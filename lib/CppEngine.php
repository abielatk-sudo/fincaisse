<?php
declare(strict_types=1);

final class CppEngine
{
    private static function binaryPath(): string
    {
        $root = dirname(__DIR__);
        $candidates = [
            $root . '/bin/fincaisse_engine',
            $root . '/build/fincaisse_engine',
        ];
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        return '';
    }

    public static function isAvailable(): bool
    {
        return self::binaryPath() !== '';
    }

    public static function status(): array
    {
        $bin = self::binaryPath();
        if ($bin === '') {
            return ['available' => false, 'engine' => 'php-fallback', 'path' => null];
        }
        try {
            $res = self::run(['op' => 'ping']);
            return [
                'available' => true,
                'engine' => $res['engine'] ?? 'fincaisse_engine-c++20',
                'path' => basename($bin),
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'engine' => 'error', 'error' => $e->getMessage()];
        }
    }

    public static function run(array $payload): array
    {
        $bin = self::binaryPath();
        if ($bin === '') {
            return self::phpFallback($payload);
        }

        $jsonIn = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open([$bin], $descriptors, $pipes);
        if (!is_resource($proc)) {
            return self::phpFallback($payload);
        }

        fwrite($pipes[0], $jsonIn);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($proc);
        $decoded = json_decode(trim((string)$stdout), true);

        if ($code !== 0 || !is_array($decoded)) {
            $err = is_array($decoded) && !empty($decoded['error'])
                ? $decoded['error']
                : ($stderr ?: 'Erreur du moteur de calcul C++');
            throw new RuntimeException($err);
        }

        if (empty($decoded['ok'])) {
            throw new RuntimeException($decoded['error'] ?? 'Calcul C++ refusé');
        }

        $decoded['_engine'] = 'c++20';
        return $decoded;
    }

