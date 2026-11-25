<?php
declare(strict_types=1);

final class Schema
{
    private static bool $checked = false;

    public static function ensureInitialized(PDO $pdo): void
    {
        if (self::$checked) {
            return;
        }
        self::$checked = true;

        $tables = [
            "CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                description TEXT NULL,
                color VARCHAR(24) NOT NULL DEFAULT '#2563EB',
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_category_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_id INT NULL,
                sku VARCHAR(64) NULL,
                name VARCHAR(160) NOT NULL,
                description TEXT NULL,
                buy_price BIGINT NOT NULL DEFAULT 0,
                sell_price BIGINT NOT NULL DEFAULT 0,
                stock INT NOT NULL DEFAULT 0,
                alert_threshold INT NOT NULL DEFAULT 5,
                active TINYINT(1) NOT NULL DEFAULT 1,
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_prod_cat (category_id),
                INDEX idx_prod_stock (stock),
                CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS customers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                phone VARCHAR(64) NULL,
                email VARCHAR(160) NULL,
                address VARCHAR(255) NULL,
                notes TEXT NULL,
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cust_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cash_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                opened_by VARCHAR(100) NOT NULL DEFAULT 'Patron',
                opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                opening_amount BIGINT NOT NULL DEFAULT 0,
                closed_by VARCHAR(100) NULL,
                closed_at DATETIME NULL,
                expected_amount BIGINT NOT NULL DEFAULT 0,
                actual_amount BIGINT NULL,
                discrepancy BIGINT NULL,
                status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
                notes TEXT NULL,
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                INDEX idx_cash_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cash_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NULL,
                type ENUM('OPENING','SALE','EXPENSE','IN','OUT','CLOSING','CANCELLATION') NOT NULL,
                amount BIGINT NOT NULL,
                reference_id INT NULL,
                description VARCHAR(255) NOT NULL,
                actor VARCHAR(100) NOT NULL DEFAULT 'Système',
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cm_sess (session_id),
                INDEX idx_cm_created (created_at),
                CONSTRAINT fk_cm_sess FOREIGN KEY (session_id) REFERENCES cash_sessions(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS payment_methods (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) NOT NULL,
                label VARCHAR(100) NOT NULL,
                is_cash TINYINT(1) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 10,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_pm_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                receipt_number VARCHAR(64) NOT NULL,
                cash_session_id INT NULL,
                customer_id INT NULL,
                customer_name VARCHAR(160) NULL,
                payment_method VARCHAR(80) NOT NULL,
                payment_code VARCHAR(50) NOT NULL DEFAULT 'CASH',
                subtotal BIGINT NOT NULL DEFAULT 0,
                discount_amount BIGINT NOT NULL DEFAULT 0,
                total_amount BIGINT NOT NULL DEFAULT 0,
                amount_given BIGINT NOT NULL DEFAULT 0,
                change_amount BIGINT NOT NULL DEFAULT 0,
                status ENUM('COMPLETED','CANCELLED') NOT NULL DEFAULT 'COMPLETED',
                cancel_reason TEXT NULL,
                cancelled_at DATETIME NULL,
                cancelled_by VARCHAR(100) NULL,
                cashier_name VARCHAR(100) NOT NULL DEFAULT 'Caissier',
                notes TEXT NULL,
                archived_at DATETIME NULL,
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_sale_receipt (receipt_number),
                INDEX idx_sale_created (created_at),
                INDEX idx_sale_status (status),
                INDEX idx_sale_cust (customer_id),
                CONSTRAINT fk_sale_sess FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id) ON DELETE SET NULL,
                CONSTRAINT fk_sale_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS sale_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sale_id INT NOT NULL,
                product_id INT NULL,
                product_name VARCHAR(160) NOT NULL,
                unit_price BIGINT NOT NULL,
                buy_price BIGINT NOT NULL DEFAULT 0,
                quantity INT NOT NULL,
                line_total BIGINT NOT NULL,
                INDEX idx_si_sale (sale_id),
                INDEX idx_si_prod (product_id),
                CONSTRAINT fk_si_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
                CONSTRAINT fk_si_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS receipts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sale_id INT NOT NULL,
                receipt_number VARCHAR(64) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                printed_count INT NOT NULL DEFAULT 0,
                archived_at DATETIME NULL,
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_rec_sale (sale_id),
                UNIQUE KEY uq_rec_num (receipt_number),
                CONSTRAINT fk_rec_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cash_session_id INT NULL,
                category VARCHAR(100) NOT NULL,
                description VARCHAR(255) NOT NULL,
                amount BIGINT NOT NULL,
                payment_method VARCHAR(80) NOT NULL DEFAULT 'Espèces',
                expense_date DATE NOT NULL,
                actor VARCHAR(100) NOT NULL DEFAULT 'Patron',
                deleted_at DATETIME NULL,
                delete_reason TEXT NULL,
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_exp_date (expense_date),
                INDEX idx_exp_deleted (deleted_at),
                CONSTRAINT fk_exp_sess FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS stock_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NULL,
                product_name VARCHAR(160) NOT NULL,
                type ENUM('IN','OUT','SALE','RETURN','ADJUSTMENT') NOT NULL,
                quantity_delta INT NOT NULL,
                stock_before INT NOT NULL,
                stock_after INT NOT NULL,
                reference VARCHAR(100) NULL,
                note VARCHAR(255) NULL,
                actor VARCHAR(100) NOT NULL DEFAULT 'Système',
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sm_prod (product_id),
                INDEX idx_sm_created (created_at),
                CONSTRAINT fk_sm_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entity_type VARCHAR(64) NOT NULL,
                entity_id INT NULL,
                action VARCHAR(80) NOT NULL,
                actor VARCHAR(100) NOT NULL DEFAULT 'Système',
                details TEXT NOT NULL,
                metadata_json LONGTEXT NULL,
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_al_type (entity_type),
                INDEX idx_al_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS settings (
                key_name VARCHAR(80) PRIMARY KEY,
                value_text TEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(64) NOT NULL,
                customer_id INT NULL,
                customer_name VARCHAR(160) NULL,
                subtotal BIGINT NOT NULL DEFAULT 0,
                discount_amount BIGINT NOT NULL DEFAULT 0,
                total_amount BIGINT NOT NULL DEFAULT 0,
                status ENUM('PENDING','PAID','CANCELLED') NOT NULL DEFAULT 'PENDING',
                created_by VARCHAR(100) NOT NULL DEFAULT 'Accueil',
                paid_by VARCHAR(100) NULL,
                sale_id INT NULL,
                cancel_reason TEXT NULL,
                notes TEXT NULL,
                is_demo TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                paid_at DATETIME NULL,
                UNIQUE KEY uq_order_num (order_number),
                INDEX idx_order_status (status),
                INDEX idx_order_created (created_at),
                CONSTRAINT fk_ord_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
                CONSTRAINT fk_ord_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                product_id INT NULL,
                product_name VARCHAR(160) NOT NULL,
                unit_price BIGINT NOT NULL,
                buy_price BIGINT NOT NULL DEFAULT 0,
                quantity INT NOT NULL,
                line_total BIGINT NOT NULL,
                INDEX idx_oi_order (order_id),
                CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_oi_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(80) NOT NULL,
                full_name VARCHAR(160) NULL,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('ADMIN','ACCUEIL','CAISSE') NOT NULL DEFAULT 'CAISSE',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS user_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_sess_token (token_hash),
                INDEX idx_sess_exp (expires_at),
                CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($tables as $sql) {
            $pdo->exec($sql);
        }

        // Seed default structural settings (idempotent)
        $defaults = [
            'store_name' => 'FINCAISSE Commerce',
            'store_tagline' => 'Gestion Commerciale & Financière',
            'store_phone' => '+225 07 00 00 00 00',
            'store_address' => 'Plateau, Avenue Chardy, Abidjan',
            'currency' => 'FCFA',
            'receipt_footer' => 'Merci pour votre confiance et à très bientôt !',
            'cashier_can_view_history' => '1',
            'retention_days' => '30',
            'retention_policy' => 'ARCHIVE',
            'last_backup_at' => '',
        ];
        $stmt = $pdo->prepare('INSERT IGNORE INTO settings (key_name, value_text) VALUES (?, ?)');
        foreach ($defaults as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        // Seed default configurable payment methods
        $pmStmt = $pdo->prepare(
            'INSERT IGNORE INTO payment_methods (code, label, is_cash, active, sort_order) VALUES (?, ?, ?, 1, ?)'
        );
        $pmStmt->execute(['CASH', 'Espèces', 1, 1]);
        $pmStmt->execute(['MOBILE_MONEY', 'Mobile Money', 0, 2]);
        $pmStmt->execute(['CARD', 'Carte Bancaire', 0, 3]);
    }

    public static function demoCounts(PDO $pdo): array
    {
        $products = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE is_demo = 1')->fetchColumn();
        $sales = (int)$pdo->query('SELECT COUNT(*) FROM sales WHERE is_demo = 1')->fetchColumn();
        $customers = (int)$pdo->query('SELECT COUNT(*) FROM customers WHERE is_demo = 1')->fetchColumn();
        $expenses = (int)$pdo->query('SELECT COUNT(*) FROM expenses WHERE is_demo = 1')->fetchColumn();
        $total = $products + $sales + $customers + $expenses;
        return [
            'active' => $total > 0,
            'products' => $products,
            'sales' => $sales,
            'customers' => $customers,
            'expenses' => $expenses,
        ];
    }

    public static function seedDemoData(PDO $pdo): array
    {
        $counts = self::demoCounts($pdo);
        if ($counts['active']) {
            return ['seeded' => false, 'message' => 'Le mode démonstration est déjà actif.'];
        }

        $pdo->beginTransaction();
        try {
            // Categories
            $cats = [
                ['Boissons & Eaux', 'Eaux minérales, jus, sodas et boissons fraîches', '#2563EB'],
                ['Alimentation & Épicerie', 'Produits alimentaires de consommation courante', '#059669'],
                ['Hygiène & Entretien', 'Savons, détergents et produits ménagers', '#0891B2'],
                ['Électronique & Accessoires', 'Câbles, chargeurs, écouteurs et accessoires', '#7C3AED'],
                ['Bureautique & Papeterie', 'Cahiers, stylos, ramettes et fournitures', '#D97706'],
            ];
            $catIds = [];
            $cIns = $pdo->prepare('INSERT INTO categories (name, description, color, is_demo) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
            foreach ($cats as $c) {
                $cIns->execute($c);
                $catIds[] = (int)$pdo->lastInsertId();
            }

            // Products (includes low stock and 1 out-of-stock for realistic alerts)
            $prods = [
                [$catIds[0], 'BOI-001', 'Eau Minérale 1.5L (Pack 6)', 'Pack de 6 bouteilles eau minérale naturelle', 1400, 2000, 42, 10],
                [$catIds[0], 'BOI-002', 'Jus de Mangue Pur 1L', 'Jus de fruit 100% naturel sans sucre ajouté', 900, 1500, 18, 6],
                [$catIds[0], 'BOI-003', 'Café Soluble Premium 200g', 'Bocal verre café arabica torréfié', 2800, 4000, 4, 5],
                [$catIds[1], 'ALI-001', 'Riz Parfumé Long Grain 5kg', 'Sac de riz parfumé qualité supérieure', 3400, 4500, 26, 8],
                [$catIds[1], 'ALI-002', 'Huile Végétale Raffinée 1L', 'Bouteille huile de cuisine pure 1 litre', 1100, 1600, 35, 10],
                [$catIds[1], 'ALI-003', 'Sucre Blanc Cristallisé 1kg', 'Paquet 1kg sucre raffiné', 650, 950, 0, 8],
                [$catIds[2], 'HYG-001', 'Savon Liquide Mains 500ml', 'Flacon pompe antibactérien parfum citron', 1200, 1900, 15, 5],
                [$catIds[2], 'HYG-002', 'Lessive Poudre Multi-Usage 2kg', 'Sachet économique lavage main & machine', 2100, 3200, 3, 5],
                [$catIds[3], 'ELE-001', 'Câble USB-C Charge Rapide 1.5m', 'Câble tressé renforcé 60W', 1500, 3000, 22, 5],
                [$catIds[3], 'ELE-002', 'Écouteurs Intra-Auriculaires Pro', 'Son stéréo haute définition avec micro', 3500, 6500, 11, 4],
                [$catIds[4], 'BUR-001', 'Ramette Papier A4 80g (500f)', 'Papier blanc multi-usage impression laser/jet', 2600, 3800, 14, 5],
                [$catIds[4], 'BUR-002', 'Lot 10 Stylos Bille Bleu', 'Pointe moyenne écriture fluide', 800, 1500, 30, 6],
            ];
            $prodIds = [];
            $pIns = $pdo->prepare(
                'INSERT INTO products (category_id, sku, name, description, buy_price, sell_price, stock, alert_threshold, active, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1)'
            );
            $smIns = $pdo->prepare(
                'INSERT INTO stock_movements (product_id, product_name, type, quantity_delta, stock_before, stock_after, reference, note, actor, is_demo)
                 VALUES (?, ?, "IN", ?, 0, ?, "INIT-DEMO", "Stock initial de démonstration", "Patron (Démo)", 1)'
            );
            foreach ($prods as $pr) {
                $pIns->execute($pr);
                $pid = (int)$pdo->lastInsertId();
                $prodIds[] = ['id' => $pid, 'name' => $pr[2], 'buy' => $pr[4], 'sell' => $pr[5]];
                if ($pr[6] > 0) {
                    $smIns->execute([$pid, $pr[2], $pr[6], $pr[6]]);
                }
            }

            // Customers
            $custs = [
                ['Kouassi Jean-Marc', '+225 07 45 12 89', 'jm.kouassi@exemple.ci', 'Cocody Angré', 'Client fidèle entreprise'],
                ['Traoré Aminata', '+225 05 88 31 04', 'aminata.t@exemple.ci', 'Marcory Zone 4', 'Achats hebdomadaires'],
                ['Bamba Seydou', '+225 01 40 22 19', 'bamba.s@exemple.ci', 'Yopougon', 'Paiement Mobile Money fréquent'],
                ['Société Horizon SARL', '+225 27 22 44 10', 'achats@horizon.ci', 'Plateau', 'Fournitures de bureau'],
            ];
            $custIds = [];
            $cuIns = $pdo->prepare(
                'INSERT INTO customers (name, phone, email, address, notes, is_demo) VALUES (?, ?, ?, ?, ?, 1)'
            );
            foreach ($custs as $cu) {
                $cuIns->execute($cu);
                $custIds[] = ['id' => (int)$pdo->lastInsertId(), 'name' => $cu[0]];
            }

            // Open Cash Session for today
            $pdo->exec(
                "INSERT INTO cash_sessions (opened_by, opened_at, opening_amount, expected_amount, status, notes, is_demo)
                 VALUES ('Patron (Démo)', DATE_FORMAT(NOW(), '%Y-%m-%d 08:00:00'), 50000, 50000, 'OPEN', 'Ouverture caisse démonstration', 1)"
            );
            $sessId = (int)$pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO cash_movements (session_id, type, amount, description, actor, is_demo, created_at)
                 VALUES (?, 'OPENING', 50000, 'Fond de caisse initial (Démo)', 'Patron (Démo)', 1, DATE_FORMAT(NOW(), '%Y-%m-%d 08:00:00'))"
            )->execute([$sessId]);

            // Sample sales across today, recent days, and >30 days ago (so 30-day retention preview also shows 2 historical demo records!)
            $salesPlan = [
                ['offset' => '-35 days', 'pm' => 'Espèces', 'code' => 'CASH', 'cust' => 0, 'items' => [[0, 2], [3, 1]]],
                ['offset' => '-32 days', 'pm' => 'Mobile Money', 'code' => 'MOBILE_MONEY', 'cust' => 3, 'items' => [[10, 3], [11, 2]]],
                ['offset' => '-14 days', 'pm' => 'Carte Bancaire', 'code' => 'CARD', 'cust' => 1, 'items' => [[9, 1], [8, 2]]],
                ['offset' => '-9 days', 'pm' => 'Espèces', 'code' => 'CASH', 'cust' => 2, 'items' => [[3, 2], [4, 2]]],
                ['offset' => '-5 days', 'pm' => 'Mobile Money', 'code' => 'MOBILE_MONEY', 'cust' => 1, 'items' => [[1, 3], [6, 2]]],
                ['offset' => '-3 days', 'pm' => 'Espèces', 'code' => 'CASH', 'cust' => 0, 'items' => [[0, 3], [4, 1], [11, 1]]],
                ['offset' => '-1 day', 'pm' => 'Mobile Money', 'code' => 'MOBILE_MONEY', 'cust' => 3, 'items' => [[10, 2], [8, 1]]],
                ['offset' => '0 days', 'pm' => 'Espèces', 'code' => 'CASH', 'cust' => 0, 'items' => [[0, 2], [1, 2]]],
                ['offset' => '0 days', 'pm' => 'Mobile Money', 'code' => 'MOBILE_MONEY', 'cust' => 2, 'items' => [[9, 1], [6, 1]]],
                ['offset' => '0 days', 'pm' => 'Espèces', 'code' => 'CASH', 'cust' => null, 'items' => [[3, 1], [4, 2]]],
            ];

            $sIns = $pdo->prepare(
                'INSERT INTO sales (receipt_number, cash_session_id, customer_id, customer_name, payment_method, payment_code, subtotal, discount_amount, total_amount, amount_given, change_amount, status, cashier_name, is_demo, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, "COMPLETED", "Caissier (Démo)", 1, ?)'
            );
            $siIns = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, product_id, product_name, unit_price, buy_price, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $recIns = $pdo->prepare(
                'INSERT INTO receipts (sale_id, receipt_number, payload_json, printed_count, is_demo, created_at)
                 VALUES (?, ?, ?, 1, 1, ?)'
            );
            $cmSale = $pdo->prepare(
                'INSERT INTO cash_movements (session_id, type, amount, reference_id, description, actor, is_demo, created_at)
                 VALUES (?, "SALE", ?, ?, ?, "Caissier (Démo)", 1, ?)'
            );

            $seq = 1;
            $todayCashSales = 0;
            foreach ($salesPlan as $sp) {
                $dt = date('Y-m-d H:i:s', strtotime($sp['offset'] . ' +' . (9 + ($seq % 8)) . ' hours +' . ($seq * 7 % 50) . ' minutes'));
                $recNo = 'FC-DEMO-' . date('Ymd', strtotime($dt)) . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
                $seq++;

                $total = 0;
                $lines = [];
                foreach ($sp['items'] as [$pIdx, $qty]) {
                    $pr = $prodIds[$pIdx];
                    $lt = $pr['sell'] * $qty;
                    $total += $lt;
                    $lines[] = [
                        'product_id' => $pr['id'],
                        'product_name' => $pr['name'],
                        'unit_price' => $pr['sell'],
                        'buy_price' => $pr['buy'],
                        'quantity' => $qty,
                        'line_total' => $lt,
                    ];
                }
                $custId = $sp['cust'] !== null ? $custIds[$sp['cust']]['id'] : null;
                $custName = $sp['cust'] !== null ? $custIds[$sp['cust']]['name'] : 'Client comptoir';
                $isToday = $sp['offset'] === '0 days';
                $given = $sp['code'] === 'CASH' ? (int)(ceil($total / 1000) * 1000) : $total;
                $change = $given - $total;

                $sIns->execute([
                    $recNo,
                    $isToday ? $sessId : null,
                    $custId,
                    $custName,
                    $sp['pm'],
                    $sp['code'],
                    $total,
                    $total,
                    $given,
                    $change,
                    $dt,
                ]);
                $saleId = (int)$pdo->lastInsertId();

                foreach ($lines as $ln) {
                    $siIns->execute([$saleId, $ln['product_id'], $ln['product_name'], $ln['unit_price'], $ln['buy_price'], $ln['quantity'], $ln['line_total']]);
                }

                $payload = json_encode([
                    'receipt_number' => $recNo,
                    'store_name' => 'FINCAISSE Commerce',
                    'customer_name' => $custName,
                    'payment_method' => $sp['pm'],
                    'subtotal' => $total,
                    'discount_amount' => 0,
                    'total_amount' => $total,
                    'amount_given' => $given,
                    'change_amount' => $change,
                    'items' => $lines,
                    'created_at' => $dt,
                    'is_demo' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $recIns->execute([$saleId, $recNo, $payload, $dt]);

                if ($isToday && $sp['code'] === 'CASH') {
                    $todayCashSales += $total;
                    $cmSale->execute([$sessId, $total, $saleId, 'Vente ' . $recNo . ' (Démo)', $dt]);
                }
            }

            // Sample expenses
            $exps = [
                ['Fournitures & Emballages', 'Sacs kraft et rouleaux papier ticket thermique', 4500, 'Espèces', date('Y-m-d'), true],
                ['Transport & Livraison', 'Livraison fournisseur boissons', 6000, 'Espèces', date('Y-m-d', strtotime('-2 days')), false],
                ['Électricité & Internet', 'Recharge fibre boutique', 15000, 'Mobile Money', date('Y-m-d', strtotime('-6 days')), false],
                ['Entretien local', 'Produits nettoyage magasin', 3500, 'Espèces', date('Y-m-d', strtotime('-12 days')), false],
            ];
            $eIns = $pdo->prepare(
                'INSERT INTO expenses (cash_session_id, category, description, amount, payment_method, expense_date, actor, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, "Patron (Démo)", 1)'
            );
            $todayCashExp = 0;
            foreach ($exps as $ex) {
                $eIns->execute([$ex[5] ? $sessId : null, $ex[0], $ex[1], $ex[2], $ex[3], $ex[4]]);
                $eid = (int)$pdo->lastInsertId();
                if ($ex[5] && $ex[3] === 'Espèces') {
                    $todayCashExp += $ex[2];
                    $pdo->prepare(
                        'INSERT INTO cash_movements (session_id, type, amount, reference_id, description, actor, is_demo)
                         VALUES (?, "EXPENSE", ?, ?, ?, "Patron (Démo)", 1)'
                    )->execute([$sessId, $ex[2], $eid, 'Dépense: ' . $ex[1] . ' (Démo)']);
                }
            }

            $expected = 50000 + $todayCashSales - $todayCashExp;
            $pdo->prepare('UPDATE cash_sessions SET expected_amount = ? WHERE id = ?')->execute([$expected, $sessId]);

            // Sample PENDING orders sent from Accueil -> waiting at Caissier
            $ordIns = $pdo->prepare(
                'INSERT INTO orders (order_number, customer_id, customer_name, subtotal, discount_amount, total_amount, status, created_by, notes, is_demo)
                 VALUES (?, ?, ?, ?, 0, ?, "PENDING", "Accueil (Démo)", ?, 1)'
            );
            $oiIns = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, unit_price, buy_price, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $demoOrders = [
                ['CMD-DEMO-001', $custIds[0]['id'], $custIds[0]['name'], [[0, 1], [1, 2]], 'Client à la caisse 1'],
                ['CMD-DEMO-002', $custIds[1]['id'], $custIds[1]['name'], [[3, 1], [6, 1]], 'Commande prête à encaisser'],
            ];
            foreach ($demoOrders as $do) {
                $tot = 0;
                $oLines = [];
                foreach ($do[3] as [$pIdx, $qty]) {
                    $pr = $prodIds[$pIdx];
                    $lt = $pr['sell'] * $qty;
                    $tot += $lt;
                    $oLines[] = [$pr['id'], $pr['name'], $pr['sell'], $pr['buy'], $qty, $lt];
                }
                $ordIns->execute([$do[0], $do[1], $do[2], $tot, $tot, $do[4]]);
                $oid = (int)$pdo->lastInsertId();
                foreach ($oLines as $ol) {
                    $oiIns->execute([$oid, $ol[0], $ol[1], $ol[2], $ol[3], $ol[4], $ol[5]]);
                }
            }

            $pdo->prepare(
                'INSERT INTO audit_logs (entity_type, entity_id, action, actor, details, is_demo)
                 VALUES ("SYSTEM", NULL, "DEMO_ACTIVATED", "Patron", "Activation du mode démonstration avec données d’exemple identifiées.", 1)'
            )->execute();

            $pdo->commit();
            return ['seeded' => true, 'message' => 'Données de démonstration chargées avec succès.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function clearDemoData(PDO $pdo): array
    {
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM orders WHERE is_demo = 1');
            $pdo->exec('DELETE FROM receipts WHERE is_demo = 1');
            $pdo->exec('DELETE FROM sales WHERE is_demo = 1');
            $pdo->exec('DELETE FROM expenses WHERE is_demo = 1');
            $pdo->exec('DELETE FROM cash_movements WHERE is_demo = 1');
            $pdo->exec('DELETE FROM cash_sessions WHERE is_demo = 1');
            $pdo->exec('DELETE FROM stock_movements WHERE is_demo = 1');
            $pdo->exec('DELETE FROM products WHERE is_demo = 1');
            $pdo->exec('DELETE FROM categories WHERE is_demo = 1');
            $pdo->exec('DELETE FROM customers WHERE is_demo = 1');
            $pdo->exec('DELETE FROM audit_logs WHERE is_demo = 1');

            $pdo->prepare(
                'INSERT INTO audit_logs (entity_type, entity_id, action, actor, details, is_demo)
                 VALUES ("SYSTEM", NULL, "DEMO_CLEARED", "Patron", "Suppression complète des données de démonstration.", 0)'
            )->execute();

            $pdo->commit();
            return ['cleared' => true, 'message' => 'Toutes les données de démonstration ont été supprimées.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