    private static function phpFallback(array $req): array
    {
        $op = $req['op'] ?? '';
        if ($op === 'ping') {
            return ['ok' => true, 'engine' => 'php-fallback'];
        }
        if ($op === 'calculate_sale') {
            $items = $req['items'] ?? [];
            if (empty($items)) {
                throw new RuntimeException('Le panier est vide');
            }
            $subtotal = 0;
            $cost = 0;
            $units = 0;
            $computed = [];
            foreach ($items as $it) {
                $qty = (int)($it['quantity'] ?? 0);
                $price = (int)($it['unit_price'] ?? -1);
                $buy = max(0, (int)($it['buy_price'] ?? 0));
                if ($qty <= 0 || $price < 0) {
                    throw new RuntimeException('Article invalide dans le panier');
                }
                $lt = $qty * $price;
                $lc = $qty * $buy;
                $subtotal += $lt;
                $cost += $lc;
                $units += $qty;
                $computed[] = [
                    'product_id' => (int)($it['product_id'] ?? 0),
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'buy_price' => $buy,
                    'line_total' => $lt,
                    'line_margin' => $lt - $lc,
                ];
            }
            $discount = min($subtotal, max(0, (int)($req['discount_amount'] ?? 0)));
            $total = $subtotal - $discount;
            $isCash = !empty($req['is_cash']);
            $given = (int)($req['amount_given'] ?? $total);
            if (!$isCash && $given <= 0) {
                $given = $total;
            }
            if ($isCash && $given < $total) {
                throw new RuntimeException('Montant remis inférieur au total à payer');
            }
            $margin = $total - $cost;
            return [
                'ok' => true,
                '_engine' => 'php-fallback',
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'total_cost' => $cost,
                'gross_margin' => $margin,
                'margin_percent' => $total > 0 ? round(($margin * 100) / $total, 2) : 0,
                'total_units' => $units,
                'amount_given' => $given,
                'change_amount' => max(0, $given - $total),
                'items' => $computed,
            ];
        }
        if ($op === 'reconcile_cash') {
            $open = (int)($req['opening_amount'] ?? 0);
            $in = (int)($req['cash_sales'] ?? 0) + (int)($req['manual_in'] ?? 0);
            $out = (int)($req['cash_expenses'] ?? 0) + (int)($req['manual_out'] ?? 0) + (int)($req['cancelled_cash'] ?? 0);
            $expected = $open + $in - $out;
            $actual = !empty($req['has_actual']) ? (int)($req['actual_amount'] ?? $expected) : $expected;
            $disc = $actual - $expected;
            return [
                'ok' => true,
                '_engine' => 'php-fallback',
                'opening_amount' => $open,
                'total_in' => $in,
                'total_out' => $out,
                'expected_amount' => $expected,
                'actual_amount' => $actual,
                'discrepancy' => $disc,
                'reconciliation_status' => $disc === 0 ? 'BALANCED' : ($disc > 0 ? 'SURPLUS' : 'SHORTAGE'),
            ];
        }
        if ($op === 'compute_analytics') {
            $rev = 0; $disc = 0; $count = 0; $cancelled = 0;
            $byDay = []; $expDay = []; $byPay = []; $cntDay = [];
            foreach ($req['sales'] ?? [] as $s) {
                if (($s['status'] ?? 'COMPLETED') === 'CANCELLED') { $cancelled++; continue; }
                $amt = (int)$s['total_amount'];
                $rev += $amt;
                $disc += (int)($s['discount_amount'] ?? 0);
                $count++;
                $d = $s['day'] ?? '';
                if ($d !== '') {
                    $byDay[$d] = ($byDay[$d] ?? 0) + $amt;
                    $cntDay[$d] = ($cntDay[$d] ?? 0) + 1;
                }
                $pm = $s['payment_method'] ?? 'Espèces';
                $byPay[$pm] = ($byPay[$pm] ?? 0) + $amt;
            }
            $totExp = 0; $expCat = [];
            foreach ($req['expenses'] ?? [] as $e) {
                $amt = (int)$e['amount'];
                $totExp += $amt;
                $d = $e['day'] ?? '';
                if ($d !== '') $expDay[$d] = ($expDay[$d] ?? 0) + $amt;
                $c = $e['category'] ?? 'Autre';
                $expCat[$c] = ($expCat[$c] ?? 0) + $amt;
            }
            $cogs = 0; $units = 0; $prods = [];
            foreach ($req['sale_items'] ?? [] as $it) {
                $q = (int)$it['quantity'];
                $lt = (int)$it['line_total'];
                $c = $q * (int)($it['buy_price'] ?? 0);
                $cogs += $c; $units += $q;
                $n = $it['product_name'] ?? 'Produit';
                if (!isset($prods[$n])) $prods[$n] = ['name' => $n, 'quantity' => 0, 'revenue' => 0, 'profit' => 0];
                $prods[$n]['quantity'] += $q;
                $prods[$n]['revenue'] += $lt;
                $prods[$n]['profit'] += ($lt - $c);
            }
            usort($prods, fn($a, $b) => $b['quantity'] <=> $a['quantity'] ?: $b['revenue'] <=> $a['revenue']);
            $days = array_unique(array_merge(array_keys($byDay), array_keys($expDay)));
            sort($days);
            $series = [];
            foreach ($days as $d) {
                $r = $byDay[$d] ?? 0; $ex = $expDay[$d] ?? 0;
                $series[] = ['day' => $d, 'revenue' => $r, 'expenses' => $ex, 'net' => $r - $ex, 'count' => $cntDay[$d] ?? 0];
            }
            $gross = $rev - $cogs;
            $net = $gross - $totExp;
            return [
                'ok' => true,
                '_engine' => 'php-fallback',
                'total_revenue' => $rev,
                'total_discount' => $disc,
                'total_cogs' => $cogs,
                'gross_profit' => $gross,
                'total_expenses' => $totExp,
                'estimated_profit' => $net,
                'profit_margin_percent' => $rev > 0 ? round(($net * 100) / $rev, 2) : 0,
                'sales_count' => $count,
                'cancelled_count' => $cancelled,
                'total_units_sold' => $units,
                'avg_basket' => $count > 0 ? intdiv($rev, $count) : 0,
                'daily_series' => $series,
                'top_products' => array_slice(array_values($prods), 0, 15),
                'by_payment' => $byPay,
                'expenses_by_category' => $expCat,
            ];
        }
        throw new RuntimeException('Opération inconnue');
    }
}
