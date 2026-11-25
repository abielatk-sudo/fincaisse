<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/CppEngine.php';

function recalcOpenSession(PDO $pdo, int $sessionId): array
{
    $stmt = $pdo->prepare('SELECT * FROM cash_sessions WHERE id = ?');
    $stmt->execute([$sessionId]);
    $sess = $stmt->fetch();
    if (!$sess) {
        return [];
    }

    $mvStmt = $pdo->prepare(
        'SELECT type, COALESCE(SUM(amount), 0) AS total FROM cash_movements WHERE session_id = ? GROUP BY type'
    );
    $mvStmt->execute([$sessionId]);
    $byType = [];
    foreach ($mvStmt->fetchAll() as $row) {
        $byType[$row['type']] = (int)$row['total'];
    }

    $rec = CppEngine::run([
        'op' => 'reconcile_cash',
        'opening_amount' => (int)$sess['opening_amount'],
        'cash_sales' => $byType['SALE'] ?? 0,
        'manual_in' => $byType['IN'] ?? 0,
        'cash_expenses' => $byType['EXPENSE'] ?? 0,
        'manual_out' => $byType['OUT'] ?? 0,
        'cancelled_cash' => $byType['CANCELLATION'] ?? 0,
        'has_actual' => $sess['actual_amount'] !== null ? 1 : 0,
        'actual_amount' => (int)($sess['actual_amount'] ?? 0),
    ]);

    $pdo->prepare('UPDATE cash_sessions SET expected_amount = ? WHERE id = ?')
        ->execute([(int)$rec['expected_amount'], $sessionId]);

    return array_merge($rec, [
        'cash_sales' => $byType['SALE'] ?? 0,
        'manual_in' => $byType['IN'] ?? 0,
        'cash_expenses' => $byType['EXPENSE'] ?? 0,
        'manual_out' => $byType['OUT'] ?? 0,
        'cancelled_cash' => $byType['CANCELLATION'] ?? 0,
    ]);
}

function getOpenSession(PDO $pdo): ?array
{
    $sess = $pdo->query("SELECT * FROM cash_sessions WHERE status = 'OPEN' ORDER BY id DESC LIMIT 1")->fetch();
    return $sess ?: null;
}

function enforceRouteAccess(PDO $pdo, string $path, string $method): array
{
    $all = ['ADMIN', 'ACCUEIL', 'CAISSE'];

    if ($path === '/api/health' && $method === 'GET') {
        return Helpers::requireRole($pdo, $all);
    }
    if (in_array($path, ['/api/products', '/api/categories', '/api/customers', '/api/orders', '/api/orders/detail'], true) && $method === 'GET') {
        return Helpers::requireRole($pdo, $all);
    }
    if ($path === '/api/orders' && $method === 'POST') {
        return Helpers::requireRole($pdo, ['ADMIN', 'ACCUEIL']);
    }
    if ($path === '/api/orders/cancel' && $method === 'POST') {
        return Helpers::requireRole($pdo, $all);
    }
    if ($path === '/api/orders/pay' && $method === 'POST') {
        return Helpers::requireRole($pdo, ['ADMIN', 'CAISSE']);
    }
    if (in_array($path, ['/api/sales', '/api/sales/detail', '/api/receipts'], true) && $method === 'GET') {
        $u = Helpers::requireRole($pdo, ['ADMIN', 'CAISSE']);
        if ($path === '/api/sales' && $u['role'] === 'CAISSE') {
            $settings = Helpers::getSettings($pdo);
            if (($settings['cashier_can_view_history'] ?? '1') === '0') {
                Helpers::error('Consultation de l’historique désactivée par le patron.', 403);
            }
        }
        return $u;
    }
    if (($path === '/api/sales' || $path === '/api/receipts/print') && $method === 'POST') {
        return Helpers::requireRole($pdo, ['ADMIN', 'CAISSE']);
    }

    return Helpers::requireRole($pdo, ['ADMIN']);
}

function handleApiRequest(string $path, string $method): never
{
    try {
        $pdo = Database::connection();

        // 0. AUTHENTICATION & FIRST-ADMIN SETUP (Public routes)
        if ($path === '/api/auth/status' && $method === 'GET') {
            $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $user = Helpers::currentUser($pdo);
            Helpers::json([
                'ok' => true,
                'needs_setup' => $userCount === 0,
                'authenticated' => $user !== null,
                'user' => $user,
            ]);
        }

        if ($path === '/api/auth/setup' && $method === 'POST') {
            $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($userCount > 0) {
                Helpers::error('Un administrateur existe déjà. Veuillez vous connecter.', 403);
            }
            $b = Helpers::body();
            $username = Helpers::str($b['username'] ?? '', 80);
            $fullName = Helpers::str($b['full_name'] ?? '', 160) ?: $username;
            $password = (string)($b['password'] ?? '');
            if (mb_strlen($username, 'UTF-8') < 3) {
                Helpers::error('Le nom d’utilisateur doit contenir au moins 3 caractères.');
            }
            if (strlen($password) < 8) {
                Helpers::error('Le mot de passe doit contenir au moins 8 caractères.');
            }

            $hash = Helpers::hashPassword($password);
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, full_name, password_hash, role, active) VALUES (?, ?, ?, "ADMIN", 1)'
            );
            $stmt->execute([$username, $fullName, $hash]);
            $uid = (int)$pdo->lastInsertId();
            $token = Helpers::createSession($pdo, $uid);
            Helpers::audit($pdo, 'USER', $uid, 'ADMIN_SETUP', $fullName, "Création du premier compte administrateur ({$username})");
            Helpers::json([
                'ok' => true,
                'token' => $token,
                'user' => ['id' => $uid, 'username' => $username, 'full_name' => $fullName, 'role' => 'ADMIN'],
            ]);
        }

        if ($path === '/api/auth/login' && $method === 'POST') {
            $b = Helpers::body();
            $username = Helpers::str($b['username'] ?? '', 80);
            $password = (string)($b['password'] ?? '');
            if ($username === '' || $password === '') {
                Helpers::error('Identifiant et mot de passe requis.', 400);
            }

            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $u = $stmt->fetch();
            if (!$u || !password_verify($password, $u['password_hash'])) {
                Helpers::error('Nom d’utilisateur ou mot de passe incorrect.', 401);
            }
            if ((int)$u['active'] !== 1) {
                Helpers::error('Ce compte a été désactivé par l’administrateur.', 403);
            }

            $token = Helpers::createSession($pdo, (int)$u['id']);
            $actor = Helpers::actorName($u);
            Helpers::audit($pdo, 'USER', (int)$u['id'], 'USER_LOGIN', $actor, "Connexion ({$u['role']})");
            Helpers::json([
                'ok' => true,
                'token' => $token,
                'user' => [
                    'id' => (int)$u['id'],
                    'username' => $u['username'],
                    'full_name' => $u['full_name'],
                    'role' => $u['role'],
                ],
            ]);
        }

        if ($path === '/api/auth/logout' && $method === 'POST') {
            $u = Helpers::currentUser($pdo);
            if ($u) {
                Helpers::audit($pdo, 'USER', (int)$u['id'], 'USER_LOGOUT', Helpers::actorName($u), 'Déconnexion');
            }
            Helpers::clearSession($pdo);
            Helpers::json(['ok' => true]);
        }

        // Enforce authentication & role permissions on all protected endpoints
        $authUser = enforceRouteAccess($pdo, $path, $method);

        // 0B. USERS MANAGEMENT (ADMIN ONLY)
        if ($path === '/api/users') {
            if ($method === 'GET') {
                $rows = $pdo->query('SELECT id, username, full_name, role, active, created_at FROM users ORDER BY id ASC')->fetchAll();
                Helpers::json(['ok' => true, 'users' => $rows]);
            }
            $b = Helpers::body();
            if ($method === 'POST') {
                $username = Helpers::str($b['username'] ?? '', 80);
                $fullName = Helpers::str($b['full_name'] ?? '', 160) ?: $username;
                $role = Helpers::str($b['role'] ?? 'CAISSE', 20);
                $password = (string)($b['password'] ?? '');
                if (mb_strlen($username, 'UTF-8') < 3) Helpers::error('Nom d’utilisateur : 3 caractères minimum.');
                if (strlen($password) < 8) Helpers::error('Mot de passe : 8 caractères minimum.');
                if (!in_array($role, ['ADMIN', 'ACCUEIL', 'CAISSE'], true)) Helpers::error('Rôle invalide.');

                $dup = $pdo->prepare('SELECT id FROM users WHERE username = ?');
                $dup->execute([$username]);
                if ($dup->fetch()) Helpers::error('Ce nom d’utilisateur est déjà utilisé.');

                $pdo->prepare('INSERT INTO users (username, full_name, password_hash, role, active) VALUES (?, ?, ?, ?, 1)')
                    ->execute([$username, $fullName, Helpers::hashPassword($password), $role]);
                $nid = (int)$pdo->lastInsertId();
                Helpers::audit($pdo, 'USER', $nid, 'USER_CREATED', Helpers::actorName($authUser), "Compte créé : {$username} ({$role})");
                Helpers::json(['ok' => true, 'id' => $nid]);
            }
            if ($method === 'PUT') {
                $id = Helpers::intVal($b['id'] ?? 0);
                $fullName = Helpers::str($b['full_name'] ?? '', 160);
                $role = Helpers::str($b['role'] ?? '', 20);
                $password = (string)($b['password'] ?? '');
                if ($id <= 0 || !in_array($role, ['ADMIN', 'ACCUEIL', 'CAISSE'], true)) {
                    Helpers::error('Paramètres utilisateur invalides.');
                }
                if ($id === (int)$authUser['id'] && $role !== 'ADMIN') {
                    Helpers::error('Vous ne pouvez pas retirer votre propre rôle administrateur.');
                }
                $pdo->prepare('UPDATE users SET full_name = ?, role = ? WHERE id = ?')
                    ->execute([$fullName ?: null, $role, $id]);
                if ($password !== '') {
                    if (strlen($password) < 8) Helpers::error('Mot de passe : 8 caractères minimum.');
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([Helpers::hashPassword($password), $id]);
                }
                Helpers::audit($pdo, 'USER', $id, 'USER_UPDATED', Helpers::actorName($authUser), "Compte #{$id} modifié ({$role})");
                Helpers::json(['ok' => true]);
            }
        }

        if ($path === '/api/users/toggle' && $method === 'POST') {
            $b = Helpers::body();
            $id = Helpers::intVal($b['id'] ?? 0);
            if ($id <= 0) Helpers::error('ID utilisateur requis.');
            if ($id === (int)$authUser['id']) {
                Helpers::error('Vous ne pouvez pas désactiver votre propre compte.');
            }
            $pdo->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$id]);
            Helpers::audit($pdo, 'USER', $id, 'USER_TOGGLED', Helpers::actorName($authUser), "Statut du compte #{$id} modifié");
            Helpers::json(['ok' => true]);
        }

        // 1. HEALTH & BOOTSTRAP
        if ($path === '/api/health' && $method === 'GET') {
            $settings = Helpers::getSettings($pdo);
            $methods = $pdo->query('SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC')->fetchAll();
            $openSess = getOpenSession($pdo);
            $cashSummary = $openSess ? recalcOpenSession($pdo, (int)$openSess['id']) : null;
            $pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'PENDING'")->fetchColumn();

            Helpers::json([
                'ok' => true,
                'app' => 'FINCAISSE',
                'user' => $authUser,
                'cpp_engine' => CppEngine::status(),
                'settings' => $settings,
                'payment_methods' => $methods,
                'open_session' => $openSess ? array_merge($openSess, ['summary' => $cashSummary]) : null,
                'pending_orders_count' => $pendingOrders,
                'demo' => Schema::demoCounts($pdo),
            ]);
        }

        // 2. DEMO MODE
        if ($path === '/api/demo/seed' && $method === 'POST') {
            $res = Schema::seedDemoData($pdo);
            Helpers::json(array_merge(['ok' => true, 'demo' => Schema::demoCounts($pdo)], $res));
        }
        if ($path === '/api/demo/clear' && $method === 'POST') {
            $res = Schema::clearDemoData($pdo);
            Helpers::json(array_merge(['ok' => true, 'demo' => Schema::demoCounts($pdo)], $res));
        }

        // 3. DASHBOARD
        if ($path === '/api/dashboard' && $method === 'GET') {
            $openSess = getOpenSession($pdo);
            $cashBalance = 0;
            $cashStatus = 'CLOSED';
            $cashSummary = null;
            if ($openSess) {
                $cashSummary = recalcOpenSession($pdo, (int)$openSess['id']);
                $cashBalance = (int)$cashSummary['expected_amount'];
                $cashStatus = 'OPEN';
            } else {
                $lastClosed = $pdo->query("SELECT actual_amount, expected_amount FROM cash_sessions WHERE status = 'CLOSED' ORDER BY id DESC LIMIT 1")->fetch();
                if ($lastClosed) {
                    $cashBalance = (int)($lastClosed['actual_amount'] ?? $lastClosed['expected_amount']);
                }
            }

            $todaySales = $pdo->query(
                "SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
                 FROM sales WHERE status = 'COMPLETED' AND DATE(created_at) = CURDATE()"
            )->fetch();

            $weekSales = $pdo->query(
                "SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
                 FROM sales WHERE status = 'COMPLETED' AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)"
            )->fetch();

            $monthSales = $pdo->query(
                "SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
                 FROM sales WHERE status = 'COMPLETED' AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
            )->fetch();

            $todayExpenses = $pdo->query(
                "SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
                 FROM expenses WHERE deleted_at IS NULL AND expense_date = CURDATE()"
            )->fetch();

            $weekExpenses = (int)$pdo->query(
                "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE deleted_at IS NULL AND YEARWEEK(expense_date, 1) = YEARWEEK(CURDATE(), 1)"
            )->fetchColumn();

            $monthExpenses = (int)$pdo->query(
                "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE deleted_at IS NULL AND DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
            )->fetchColumn();

            $prodStats = $pdo->query(
                "SELECT
                    COUNT(*) AS total_products,
                    COALESCE(SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END), 0) AS available_products,
                    COALESCE(SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_count,
                    COALESCE(SUM(CASE WHEN stock > 0 AND stock <= alert_threshold THEN 1 ELSE 0 END), 0) AS low_stock_count
                 FROM products WHERE active = 1"
            )->fetch();

            $outOfStock = $pdo->query(
                "SELECT p.id, p.name, p.sku, p.stock, p.alert_threshold, p.is_demo, c.name AS category_name
                 FROM products p LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.active = 1 AND p.stock <= p.alert_threshold
                 ORDER BY p.stock ASC, p.name ASC LIMIT 12"
            )->fetchAll();

            $topProducts = $pdo->query(
                "SELECT si.product_name AS name, SUM(si.quantity) AS qty, SUM(si.line_total) AS revenue
                 FROM sale_items si
                 JOIN sales s ON s.id = si.sale_id
                 WHERE s.status = 'COMPLETED'
                 GROUP BY si.product_name
                 ORDER BY qty DESC, revenue DESC
                 LIMIT 6"
            )->fetchAll();

            $recentSales = $pdo->query(
                "SELECT id, receipt_number, customer_name, payment_method, total_amount, status, is_demo, created_at
                 FROM sales
                 ORDER BY id DESC
                 LIMIT 8"
            )->fetchAll();

            // Chart series: daily (last 14 days)
            $dailySalesRows = $pdo->query(
                "SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS rev, COUNT(*) AS cnt
                 FROM sales WHERE status = 'COMPLETED' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                 GROUP BY DATE(created_at) ORDER BY d ASC"
            )->fetchAll();
            $dailyExpRows = $pdo->query(
                "SELECT expense_date AS d, COALESCE(SUM(amount),0) AS exp
                 FROM expenses WHERE deleted_at IS NULL AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                 GROUP BY expense_date ORDER BY d ASC"
            )->fetchAll();

            $dsMap = [];
            foreach ($dailySalesRows as $r) $dsMap[$r['d']] = ['rev' => (int)$r['rev'], 'cnt' => (int)$r['cnt']];
            $deMap = [];
            foreach ($dailyExpRows as $r) $deMap[$r['d']] = (int)$r['exp'];

            $dailyChart = [];
            for ($i = 13; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $dailyChart[] = [
                    'label' => date('d/m', strtotime($d)),
                    'date' => $d,
                    'revenue' => $dsMap[$d]['rev'] ?? 0,
                    'expenses' => $deMap[$d] ?? 0,
                    'count' => $dsMap[$d]['cnt'] ?? 0,
                ];
            }

            // Weekly (last 8 weeks)
            $weeklyChart = $pdo->query(
                "SELECT YEARWEEK(created_at, 1) AS yw,
                        DATE_FORMAT(MIN(created_at), '%d/%m') AS label,
                        COALESCE(SUM(total_amount),0) AS revenue,
                        COUNT(*) AS count
                 FROM sales
                 WHERE status = 'COMPLETED' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
                 GROUP BY YEARWEEK(created_at, 1)
                 ORDER BY yw ASC"
            )->fetchAll();

            // Monthly (last 6 months)
            $monthlyChart = $pdo->query(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
                        DATE_FORMAT(created_at, '%m/%Y') AS label,
                        COALESCE(SUM(total_amount),0) AS revenue,
                        COUNT(*) AS count
                 FROM sales
                 WHERE status = 'COMPLETED' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                 GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                 ORDER BY ym ASC"
            )->fetchAll();

            Helpers::json([
                'ok' => true,
                'cash' => [
                    'balance' => $cashBalance,
                    'status' => $cashStatus,
                    'session' => $openSess,
                    'summary' => $cashSummary,
                ],
                'today' => [
                    'sales_total' => (int)$todaySales['total'],
                    'sales_count' => (int)$todaySales['cnt'],
                    'expenses_total' => (int)$todayExpenses['total'],
                    'expenses_count' => (int)$todayExpenses['cnt'],
                ],
                'periods' => [
                    'day' => ['sales' => (int)$todaySales['total'], 'count' => (int)$todaySales['cnt'], 'expenses' => (int)$todayExpenses['total']],
                    'week' => ['sales' => (int)$weekSales['total'], 'count' => (int)$weekSales['cnt'], 'expenses' => $weekExpenses],
                    'month' => ['sales' => (int)$monthSales['total'], 'count' => (int)$monthSales['cnt'], 'expenses' => $monthExpenses],
                ],
                'products' => $prodStats,
                'stock_alerts' => $outOfStock,
                'top_products' => $topProducts,
                'recent_transactions' => $recentSales,
                'charts' => [
                    'daily' => $dailyChart,
                    'weekly' => $weeklyChart,
                    'monthly' => $monthlyChart,
                ],
            ]);
        }

        // 4. CATEGORIES
        if ($path === '/api/categories') {
            if ($method === 'GET') {
                $rows = $pdo->query(
                    "SELECT c.*, COUNT(p.id) AS product_count
                     FROM categories c
                     LEFT JOIN products p ON p.category_id = c.id AND p.active = 1
                     GROUP BY c.id
                     ORDER BY c.name ASC"
                )->fetchAll();
                Helpers::json(['ok' => true, 'categories' => $rows]);
            }
            $b = Helpers::body();
            if ($method === 'POST') {
                $name = Helpers::str($b['name'] ?? '', 120);
                if ($name === '') Helpers::error('Le nom de la catégorie est requis.');
                $desc = Helpers::str($b['description'] ?? '', 500);
                $color = Helpers::str($b['color'] ?? '#2563EB', 24) ?: '#2563EB';
                $stmt = $pdo->prepare('INSERT INTO categories (name, description, color, is_demo) VALUES (?, ?, ?, 0)');
                $stmt->execute([$name, $desc, $color]);
                $id = (int)$pdo->lastInsertId();
                Helpers::audit($pdo, 'CATEGORY', $id, 'CATEGORY_CREATED', 'Patron', "Catégorie créée : {$name}");
                Helpers::json(['ok' => true, 'id' => $id]);
            }
            if ($method === 'PUT') {
                $id = Helpers::intVal($b['id'] ?? 0);
                $name = Helpers::str($b['name'] ?? '', 120);
                if ($id <= 0 || $name === '') Helpers::error('ID et nom de catégorie requis.');
                $desc = Helpers::str($b['description'] ?? '', 500);
                $color = Helpers::str($b['color'] ?? '#2563EB', 24) ?: '#2563EB';
                $pdo->prepare('UPDATE categories SET name = ?, description = ?, color = ? WHERE id = ?')
                    ->execute([$name, $desc, $color, $id]);
                Helpers::audit($pdo, 'CATEGORY', $id, 'CATEGORY_UPDATED', 'Patron', "Catégorie modifiée : {$name}");
                Helpers::json(['ok' => true]);
            }
            if ($method === 'DELETE') {
                $id = Helpers::intVal($_GET['id'] ?? $b['id'] ?? 0);
                if ($id <= 0) Helpers::error('ID catégorie requis.');
                $name = (string)$pdo->query("SELECT name FROM categories WHERE id = {$id}")->fetchColumn();
                $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
                Helpers::audit($pdo, 'CATEGORY', $id, 'CATEGORY_DELETED', 'Patron', "Catégorie supprimée : {$name}");
                Helpers::json(['ok' => true]);
            }
        }

        // 5. PRODUCTS & STOCK
        if ($path === '/api/products') {
            if ($method === 'GET') {
                $q = Helpers::str($_GET['q'] ?? '', 120);
                $catId = Helpers::intVal($_GET['category_id'] ?? 0);
                $stockFilter = Helpers::str($_GET['stock'] ?? '', 20);

                $sql = "SELECT p.*, c.name AS category_name, c.color AS category_color
                        FROM products p
                        LEFT JOIN categories c ON c.id = p.category_id
                        WHERE p.active = 1";
                $params = [];
                if ($q !== '') {
                    $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.description LIKE ?)";
                    $like = "%{$q}%";
                    $params[] = $like;
                    $params[] = $like;
                    $params[] = $like;
                }
                if ($catId > 0) {
                    $sql .= " AND p.category_id = ?";
                    $params[] = $catId;
                }
                if ($stockFilter === 'out') {
                    $sql .= " AND p.stock <= 0";
                } elseif ($stockFilter === 'low') {
                    $sql .= " AND p.stock > 0 AND p.stock <= p.alert_threshold";
                } elseif ($stockFilter === 'available') {
                    $sql .= " AND p.stock > 0";
                }
                $sql .= " ORDER BY p.name ASC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                Helpers::json(['ok' => true, 'products' => $stmt->fetchAll()]);
            }

            $b = Helpers::body();
            if ($method === 'POST') {
                $name = Helpers::str($b['name'] ?? '', 160);
                $sellPrice = Helpers::intVal($b['sell_price'] ?? -1, -1);
                $buyPrice = max(0, Helpers::intVal($b['buy_price'] ?? 0));
                $stock = max(0, Helpers::intVal($b['stock'] ?? 0));
                $threshold = max(0, Helpers::intVal($b['alert_threshold'] ?? 5));
                $catId = !empty($b['category_id']) ? Helpers::intVal($b['category_id']) : null;
                $sku = Helpers::str($b['sku'] ?? '', 64);
                $desc = Helpers::str($b['description'] ?? '', 1000);

                if ($name === '' || $sellPrice < 0) {
                    Helpers::error('Le nom et un prix de vente valide sont obligatoires.');
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'INSERT INTO products (category_id, sku, name, description, buy_price, sell_price, stock, alert_threshold, active, is_demo)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0)'
                );
                $stmt->execute([$catId, $sku ?: null, $name, $desc ?: null, $buyPrice, $sellPrice, $stock, $threshold]);
                $pid = (int)$pdo->lastInsertId();

                if ($stock > 0) {
                    $pdo->prepare(
                        'INSERT INTO stock_movements (product_id, product_name, type, quantity_delta, stock_before, stock_after, reference, note, actor, is_demo)
                         VALUES (?, ?, "IN", ?, 0, ?, "INIT", "Stock initial lors de la création", "Patron", 0)'
                    )->execute([$pid, $name, $stock, $stock]);
                }
                Helpers::audit($pdo, 'PRODUCT', $pid, 'PRODUCT_CREATED', 'Patron', "Produit ajouté : {$name} ({$sellPrice} FCFA, stock {$stock})");
                $pdo->commit();
                Helpers::json(['ok' => true, 'id' => $pid]);
            }

            if ($method === 'PUT') {
                $id = Helpers::intVal($b['id'] ?? 0);
                $name = Helpers::str($b['name'] ?? '', 160);
                $sellPrice = Helpers::intVal($b['sell_price'] ?? -1, -1);
                $buyPrice = max(0, Helpers::intVal($b['buy_price'] ?? 0));
                $newStock = max(0, Helpers::intVal($b['stock'] ?? 0));
                $threshold = max(0, Helpers::intVal($b['alert_threshold'] ?? 5));
                $catId = !empty($b['category_id']) ? Helpers::intVal($b['category_id']) : null;
                $sku = Helpers::str($b['sku'] ?? '', 64);
                $desc = Helpers::str($b['description'] ?? '', 1000);

                if ($id <= 0 || $name === '' || $sellPrice < 0) {
                    Helpers::error('Données produit invalides.');
                }

                $pdo->beginTransaction();
                $cur = $pdo->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                $cur->execute([$id]);
                $old = $cur->fetch();
                if (!$old) {
                    $pdo->rollBack();
                    Helpers::error('Produit introuvable.', 404);
                }

                $pdo->prepare(
                    'UPDATE products SET category_id = ?, sku = ?, name = ?, description = ?, buy_price = ?, sell_price = ?, stock = ?, alert_threshold = ? WHERE id = ?'
                )->execute([$catId, $sku ?: null, $name, $desc ?: null, $buyPrice, $sellPrice, $newStock, $threshold, $id]);

                $oldStock = (int)$old['stock'];
                if ($newStock !== $oldStock) {
                    $delta = $newStock - $oldStock;
                    $pdo->prepare(
                        'INSERT INTO stock_movements (product_id, product_name, type, quantity_delta, stock_before, stock_after, reference, note, actor, is_demo)
                         VALUES (?, ?, "ADJUSTMENT", ?, ?, ?, "EDIT", "Mise à jour manuelle fiche produit", "Patron", 0)'
                    )->execute([$id, $name, $delta, $oldStock, $newStock]);
                }

                Helpers::audit($pdo, 'PRODUCT', $id, 'PRODUCT_UPDATED', 'Patron', "Produit modifié : {$name} ({$sellPrice} FCFA, stock {$newStock})");
                $pdo->commit();
                Helpers::json(['ok' => true]);
            }

            if ($method === 'DELETE') {
                $id = Helpers::intVal($_GET['id'] ?? $b['id'] ?? 0);
                if ($id <= 0) Helpers::error('ID produit requis.');
                $stmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                $stmt->execute([$id]);
                $name = (string)$stmt->fetchColumn();
                if ($name === '') Helpers::error('Produit introuvable.', 404);

                // Soft-deactivate if referenced by sale_items to preserve sale history, else delete cleanly
                $used = (int)$pdo->query("SELECT COUNT(*) FROM sale_items WHERE product_id = {$id}")->fetchColumn();
                if ($used > 0) {
                    $pdo->prepare('UPDATE products SET active = 0 WHERE id = ?')->execute([$id]);
                } else {
                    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
                }
                Helpers::audit($pdo, 'PRODUCT', $id, 'PRODUCT_DELETED', 'Patron', "Produit supprimé : {$name}");
                Helpers::json(['ok' => true]);
            }
        }

        if ($path === '/api/products/stock' && $method === 'POST') {
            $b = Helpers::body();
            $id = Helpers::intVal($b['product_id'] ?? 0);
            $type = Helpers::str($b['type'] ?? 'IN', 20);
            $qty = Helpers::intVal($b['quantity'] ?? 0);
            $note = Helpers::str($b['note'] ?? '', 255);
            if ($id <= 0 || $qty === 0 || !in_array($type, ['IN', 'OUT', 'ADJUSTMENT'], true)) {
                Helpers::error('Paramètres de mouvement de stock invalides.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $prod = $stmt->fetch();
            if (!$prod) {
                $pdo->rollBack();
                Helpers::error('Produit introuvable.', 404);
            }

            $before = (int)$prod['stock'];
            if ($type === 'IN') {
                $delta = abs($qty);
                $after = $before + $delta;
            } elseif ($type === 'OUT') {
                $delta = -abs($qty);
                $after = max(0, $before + $delta);
                $delta = $after - $before;
            } else {
                // ADJUSTMENT sets exact target stock
                $after = max(0, $qty);
                $delta = $after - $before;
            }

            $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$after, $id]);
            $pdo->prepare(
                'INSERT INTO stock_movements (product_id, product_name, type, quantity_delta, stock_before, stock_after, reference, note, actor, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, "MANUAL", ?, "Patron", 0)'
            )->execute([$id, $prod['name'], $type, $delta, $before, $after, $note ?: 'Ajustement de stock']);

            Helpers::audit(
                $pdo,
                'STOCK',
                $id,
                'STOCK_MOVEMENT',
                'Patron',
                "Stock {$prod['name']} : {$before} -> {$after} ({$type})" . ($note ? " — {$note}" : '')
            );
            $pdo->commit();
            Helpers::json(['ok' => true, 'stock_before' => $before, 'stock_after' => $after]);
        }

        if ($path === '/api/stock-movements' && $method === 'GET') {
            $pid = Helpers::intVal($_GET['product_id'] ?? 0);
            $sql = 'SELECT * FROM stock_movements';
            $params = [];
            if ($pid > 0) {
                $sql .= ' WHERE product_id = ?';
                $params[] = $pid;
            }
            $sql .= ' ORDER BY id DESC LIMIT 150';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            Helpers::json(['ok' => true, 'movements' => $stmt->fetchAll()]);
        }

        // 6. CUSTOMERS
        if ($path === '/api/customers') {
            if ($method === 'GET') {
                $q = Helpers::str($_GET['q'] ?? '', 120);
                $sql = "SELECT c.*,
                               COUNT(CASE WHEN s.status = 'COMPLETED' THEN 1 END) AS purchase_count,
                               COALESCE(SUM(CASE WHEN s.status = 'COMPLETED' THEN s.total_amount ELSE 0 END), 0) AS total_spent,
                               MAX(s.created_at) AS last_purchase_at
                        FROM customers c
                        LEFT JOIN sales s ON s.customer_id = c.id";
                $params = [];
                if ($q !== '') {
                    $sql .= " WHERE c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?";
                    $like = "%{$q}%";
                    $params = [$like, $like, $like];
                }
                $sql .= " GROUP BY c.id ORDER BY c.name ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                Helpers::json(['ok' => true, 'customers' => $stmt->fetchAll()]);
            }

            $b = Helpers::body();
            if ($method === 'POST') {
                $name = Helpers::str($b['name'] ?? '', 160);
                if ($name === '') Helpers::error('Le nom du client est requis.');
                $phone = Helpers::str($b['phone'] ?? '', 64);
                $email = Helpers::str($b['email'] ?? '', 160);
                $address = Helpers::str($b['address'] ?? '', 255);
                $notes = Helpers::str($b['notes'] ?? '', 500);
                $stmt = $pdo->prepare('INSERT INTO customers (name, phone, email, address, notes, is_demo) VALUES (?, ?, ?, ?, ?, 0)');
                $stmt->execute([$name, $phone ?: null, $email ?: null, $address ?: null, $notes ?: null]);
                $cid = (int)$pdo->lastInsertId();
                Helpers::audit($pdo, 'CUSTOMER', $cid, 'CUSTOMER_CREATED', 'Patron', "Client ajouté : {$name}");
                Helpers::json(['ok' => true, 'id' => $cid]);
            }
            if ($method === 'PUT') {
                $id = Helpers::intVal($b['id'] ?? 0);
                $name = Helpers::str($b['name'] ?? '', 160);
                if ($id <= 0 || $name === '') Helpers::error('ID et nom du client requis.');
                $phone = Helpers::str($b['phone'] ?? '', 64);
                $email = Helpers::str($b['email'] ?? '', 160);
                $address = Helpers::str($b['address'] ?? '', 255);
                $notes = Helpers::str($b['notes'] ?? '', 500);
                $pdo->prepare('UPDATE customers SET name = ?, phone = ?, email = ?, address = ?, notes = ? WHERE id = ?')
                    ->execute([$name, $phone ?: null, $email ?: null, $address ?: null, $notes ?: null, $id]);
                Helpers::audit($pdo, 'CUSTOMER', $id, 'CUSTOMER_UPDATED', 'Patron', "Client modifié : {$name}");
                Helpers::json(['ok' => true]);
            }
            if ($method === 'DELETE') {
                $id = Helpers::intVal($_GET['id'] ?? $b['id'] ?? 0);
                if ($id <= 0) Helpers::error('ID client requis.');
                $name = (string)$pdo->query("SELECT name FROM customers WHERE id = {$id}")->fetchColumn();
                $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
                Helpers::audit($pdo, 'CUSTOMER', $id, 'CUSTOMER_DELETED', 'Patron', "Client supprimé : {$name}");
                Helpers::json(['ok' => true]);
            }
        }

        if ($path === '/api/customers/history' && $method === 'GET') {
            $id = Helpers::intVal($_GET['id'] ?? 0);
            if ($id <= 0) Helpers::error('ID client requis.');
            $custStmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
            $custStmt->execute([$id]);
            $cust = $custStmt->fetch();
            if (!$cust) Helpers::error('Client introuvable.', 404);

            $salesStmt = $pdo->prepare(
                'SELECT s.*, (SELECT COALESCE(SUM(quantity),0) FROM sale_items WHERE sale_id = s.id) AS items_count
                 FROM sales s WHERE s.customer_id = ? ORDER BY s.id DESC'
            );
            $salesStmt->execute([$id]);
            Helpers::json(['ok' => true, 'customer' => $cust, 'sales' => $salesStmt->fetchAll()]);
        }

        // 6B. ORDERS (ACCUEIL -> CAISSIER -> REÇU)
        if ($path === '/api/orders') {
            if ($method === 'GET') {
                $status = Helpers::str($_GET['status'] ?? '', 20);
                $q = Helpers::str($_GET['q'] ?? '', 120);
                $sql = "SELECT o.*,
                               (SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi WHERE oi.order_id = o.id) AS items_count,
                               (SELECT GROUP_CONCAT(CONCAT(oi.quantity, '× ', oi.product_name) SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) AS items_summary
                        FROM orders o
                        WHERE 1=1";
                $params = [];
                if ($status !== '' && in_array($status, ['PENDING', 'PAID', 'CANCELLED'], true)) {
                    $sql .= " AND o.status = ?";
                    $params[] = $status;
                }
                if ($q !== '') {
                    $sql .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.created_by LIKE ?)";
                    $like = "%{$q}%";
                    array_push($params, $like, $like, $like);
                }
                $sql .= " ORDER BY CASE WHEN o.status = 'PENDING' THEN 0 ELSE 1 END, o.id DESC LIMIT 120";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                Helpers::json(['ok' => true, 'orders' => $stmt->fetchAll()]);
            }

            if ($method === 'POST') {
                $b = Helpers::body();
                $itemsInput = $b['items'] ?? [];
                if (!is_array($itemsInput) || empty($itemsInput)) {
                    Helpers::error('La commande ne contient aucun article.');
                }

                $customerId = !empty($b['customer_id']) ? Helpers::intVal($b['customer_id']) : null;
                $customerName = Helpers::str($b['customer_name'] ?? '', 160);
                $discount = max(0, Helpers::intVal($b['discount_amount'] ?? 0));
                $createdBy = Helpers::str($b['created_by'] ?? 'Accueil', 100) ?: 'Accueil';
                $notes = Helpers::str($b['notes'] ?? '', 500);

                $pdo->beginTransaction();
                try {
                    if ($customerId && $customerName === '') {
                        $cStmt = $pdo->prepare('SELECT name FROM customers WHERE id = ?');
                        $cStmt->execute([$customerId]);
                        $customerName = (string)($cStmt->fetchColumn() ?: '');
                    }

                    $cppItems = [];
                    $prods = [];
                    $pSel = $pdo->prepare('SELECT * FROM products WHERE id = ? AND active = 1');
                    foreach ($itemsInput as $reqIt) {
                        $pid = Helpers::intVal($reqIt['product_id'] ?? 0);
                        $qty = Helpers::intVal($reqIt['quantity'] ?? 0);
                        if ($pid <= 0 || $qty <= 0) {
                            throw new RuntimeException('Article ou quantité invalide.');
                        }
                        $pSel->execute([$pid]);
                        $prod = $pSel->fetch();
                        if (!$prod) {
                            throw new RuntimeException("Produit #{$pid} introuvable.");
                        }
                        if ((int)$prod['stock'] < $qty) {
                            throw new RuntimeException("Stock insuffisant pour « {$prod['name']} » (disponible : {$prod['stock']}).");
                        }
                        $prods[$pid] = $prod;
                        $cppItems[] = [
                            'product_id' => $pid,
                            'quantity' => $qty,
                            'unit_price' => (int)$prod['sell_price'],
                            'buy_price' => (int)$prod['buy_price'],
                        ];
                    }

                    $calc = CppEngine::run([
                        'op' => 'calculate_sale',
                        'items' => $cppItems,
                        'discount_amount' => $discount,
                        'amount_given' => 0,
                        'is_cash' => 0,
                    ]);

                    $orderNo = Helpers::nextOrderNumber($pdo);
                    $pdo->prepare(
                        'INSERT INTO orders (order_number, customer_id, customer_name, subtotal, discount_amount, total_amount, status, created_by, notes, is_demo)
                         VALUES (?, ?, ?, ?, ?, ?, "PENDING", ?, ?, 0)'
                    )->execute([
                        $orderNo,
                        $customerId,
                        $customerName !== '' ? $customerName : null,
                        (int)$calc['subtotal'],
                        (int)$calc['discount_amount'],
                        (int)$calc['total_amount'],
                        $createdBy,
                        $notes !== '' ? $notes : null,
                    ]);
                    $orderId = (int)$pdo->lastInsertId();

                    $oiIns = $pdo->prepare(
                        'INSERT INTO order_items (order_id, product_id, product_name, unit_price, buy_price, quantity, line_total)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    foreach ($calc['items'] as $ci) {
                        $pid = (int)$ci['product_id'];
                        $pr = $prods[$pid];
                        $oiIns->execute([
                            $orderId,
                            $pid,
                            $pr['name'],
                            (int)$ci['unit_price'],
                            (int)$ci['buy_price'],
                            (int)$ci['quantity'],
                            (int)$ci['line_total'],
                        ]);
                    }

                    Helpers::audit(
                        $pdo,
                        'ORDER',
                        $orderId,
                        'ORDER_SENT_TO_CASHIER',
                        $createdBy,
                        "Commande {$orderNo} envoyée à la caisse ({$calc['total_amount']} FCFA)"
                    );
                    $pdo->commit();
                    Helpers::json([
                        'ok' => true,
                        'order_id' => $orderId,
                        'order_number' => $orderNo,
                        'total_amount' => (int)$calc['total_amount'],
                    ]);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    Helpers::error($e->getMessage(), 400);
                }
            }
        }

        if ($path === '/api/orders/detail' && $method === 'GET') {
            $id = Helpers::intVal($_GET['id'] ?? 0);
            if ($id <= 0) Helpers::error('ID commande requis.');
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            if (!$order) Helpers::error('Commande introuvable.', 404);
            $itStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
            $itStmt->execute([$id]);
            Helpers::json(['ok' => true, 'order' => $order, 'items' => $itStmt->fetchAll()]);
        }

        if ($path === '/api/orders/pay' && $method === 'POST') {
            $b = Helpers::body();
            $orderId = Helpers::intVal($b['order_id'] ?? 0);
            if ($orderId <= 0) Helpers::error('ID commande requis.');

            $paymentCode = Helpers::str($b['payment_code'] ?? 'CASH', 50);
            $pmStmt = $pdo->prepare('SELECT * FROM payment_methods WHERE code = ? AND active = 1');
            $pmStmt->execute([$paymentCode]);
            $pm = $pmStmt->fetch();
            if (!$pm) {
                $pm = ['code' => 'CASH', 'label' => Helpers::str($b['payment_method'] ?? 'Espèces', 80) ?: 'Espèces', 'is_cash' => 1];
            }

            $amountGiven = Helpers::intVal($b['amount_given'] ?? 0);
            $cashierName = Helpers::str($b['cashier_name'] ?? 'Caissier', 100) ?: 'Caissier';

            $pdo->beginTransaction();
            try {
                $oStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
                $oStmt->execute([$orderId]);
                $order = $oStmt->fetch();
                if (!$order) throw new RuntimeException('Commande introuvable.');
                if ($order['status'] !== 'PENDING') {
                    throw new RuntimeException('Cette commande a déjà été traitée.');
                }

                $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
                $itemsStmt->execute([$orderId]);
                $orderItems = $itemsStmt->fetchAll();
                if (empty($orderItems)) throw new RuntimeException('Commande sans articles.');

                $prodQuery = $pdo->prepare('SELECT * FROM products WHERE id = ? AND active = 1 FOR UPDATE');
                $cppItems = [];
                $lockedProducts = [];
                foreach ($orderItems as $it) {
                    $pid = (int)$it['product_id'];
                    $qty = (int)$it['quantity'];
                    $prodQuery->execute([$pid]);
                    $prod = $prodQuery->fetch();
                    if (!$prod) throw new RuntimeException("Produit « {$it['product_name']} » introuvable.");
                    if ((int)$prod['stock'] < $qty) {
                        throw new RuntimeException("Stock insuffisant pour « {$prod['name']} » (disponible : {$prod['stock']}).");
                    }
                    $lockedProducts[$pid] = $prod;
                    $cppItems[] = [
                        'product_id' => $pid,
                        'quantity' => $qty,
                        'unit_price' => (int)$it['unit_price'],
                        'buy_price' => (int)$prod['buy_price'],
                    ];
                }

                $calc = CppEngine::run([
                    'op' => 'calculate_sale',
                    'items' => $cppItems,
                    'discount_amount' => (int)$order['discount_amount'],
                    'amount_given' => $amountGiven,
                    'is_cash' => (int)$pm['is_cash'],
                ]);

                $openSess = getOpenSession($pdo);
                $sessId = $openSess ? (int)$openSess['id'] : null;
                $recNo = Helpers::nextReceiptNumber($pdo);
                $settings = Helpers::getSettings($pdo);
                $isDemo = (int)$order['is_demo'];

                $pdo->prepare(
                    'INSERT INTO sales (receipt_number, cash_session_id, customer_id, customer_name, payment_method, payment_code, subtotal, discount_amount, total_amount, amount_given, change_amount, status, cashier_name, notes, is_demo)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "COMPLETED", ?, ?, ?)'
                )->execute([
                    $recNo,
                    $sessId,
                    $order['customer_id'],
                    $order['customer_name'],
                    $pm['label'],
                    $pm['code'],
                    (int)$calc['subtotal'],
                    (int)$calc['discount_amount'],
                    (int)$calc['total_amount'],
                    (int)$calc['amount_given'],
                    (int)$calc['change_amount'],
                    $cashierName,
                    "Depuis commande {$order['order_number']}" . ($order['notes'] ? " — {$order['notes']}" : ''),
                    $isDemo,
                ]);
                $saleId = (int)$pdo->lastInsertId();

                $itemIns = $pdo->prepare(
                    'INSERT INTO sale_items (sale_id, product_id, product_name, unit_price, buy_price, quantity, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stockUpd = $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?');
                $smIns = $pdo->prepare(
                    'INSERT INTO stock_movements (product_id, product_name, type, quantity_delta, stock_before, stock_after, reference, note, actor, is_demo)
                     VALUES (?, ?, "SALE", ?, ?, ?, ?, ?, ?, ?)'
                );

                $receiptItems = [];
                foreach ($calc['items'] as $ci) {
                    $pid = (int)$ci['product_id'];
                    $prod = $lockedProducts[$pid];
                    $qty = (int)$ci['quantity'];
                    $before = (int)$prod['stock'];
                    $after = $before - $qty;

                    $itemIns->execute([
                        $saleId, $pid, $prod['name'], (int)$ci['unit_price'], (int)$ci['buy_price'], $qty, (int)$ci['line_total'],
                    ]);
                    $stockUpd->execute([$after, $pid]);
                    $smIns->execute([$pid, $prod['name'], -$qty, $before, $after, $recNo, "Encaissement {$order['order_number']}", $cashierName, $isDemo]);

                    $receiptItems[] = [
                        'product_id' => $pid,
                        'product_name' => $prod['name'],
                        'unit_price' => (int)$ci['unit_price'],
                        'quantity' => $qty,
                        'line_total' => (int)$ci['line_total'],
                    ];
                }

                $nowStr = date('Y-m-d H:i:s');
                $receiptPayload = [
                    'sale_id' => $saleId,
                    'order_number' => $order['order_number'],
                    'receipt_number' => $recNo,
                    'store_name' => $settings['store_name'] ?? 'FINCAISSE',
                    'store_tagline' => $settings['store_tagline'] ?? '',
                    'store_phone' => $settings['store_phone'] ?? '',
                    'store_address' => $settings['store_address'] ?? '',
                    'currency' => $settings['currency'] ?? 'FCFA',
                    'receipt_footer' => $settings['receipt_footer'] ?? 'Merci de votre visite !',
                    'customer_name' => $order['customer_name'] ?: '',
                    'cashier_name' => $cashierName,
                    'seller_name' => $order['created_by'],
                    'payment_method' => $pm['label'],
                    'subtotal' => (int)$calc['subtotal'],
                    'discount_amount' => (int)$calc['discount_amount'],
                    'total_amount' => (int)$calc['total_amount'],
                    'amount_given' => (int)$calc['amount_given'],
                    'change_amount' => (int)$calc['change_amount'],
                    'items' => $receiptItems,
                    'created_at' => $nowStr,
                    'status' => 'COMPLETED',
                ];

                $pdo->prepare(
                    'INSERT INTO receipts (sale_id, receipt_number, payload_json, printed_count, is_demo)
                     VALUES (?, ?, ?, 0, ?)'
                )->execute([
                    $saleId,
                    $recNo,
                    json_encode($receiptPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $isDemo,
                ]);

                $pdo->prepare(
                    'UPDATE orders SET status = "PAID", paid_by = ?, sale_id = ?, paid_at = NOW() WHERE id = ?'
                )->execute([$cashierName, $saleId, $orderId]);

                if ($sessId !== null && (int)$pm['is_cash'] === 1) {
                    $pdo->prepare(
                        'INSERT INTO cash_movements (session_id, type, amount, reference_id, description, actor, is_demo)
                         VALUES (?, "SALE", ?, ?, ?, ?, ?)'
                    )->execute([
                        $sessId,
                        (int)$calc['total_amount'],
                        $saleId,
                        "Encaissement {$order['order_number']} -> {$recNo}",
                        $cashierName,
                        $isDemo,
                    ]);
                    recalcOpenSession($pdo, $sessId);
                }

                Helpers::audit(
                    $pdo,
                    'ORDER',
                    $orderId,
                    'ORDER_PAID',
                    $cashierName,
                    "Commande {$order['order_number']} encaissée ({$calc['total_amount']} FCFA, {$pm['label']}) — Reçu {$recNo}"
                );
                $pdo->commit();
                Helpers::json([
                    'ok' => true,
                    'sale_id' => $saleId,
                    'receipt_number' => $recNo,
                    'receipt' => $receiptPayload,
                    'calculation' => $calc,
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                Helpers::error($e->getMessage(), 400);
            }
        }

        if ($path === '/api/orders/cancel' && $method === 'POST') {
            $b = Helpers::body();
            $orderId = Helpers::intVal($b['order_id'] ?? 0);
            $reason = Helpers::str($b['reason'] ?? '', 500);
            $actor = Helpers::str($b['actor'] ?? 'Accueil', 100) ?: 'Accueil';
            if ($orderId <= 0 || $reason === '') {
                Helpers::error('Un motif d’annulation est obligatoire.');
            }
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) Helpers::error('Commande introuvable.', 404);
            if ($order['status'] !== 'PENDING') Helpers::error('Seule une commande en attente peut être annulée ici.');

            $pdo->prepare('UPDATE orders SET status = "CANCELLED", cancel_reason = ? WHERE id = ?')
                ->execute([$reason, $orderId]);
            Helpers::audit($pdo, 'ORDER', $orderId, 'ORDER_CANCELLED', $actor, "Commande {$order['order_number']} annulée : {$reason}");
            Helpers::json(['ok' => true]);
        }

        // 7. SALES & CHECKOUT
        if ($path === '/api/sales') {
            if ($method === 'GET') {
                $scope = Helpers::str($_GET['scope'] ?? 'all', 20);
                $status = Helpers::str($_GET['status'] ?? '', 20);
                $q = Helpers::str($_GET['q'] ?? '', 120);
                $from = Helpers::str($_GET['from'] ?? '', 20);
                $to = Helpers::str($_GET['to'] ?? '', 20);
                $cashier = Helpers::str($_GET['cashier'] ?? '', 100);

                $sql = "SELECT s.*,
                               (SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si WHERE si.sale_id = s.id) AS items_count,
                               (SELECT GROUP_CONCAT(CONCAT(si.quantity, '× ', si.product_name) SEPARATOR ', ') FROM sale_items si WHERE si.sale_id = s.id) AS items_summary
                        FROM sales s
                        WHERE 1=1";
                $params = [];

                if ($scope === 'today') {
                    $sql .= " AND DATE(s.created_at) = CURDATE()";
                } elseif ($scope === 'archived') {
                    $sql .= " AND s.archived_at IS NOT NULL";
                } else {
                    $sql .= " AND s.archived_at IS NULL";
                }

                if ($status !== '' && in_array($status, ['COMPLETED', 'CANCELLED'], true)) {
                    $sql .= " AND s.status = ?";
                    $params[] = $status;
                }
                if ($from !== '') {
                    $sql .= " AND DATE(s.created_at) >= ?";
                    $params[] = $from;
                }
                if ($to !== '') {
                    $sql .= " AND DATE(s.created_at) <= ?";
                    $params[] = $to;
                }
                if ($cashier !== '') {
                    $sql .= " AND s.cashier_name LIKE ?";
                    $params[] = "%{$cashier}%";
                }
                if ($q !== '') {
                    $sql .= " AND (s.receipt_number LIKE ? OR s.customer_name LIKE ? OR s.payment_method LIKE ?
                              OR EXISTS (SELECT 1 FROM sale_items si2 WHERE si2.sale_id = s.id AND si2.product_name LIKE ?))";
                    $like = "%{$q}%";
                    array_push($params, $like, $like, $like, $like);
                }
                $sql .= " ORDER BY s.id DESC LIMIT 200";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                Helpers::json(['ok' => true, 'sales' => $stmt->fetchAll()]);
            }

            if ($method === 'POST') {
                $b = Helpers::body();
                $itemsInput = $b['items'] ?? [];
                if (!is_array($itemsInput) || empty($itemsInput)) {
                    Helpers::error('Le panier est vide.');
                }

                $paymentCode = Helpers::str($b['payment_code'] ?? 'CASH', 50);
                $pmStmt = $pdo->prepare('SELECT * FROM payment_methods WHERE code = ? AND active = 1');
                $pmStmt->execute([$paymentCode]);
                $pm = $pmStmt->fetch();
                if (!$pm) {
                    $pm = ['code' => 'CASH', 'label' => Helpers::str($b['payment_method'] ?? 'Espèces', 80) ?: 'Espèces', 'is_cash' => 1];
                }

                $customerId = !empty($b['customer_id']) ? Helpers::intVal($b['customer_id']) : null;
                $customerName = Helpers::str($b['customer_name'] ?? '', 160);
                $discount = max(0, Helpers::intVal($b['discount_amount'] ?? 0));
                $amountGiven = Helpers::intVal($b['amount_given'] ?? 0);
                $cashierName = Helpers::str($b['cashier_name'] ?? 'Caissier', 100) ?: 'Caissier';
                $notes = Helpers::str($b['notes'] ?? '', 500);

                $pdo->beginTransaction();
                try {
                    if ($customerId) {
                        $cStmt = $pdo->prepare('SELECT id, name FROM customers WHERE id = ?');
                        $cStmt->execute([$customerId]);
                        $cRow = $cStmt->fetch();
                        if ($cRow && $customerName === '') {
                            $customerName = $cRow['name'];
                        }
                    }

                    $cppItems = [];
                    $lockedProducts = [];
                    $prodQuery = $pdo->prepare('SELECT * FROM products WHERE id = ? AND active = 1 FOR UPDATE');

                    foreach ($itemsInput as $reqIt) {
                        $pid = Helpers::intVal($reqIt['product_id'] ?? 0);
                        $qty = Helpers::intVal($reqIt['quantity'] ?? 0);
                        if ($pid <= 0 || $qty <= 0) {
                            throw new RuntimeException('Article ou quantité invalide.');
                        }
                        $prodQuery->execute([$pid]);
                        $prod = $prodQuery->fetch();
                        if (!$prod) {
                            throw new RuntimeException("Produit #{$pid} introuvable ou inactif.");
                        }
                        if ((int)$prod['stock'] < $qty) {
                            throw new RuntimeException("Stock insuffisant pour « {$prod['name']} » (disponible : {$prod['stock']}).");
                        }
                        $lockedProducts[$pid] = $prod;
                        $cppItems[] = [
                            'product_id' => $pid,
                            'quantity' => $qty,
                            'unit_price' => (int)$prod['sell_price'],
                            'buy_price' => (int)$prod['buy_price'],
                        ];
                    }

                    // Delegate financial math & validation to C++20 Engine
                    $calc = CppEngine::run([
                        'op' => 'calculate_sale',
                        'items' => $cppItems,
                        'discount_amount' => $discount,
                        'amount_given' => $amountGiven,
                        'is_cash' => (int)$pm['is_cash'],
                    ]);

                    $openSess = getOpenSession($pdo);
                    $sessId = $openSess ? (int)$openSess['id'] : null;
                    $recNo = Helpers::nextReceiptNumber($pdo);
                    $settings = Helpers::getSettings($pdo);

                    $saleIns = $pdo->prepare(
                        'INSERT INTO sales (receipt_number, cash_session_id, customer_id, customer_name, payment_method, payment_code, subtotal, discount_amount, total_amount, amount_given, change_amount, status, cashier_name, notes, is_demo)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "COMPLETED", ?, ?, 0)'
                    );
                    $saleIns->execute([
                        $recNo,
                        $sessId,
                        $customerId,
                        $customerName !== '' ? $customerName : null,
                        $pm['label'],
                        $pm['code'],
                        (int)$calc['subtotal'],
                        (int)$calc['discount_amount'],
                        (int)$calc['total_amount'],
                        (int)$calc['amount_given'],
                        (int)$calc['change_amount'],
                        $cashierName,
                        $notes !== '' ? $notes : null,
                    ]);
                    $saleId = (int)$pdo->lastInsertId();

                    $itemIns = $pdo->prepare(
                        'INSERT INTO sale_items (sale_id, product_id, product_name, unit_price, buy_price, quantity, line_total)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stockUpd = $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?');
                    $smIns = $pdo->prepare(
                        'INSERT INTO stock_movements (product_id, product_name, type, quantity_delta, stock_before, stock_after, reference, note, actor, is_demo)
                         VALUES (?, ?, "SALE", ?, ?, ?, ?, "Sortie suite à vente validée", ?, 0)'
                    );

                    $receiptItems = [];
                    foreach ($calc['items'] as $ci) {
                        $pid = (int)$ci['product_id'];
                        $prod = $lockedProducts[$pid];
                        $qty = (int)$ci['quantity'];
                        $before = (int)$prod['stock'];
                        $after = $before - $qty;

                        $itemIns->execute([
                            $saleId,
                            $pid,
                            $prod['name'],
                            (int)$ci['unit_price'],
                            (int)$ci['buy_price'],
                            $qty,
                            (int)$ci['line_total'],
                        ]);
                        $stockUpd->execute([$after, $pid]);
                        $smIns->execute([$pid, $prod['name'], -$qty, $before, $after, $recNo, $cashierName]);

                        $receiptItems[] = [
                            'product_id' => $pid,
                            'product_name' => $prod['name'],
                            'unit_price' => (int)$ci['unit_price'],
                            'quantity' => $qty,
                            'line_total' => (int)$ci['line_total'],
                        ];
                    }

                    $nowStr = date('Y-m-d H:i:s');
                    $receiptPayload = [
                        'sale_id' => $saleId,
                        'receipt_number' => $recNo,
                        'store_name' => $settings['store_name'] ?? 'FINCAISSE',
                        'store_tagline' => $settings['store_tagline'] ?? '',
                        'store_phone' => $settings['store_phone'] ?? '',
                        'store_address' => $settings['store_address'] ?? '',
                        'currency' => $settings['currency'] ?? 'FCFA',
                        'receipt_footer' => $settings['receipt_footer'] ?? 'Merci de votre visite !',
                        'customer_name' => $customerName,
                        'cashier_name' => $cashierName,
                        'payment_method' => $pm['label'],
                        'subtotal' => (int)$calc['subtotal'],
                        'discount_amount' => (int)$calc['discount_amount'],
                        'total_amount' => (int)$calc['total_amount'],
                        'amount_given' => (int)$calc['amount_given'],
                        'change_amount' => (int)$calc['change_amount'],
                        'items' => $receiptItems,
                        'created_at' => $nowStr,
                        'status' => 'COMPLETED',
                    ];

                    $pdo->prepare(
                        'INSERT INTO receipts (sale_id, receipt_number, payload_json, printed_count, is_demo)
                         VALUES (?, ?, ?, 0, 0)'
                    )->execute([
                        $saleId,
                        $recNo,
                        json_encode($receiptPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);

                    if ($sessId !== null && (int)$pm['is_cash'] === 1) {
                        $pdo->prepare(
                            'INSERT INTO cash_movements (session_id, type, amount, reference_id, description, actor, is_demo)
                             VALUES (?, "SALE", ?, ?, ?, ?, 0)'
                        )->execute([
                            $sessId,
                            (int)$calc['total_amount'],
                            $saleId,
                            "Encaissement vente {$recNo}",
                            $cashierName,
                        ]);
                        recalcOpenSession($pdo, $sessId);
                    }

                    Helpers::audit(
                        $pdo,
                        'SALE',
                        $saleId,
                        'SALE_COMPLETED',
                        $cashierName,
                        "Vente {$recNo} validée : {$calc['total_amount']} FCFA ({$pm['label']})"
                    );

                    $pdo->commit();
                    Helpers::json([
                        'ok' => true,
                        'sale_id' => $saleId,
                        'receipt_number' => $recNo,
                        'receipt' => $receiptPayload,
                        'calculation' => $calc,
                    ]);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    Helpers::error($e->getMessage(), 400);
                }
            }
        }

        if ($path === '/api/sales/detail' && $method === 'GET') {
            $id = Helpers::intVal($_GET['id'] ?? 0);
            if ($id <= 0) Helpers::error('ID vente requis.');
            $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ?');
            $stmt->execute([$id]);
            $sale = $stmt->fetch();
            if (!$sale) Helpers::error('Vente introuvable.', 404);

            $itemsStmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = ?');
            $itemsStmt->execute([$id]);
            $recStmt = $pdo->prepare('SELECT * FROM receipts WHERE sale_id = ?');
            $recStmt->execute([$id]);
            $rec = $recStmt->fetch();

            Helpers::json([
                'ok' => true,
                'sale' => $sale,
                'items' => $itemsStmt->fetchAll(),
                'receipt' => $rec ? json_decode($rec['payload_json'], true) : null,
                'printed_count' => $rec ? (int)$rec['printed_count'] : 0,
            ]);
        }

        if ($path === '/api/sales/cancel' && $method === 'POST') {
            $b = Helpers::body();
            $id = Helpers::intVal($b['sale_id'] ?? 0);
            $reason = Helpers::str($b['reason'] ?? '', 500);
            $actor = Helpers::str($b['actor'] ?? 'Patron', 100) ?: 'Patron';
            if ($id <= 0 || $reason === '') {
                Helpers::error('Un motif d’annulation est obligatoire pour la traçabilité.');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $sale = $stmt->fetch();
                if (!$sale) {
                    throw new RuntimeException('Vente introuvable.');
                }
                if ($sale['status'] === 'CANCELLED') {
                    throw new RuntimeException('Cette vente est déjà annulée.');
                }

                $itemsStmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = ?');
                $itemsStmt->execute([$id]);
                $items = $itemsStmt->fetchAll();

                $prodSel = $pdo->prepare('SELECT id, name, stock FROM products WHERE id = ? FOR UPDATE');
                $prodUpd = $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?');
                $smIns = $pdo->prepare(
                    'INSERT INTO stock_movements (product_id, product_name, type, quantity_delta, stock_before, stock_after, reference, note, actor, is_demo)
                     VALUES (?, ?, "RETURN", ?, ?, ?, ?, ?, ?, ?)'
                );

                foreach ($items as $it) {
                    if (!$it['product_id']) continue;
                    $prodSel->execute([(int)$it['product_id']]);
                    $pr = $prodSel->fetch();
                    if ($pr) {
                        $before = (int)$pr['stock'];
                        $qty = (int)$it['quantity'];
                        $after = $before + $qty;
                        $prodUpd->execute([$after, (int)$pr['id']]);
                        $smIns->execute([
                            (int)$pr['id'],
                            $pr['name'],
                            $qty,
                            $before,
                            $after,
                            $sale['receipt_number'],
                            "Annulation vente : {$reason}",
                            $actor,
                            (int)$sale['is_demo'],
                        ]);
                    }
                }

                $pdo->prepare(
                    'UPDATE sales SET status = "CANCELLED", cancel_reason = ?, cancelled_at = NOW(), cancelled_by = ? WHERE id = ?'
                )->execute([$reason, $actor, $id]);

                // Update cash session if linked to an OPEN session and cash payment
                if ($sale['cash_session_id'] && $sale['payment_code'] === 'CASH') {
                    $sessCheck = $pdo->prepare('SELECT status FROM cash_sessions WHERE id = ?');
                    $sessCheck->execute([(int)$sale['cash_session_id']]);
                    if ($sessCheck->fetchColumn() === 'OPEN') {
                        $pdo->prepare(
                            'INSERT INTO cash_movements (session_id, type, amount, reference_id, description, actor, is_demo)
                             VALUES (?, "CANCELLATION", ?, ?, ?, ?, ?)'
                        )->execute([
                            (int)$sale['cash_session_id'],
                            (int)$sale['total_amount'],
                            $id,
                            "Annulation {$sale['receipt_number']} : {$reason}",
                            $actor,
                            (int)$sale['is_demo'],
                        ]);
                        recalcOpenSession($pdo, (int)$sale['cash_session_id']);
                    }
                }

                // Update stored receipt payload status
                $recStmt = $pdo->prepare('SELECT payload_json FROM receipts WHERE sale_id = ?');
                $recStmt->execute([$id]);
                $rawPayload = $recStmt->fetchColumn();
                if (is_string($rawPayload)) {
                    $p = json_decode($rawPayload, true);
                    if (is_array($p)) {
                        $p['status'] = 'CANCELLED';
                        $p['cancel_reason'] = $reason;
                        $pdo->prepare('UPDATE receipts SET payload_json = ? WHERE sale_id = ?')
                            ->execute([json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
                    }
                }

                Helpers::audit(
                    $pdo,
                    'SALE',
                    $id,
                    'SALE_CANCELLED',
                    $actor,
                    "Vente {$sale['receipt_number']} annulée ({$sale['total_amount']} FCFA). Motif : {$reason}"
                );
                $pdo->commit();
                Helpers::json(['ok' => true]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                Helpers::error($e->getMessage(), 400);
            }
        }

        // 8. RECEIPTS
        if ($path === '/api/receipts' && $method === 'GET') {
            $q = Helpers::str($_GET['q'] ?? '', 120);
            $sql = "SELECT r.id, r.sale_id, r.receipt_number, r.printed_count, r.is_demo, r.created_at,
                           s.customer_name, s.total_amount, s.payment_method, s.status, s.cashier_name, r.payload_json
                    FROM receipts r
                    JOIN sales s ON s.id = r.sale_id
                    WHERE r.archived_at IS NULL";
            $params = [];
            if ($q !== '') {
                $sql .= " AND (r.receipt_number LIKE ? OR s.customer_name LIKE ?)";
                $like = "%{$q}%";
                $params = [$like, $like];
            }
            $sql .= " ORDER BY r.id DESC LIMIT 120";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll() as $row) {
                $row['payload'] = json_decode($row['payload_json'], true);
                unset($row['payload_json']);
                $rows[] = $row;
            }
            Helpers::json(['ok' => true, 'receipts' => $rows]);
        }

        if ($path === '/api/receipts/print' && $method === 'POST') {
            $b = Helpers::body();
            $saleId = Helpers::intVal($b['sale_id'] ?? 0);
            if ($saleId <= 0) Helpers::error('sale_id requis.');
            $pdo->prepare('UPDATE receipts SET printed_count = printed_count + 1 WHERE sale_id = ?')->execute([$saleId]);
            Helpers::json(['ok' => true]);
        }

        // 9. EXPENSES
        if ($path === '/api/expenses') {
            if ($method === 'GET') {
                $includeDeleted = !empty($_GET['include_deleted']);
                $q = Helpers::str($_GET['q'] ?? '', 120);
                $sql = 'SELECT * FROM expenses WHERE 1=1';
                $params = [];
                if (!$includeDeleted) {
                    $sql .= ' AND deleted_at IS NULL';
                }
                if ($q !== '') {
                    $sql .= ' AND (category LIKE ? OR description LIKE ?)';
                    $params = ["%{$q}%", "%{$q}%"];
                }
                $sql .= ' ORDER BY expense_date DESC, id DESC LIMIT 200';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                Helpers::json(['ok' => true, 'expenses' => $stmt->fetchAll()]);
            }

            $b = Helpers::body();
            if ($method === 'POST') {
                $cat = Helpers::str($b['category'] ?? '', 100);
                $desc = Helpers::str($b['description'] ?? '', 255);
                $amount = Helpers::intVal($b['amount'] ?? 0);
                $pm = Helpers::str($b['payment_method'] ?? 'Espèces', 80) ?: 'Espèces';
                $date = Helpers::str($b['expense_date'] ?? date('Y-m-d'), 20) ?: date('Y-m-d');
                if ($cat === '' || $desc === '' || $amount <= 0) {
                    Helpers::error('Catégorie, description et montant positif obligatoires.');
                }

                $pdo->beginTransaction();
                $openSess = getOpenSession($pdo);
                $isCashExp = mb_stripos($pm, 'Espèce') !== false || mb_strtolower($pm) === 'cash';
                $sessId = ($openSess && $isCashExp && $date === date('Y-m-d')) ? (int)$openSess['id'] : null;

                $pdo->prepare(
                    'INSERT INTO expenses (cash_session_id, category, description, amount, payment_method, expense_date, actor, is_demo)
                     VALUES (?, ?, ?, ?, ?, ?, "Patron", 0)'
                )->execute([$sessId, $cat, $desc, $amount, $pm, $date]);
                $eid = (int)$pdo->lastInsertId();

                if ($sessId !== null) {
                    $pdo->prepare(
                        'INSERT INTO cash_movements (session_id, type, amount, reference_id, description, actor, is_demo)
                         VALUES (?, "EXPENSE", ?, ?, ?, "Patron", 0)'
                    )->execute([$sessId, $amount, $eid, "Dépense ({$cat}) : {$desc}"]);
                    recalcOpenSession($pdo, $sessId);
                }

                Helpers::audit($pdo, 'EXPENSE', $eid, 'EXPENSE_CREATED', 'Patron', "Dépense enregistrée : {$amount} FCFA ({$cat}) — {$desc}");
                $pdo->commit();
                Helpers::json(['ok' => true, 'id' => $eid]);
            }

            if ($method === 'PUT') {
                $id = Helpers::intVal($b['id'] ?? 0);
                $cat = Helpers::str($b['category'] ?? '', 100);
                $desc = Helpers::str($b['description'] ?? '', 255);
                $amount = Helpers::intVal($b['amount'] ?? 0);
                $pm = Helpers::str($b['payment_method'] ?? 'Espèces', 80);
                $date = Helpers::str($b['expense_date'] ?? date('Y-m-d'), 20);
                if ($id <= 0 || $cat === '' || $desc === '' || $amount <= 0) {
                    Helpers::error('Données de dépense invalides.');
                }

                $pdo->prepare(
                    'UPDATE expenses SET category = ?, description = ?, amount = ?, payment_method = ?, expense_date = ? WHERE id = ? AND deleted_at IS NULL'
                )->execute([$cat, $desc, $amount, $pm, $date, $id]);
                Helpers::audit($pdo, 'EXPENSE', $id, 'EXPENSE_UPDATED', 'Patron', "Dépense #{$id} modifiée : {$amount} FCFA ({$cat})");
                Helpers::json(['ok' => true]);
            }

            if ($method === 'DELETE') {
                $id = Helpers::intVal($_GET['id'] ?? $b['id'] ?? 0);
                $reason = Helpers::str($b['reason'] ?? $_GET['reason'] ?? 'Suppression par le patron', 255);
                if ($id <= 0) Helpers::error('ID dépense requis.');

                $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = ? AND deleted_at IS NULL');
                $stmt->execute([$id]);
                $exp = $stmt->fetch();
                if (!$exp) Helpers::error('Dépense introuvable.', 404);

                $pdo->beginTransaction();
                $pdo->prepare('UPDATE expenses SET deleted_at = NOW(), delete_reason = ? WHERE id = ?')
                    ->execute([$reason, $id]);

                if ($exp['cash_session_id']) {
                    $pdo->prepare('DELETE FROM cash_movements WHERE session_id = ? AND type = "EXPENSE" AND reference_id = ?')
                        ->execute([(int)$exp['cash_session_id'], $id]);
                    recalcOpenSession($pdo, (int)$exp['cash_session_id']);
                }

                Helpers::audit($pdo, 'EXPENSE', $id, 'EXPENSE_DELETED', 'Patron', "Dépense #{$id} supprimée ({$exp['amount']} FCFA). Motif : {$reason}");
                $pdo->commit();
                Helpers::json(['ok' => true]);
            }
        }

        // 10. CASH REGISTER MANAGEMENT
        if ($path === '/api/cash' && $method === 'GET') {
            $open = getOpenSession($pdo);
            $summary = $open ? recalcOpenSession($pdo, (int)$open['id']) : null;
            $movements = [];
            if ($open) {
                $mvStmt = $pdo->prepare('SELECT * FROM cash_movements WHERE session_id = ? ORDER BY id DESC');
                $mvStmt->execute([(int)$open['id']]);
                $movements = $mvStmt->fetchAll();
            } else {
                $movements = $pdo->query('SELECT * FROM cash_movements ORDER BY id DESC LIMIT 40')->fetchAll();
            }
            $history = $pdo->query('SELECT * FROM cash_sessions ORDER BY id DESC LIMIT 30')->fetchAll();

            Helpers::json([
                'ok' => true,
                'open_session' => $open,
                'summary' => $summary,
                'movements' => $movements,
                'history' => $history,
            ]);
        }

        if ($path === '/api/cash/open' && $method === 'POST') {
            if (getOpenSession($pdo)) {
                Helpers::error('Une caisse est déjà ouverte. Fermez-la avant d’en ouvrir une nouvelle.');
            }
            $b = Helpers::body();
            $opening = max(0, Helpers::intVal($b['opening_amount'] ?? 0));
            $actor = Helpers::str($b['opened_by'] ?? 'Patron', 100) ?: 'Patron';
            $notes = Helpers::str($b['notes'] ?? '', 500);

            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO cash_sessions (opened_by, opening_amount, expected_amount, status, notes, is_demo)
                 VALUES (?, ?, ?, "OPEN", ?, 0)'
            )->execute([$actor, $opening, $opening, $notes ?: null]);
            $sid = (int)$pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO cash_movements (session_id, type, amount, description, actor, is_demo)
                 VALUES (?, "OPENING", ?, "Ouverture de caisse", ?, 0)'
            )->execute([$sid, $opening, $actor]);

            Helpers::audit($pdo, 'CASH', $sid, 'CASH_OPENED', $actor, "Ouverture de caisse avec fond de {$opening} FCFA");
            $pdo->commit();
            Helpers::json(['ok' => true, 'session_id' => $sid]);
        }

        if ($path === '/api/cash/movement' && $method === 'POST') {
            $open = getOpenSession($pdo);
            if (!$open) Helpers::error('Aucune caisse ouverte actuellement.');
            $b = Helpers::body();
            $type = Helpers::str($b['type'] ?? '', 10);
            $amount = Helpers::intVal($b['amount'] ?? 0);
            $desc = Helpers::str($b['description'] ?? '', 255);
            $actor = Helpers::str($b['actor'] ?? 'Patron', 100) ?: 'Patron';
            if (!in_array($type, ['IN', 'OUT'], true) || $amount <= 0 || $desc === '') {
                Helpers::error('Type (IN/OUT), montant positif et motif requis.');
            }

            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO cash_movements (session_id, type, amount, description, actor, is_demo)
                 VALUES (?, ?, ?, ?, ?, 0)'
            )->execute([(int)$open['id'], $type, $amount, $desc, $actor]);
            $summary = recalcOpenSession($pdo, (int)$open['id']);

            $label = $type === 'IN' ? 'Entrée caisse' : 'Sortie caisse';
            Helpers::audit($pdo, 'CASH', (int)$open['id'], 'CASH_MOVEMENT', $actor, "{$label} : {$amount} FCFA — {$desc}");
            $pdo->commit();
            Helpers::json(['ok' => true, 'summary' => $summary]);
        }

        if ($path === '/api/cash/close' && $method === 'POST') {
            $open = getOpenSession($pdo);
            if (!$open) Helpers::error('Aucune caisse ouverte à fermer.');
            $b = Helpers::body();
            $actual = max(0, Helpers::intVal($b['actual_amount'] ?? 0));
            $actor = Helpers::str($b['closed_by'] ?? 'Patron', 100) ?: 'Patron';
            $notes = Helpers::str($b['notes'] ?? '', 500);

            $pdo->beginTransaction();
            $sid = (int)$open['id'];
            $mvStmt = $pdo->prepare('SELECT type, COALESCE(SUM(amount), 0) AS total FROM cash_movements WHERE session_id = ? GROUP BY type');
            $mvStmt->execute([$sid]);
            $byType = [];
            foreach ($mvStmt->fetchAll() as $row) $byType[$row['type']] = (int)$row['total'];

            $rec = CppEngine::run([
                'op' => 'reconcile_cash',
                'opening_amount' => (int)$open['opening_amount'],
                'cash_sales' => $byType['SALE'] ?? 0,
                'manual_in' => $byType['IN'] ?? 0,
                'cash_expenses' => $byType['EXPENSE'] ?? 0,
                'manual_out' => $byType['OUT'] ?? 0,
                'cancelled_cash' => $byType['CANCELLATION'] ?? 0,
                'has_actual' => 1,
                'actual_amount' => $actual,
            ]);

            $pdo->prepare(
                'UPDATE cash_sessions
                 SET closed_by = ?, closed_at = NOW(), expected_amount = ?, actual_amount = ?, discrepancy = ?, status = "CLOSED", notes = CONCAT(COALESCE(notes, ""), ?)
                 WHERE id = ?'
            )->execute([
                $actor,
                (int)$rec['expected_amount'],
                $actual,
                (int)$rec['discrepancy'],
                $notes !== '' ? ("\nClôture : " . $notes) : '',
                $sid,
            ]);

            $pdo->prepare(
                'INSERT INTO cash_movements (session_id, type, amount, description, actor, is_demo)
                 VALUES (?, "CLOSING", ?, ?, ?, 0)'
            )->execute([
                $sid,
                $actual,
                "Fermeture caisse (Attendu : {$rec['expected_amount']} FCFA, Écart : {$rec['discrepancy']} FCFA)",
                $actor,
            ]);

            Helpers::audit(
                $pdo,
                'CASH',
                $sid,
                'CASH_CLOSED',
                $actor,
                "Fermeture caisse #{$sid} : Réel {$actual} FCFA vs Attendu {$rec['expected_amount']} FCFA (Écart {$rec['discrepancy']} FCFA)"
            );
            $pdo->commit();
            Helpers::json(['ok' => true, 'reconciliation' => $rec]);
        }

        // 11. REPORTS & EXPORTS (C++20 Analytics Engine + Excel XLS)
        if ($path === '/api/reports' || $path === '/api/reports/export') {
            $period = Helpers::str($_GET['period'] ?? 'month', 20);
            $from = Helpers::str($_GET['from'] ?? '', 20);
            $to = Helpers::str($_GET['to'] ?? '', 20);

            if ($period === 'day') {
                $from = date('Y-m-d');
                $to = date('Y-m-d');
            } elseif ($period === 'week') {
                $from = date('Y-m-d', strtotime('monday this week'));
                $to = date('Y-m-d', strtotime('sunday this week'));
            } elseif ($period === 'month') {
                $from = date('Y-m-01');
                $to = date('Y-m-t');
            } else {
                if ($from === '') $from = date('Y-m-01');
                if ($to === '') $to = date('Y-m-d');
            }

            $salesStmt = $pdo->prepare(
                "SELECT id, receipt_number, customer_name, payment_method, total_amount, discount_amount, status, DATE(created_at) AS day, created_at
                 FROM sales
                 WHERE archived_at IS NULL AND DATE(created_at) BETWEEN ? AND ?
                 ORDER BY created_at DESC"
            );
            $salesStmt->execute([$from, $to]);
            $sales = $salesStmt->fetchAll();

            $itemsStmt = $pdo->prepare(
                "SELECT si.product_name, si.quantity, si.unit_price, si.buy_price, si.line_total
                 FROM sale_items si
                 JOIN sales s ON s.id = si.sale_id
                 WHERE s.archived_at IS NULL AND s.status = 'COMPLETED' AND DATE(s.created_at) BETWEEN ? AND ?"
            );
            $itemsStmt->execute([$from, $to]);
            $saleItems = $itemsStmt->fetchAll();

            $expStmt = $pdo->prepare(
                "SELECT id, category, description, amount, payment_method, expense_date AS day
                 FROM expenses
                 WHERE deleted_at IS NULL AND expense_date BETWEEN ? AND ?
                 ORDER BY expense_date DESC"
            );
            $expStmt->execute([$from, $to]);
            $expenses = $expStmt->fetchAll();

            $analytics = CppEngine::run([
                'op' => 'compute_analytics',
                'sales' => $sales,
                'sale_items' => $saleItems,
                'expenses' => $expenses,
            ]);

            if ($path === '/api/reports/export') {
                $format = Helpers::str($_GET['format'] ?? 'excel', 20);
                if ($format === 'excel') {
                    $settings = Helpers::getSettings($pdo);
                    $store = htmlspecialchars($settings['store_name'] ?? 'FINCAISSE', ENT_QUOTES, 'UTF-8');
                    $filename = "FINCAISSE_Rapport_{$from}_{$to}.xls";

                    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                    header("Content-Disposition: attachment; filename=\"{$filename}\"");
                    header('Cache-Control: no-store');

                    echo "\xEF\xBB\xBF";
                    echo "<html xmlns:x=\"urn:schemas-microsoft-com:office:excel\"><head><meta charset=\"utf-8\">";
                    echo "<style>table{border-collapse:collapse;font-family:Calibri,Arial,sans-serif;}th,td{border:1px solid #cbd5e1;padding:6px 10px;}th{background:#0f172a;color:#ffffff;font-weight:bold;}h2{color:#0f172a;}</style></head><body>";
                    echo "<h2>{$store} — Rapport financier ({$from} au {$to})</h2>";
                    echo "<table><tr><th>Indicateur</th><th>Valeur</th></tr>";
                    echo "<tr><td>Chiffre d'affaires (Ventes)</td><td>{$analytics['total_revenue']} FCFA</td></tr>";
                    echo "<tr><td>Nombre de ventes validées</td><td>{$analytics['sales_count']}</td></tr>";
                    echo "<tr><td>Coût d'achat marchandises</td><td>{$analytics['total_cogs']} FCFA</td></tr>";
                    echo "<tr><td>Marge brute</td><td>{$analytics['gross_profit']} FCFA</td></tr>";
                    echo "<tr><td>Total des dépenses</td><td>{$analytics['total_expenses']} FCFA</td></tr>";
                    echo "<tr><td><b>Bénéfice net estimé</b></td><td><b>{$analytics['estimated_profit']} FCFA</b></td></tr>";
                    echo "<tr><td>Panier moyen</td><td>{$analytics['avg_basket']} FCFA</td></tr>";
                    echo "</table><br/>";

                    echo "<h3>Produits les plus vendus</h3><table><tr><th>Produit</th><th>Quantité</th><th>Chiffre d'affaires</th><th>Marge estimée</th></tr>";
                    foreach ($analytics['top_products'] as $tp) {
                        $pn = htmlspecialchars((string)$tp['name'], ENT_QUOTES, 'UTF-8');
                        echo "<tr><td>{$pn}</td><td>{$tp['quantity']}</td><td>{$tp['revenue']}</td><td>{$tp['profit']}</td></tr>";
                    }
                    echo "</table><br/>";

                    echo "<h3>Détail des ventes</h3><table><tr><th>N° Reçu</th><th>Date</th><th>Client</th><th>Paiement</th><th>Statut</th><th>Montant (FCFA)</th></tr>";
                    foreach ($sales as $s) {
                        $rn = htmlspecialchars((string)$s['receipt_number'], ENT_QUOTES, 'UTF-8');
                        $cn = htmlspecialchars((string)($s['customer_name'] ?: 'Client comptoir'), ENT_QUOTES, 'UTF-8');
                        $pm = htmlspecialchars((string)$s['payment_method'], ENT_QUOTES, 'UTF-8');
                        echo "<tr><td>{$rn}</td><td>{$s['created_at']}</td><td>{$cn}</td><td>{$pm}</td><td>{$s['status']}</td><td>{$s['total_amount']}</td></tr>";
                    }
                    echo "</table><br/>";

                    echo "<h3>Détail des dépenses</h3><table><tr><th>Date</th><th>Catégorie</th><th>Description</th><th>Paiement</th><th>Montant (FCFA)</th></tr>";
                    foreach ($expenses as $ex) {
                        $cat = htmlspecialchars((string)$ex['category'], ENT_QUOTES, 'UTF-8');
                        $desc = htmlspecialchars((string)$ex['description'], ENT_QUOTES, 'UTF-8');
                        $pm = htmlspecialchars((string)$ex['payment_method'], ENT_QUOTES, 'UTF-8');
                        echo "<tr><td>{$ex['day']}</td><td>{$cat}</td><td>{$desc}</td><td>{$pm}</td><td>{$ex['amount']}</td></tr>";
                    }
                    echo "</table></body></html>";
                    exit;
                }
            }

            Helpers::json([
                'ok' => true,
                'period' => $period,
                'from' => $from,
                'to' => $to,
                'analytics' => $analytics,
                'sales' => $sales,
                'expenses' => $expenses,
            ]);
        }

        // 12. AUDIT LOG
        if ($path === '/api/audit' && $method === 'GET') {
            $type = Helpers::str($_GET['entity_type'] ?? '', 64);
            $q = Helpers::str($_GET['q'] ?? '', 120);
            $sql = 'SELECT * FROM audit_logs WHERE 1=1';
            $params = [];
            if ($type !== '') {
                $sql .= ' AND entity_type = ?';
                $params[] = $type;
            }
            if ($q !== '') {
                $sql .= ' AND (details LIKE ? OR action LIKE ? OR actor LIKE ?)';
                $like = "%{$q}%";
                array_push($params, $like, $like, $like);
            }
            $sql .= ' ORDER BY id DESC LIMIT 250';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            Helpers::json(['ok' => true, 'logs' => $stmt->fetchAll()]);
        }

        // 13. SETTINGS & PAYMENT METHODS
        if ($path === '/api/settings') {
            if ($method === 'GET') {
                Helpers::json([
                    'ok' => true,
                    'settings' => Helpers::getSettings($pdo),
                    'payment_methods' => $pdo->query('SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC')->fetchAll(),
                ]);
            }
            if ($method === 'POST') {
                $b = Helpers::body();
                $allowed = [
                    'store_name', 'store_tagline', 'store_phone', 'store_address',
                    'currency', 'receipt_footer', 'cashier_can_view_history',
                    'retention_days', 'retention_policy',
                ];
                foreach ($allowed as $key) {
                    if (array_key_exists($key, $b)) {
                        $val = Helpers::str($b[$key], 500);
                        if ($key === 'retention_days') {
                            $val = (string)max(30, Helpers::intVal($val, 30));
                        }
                        if ($key === 'retention_policy' && !in_array($val, ['KEEP', 'ARCHIVE', 'DELETE'], true)) {
                            $val = 'ARCHIVE';
                        }
                        if ($key === 'cashier_can_view_history') {
                            $val = !empty($b[$key]) && $val !== '0' ? '1' : '0';
                        }
                        Helpers::setSetting($pdo, $key, $val);
                    }
                }
                Helpers::audit($pdo, 'SETTINGS', null, 'SETTINGS_UPDATED', 'Patron', 'Mise à jour des paramètres de l’application');
                Helpers::json(['ok' => true, 'settings' => Helpers::getSettings($pdo)]);
            }
        }

        if ($path === '/api/payment-methods') {
            $b = Helpers::body();
            if ($method === 'POST') {
                $label = Helpers::str($b['label'] ?? '', 100);
                if ($label === '') Helpers::error('Le libellé du mode de paiement est requis.');
                $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $label));
                $isCash = !empty($b['is_cash']) ? 1 : 0;
                $pdo->prepare(
                    'INSERT INTO payment_methods (code, label, is_cash, active, sort_order)
                     VALUES (?, ?, ?, 1, 20)
                     ON DUPLICATE KEY UPDATE label = VALUES(label), is_cash = VALUES(is_cash), active = 1'
                )->execute([$code, $label, $isCash]);
                Helpers::audit($pdo, 'SETTINGS', null, 'PAYMENT_METHOD_ADDED', 'Patron', "Mode de paiement ajouté : {$label}");
                Helpers::json(['ok' => true]);
            }
            if ($method === 'PUT') {
                $id = Helpers::intVal($b['id'] ?? 0);
                if ($id <= 0) Helpers::error('ID requis.');
                $active = !empty($b['active']) ? 1 : 0;
                $pdo->prepare('UPDATE payment_methods SET active = ? WHERE id = ?')->execute([$active, $id]);
                Helpers::json(['ok' => true]);
            }
            if ($method === 'DELETE') {
                $id = Helpers::intVal($_GET['id'] ?? $b['id'] ?? 0);
                if ($id <= 0) Helpers::error('ID requis.');
                $pdo->prepare('DELETE FROM payment_methods WHERE id = ? AND code <> "CASH"')->execute([$id]);
                Helpers::json(['ok' => true]);
            }
        }

        // 14. 30-DAY RETENTION, ARCHIVING & BACKUP (Configurable, never silent auto-delete)
        if ($path === '/api/retention/preview' && $method === 'GET') {
            $settings = Helpers::getSettings($pdo);
            $days = max(30, Helpers::intVal($settings['retention_days'] ?? 30, 30));
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            $stmtS = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE archived_at IS NULL AND created_at < ?');
            $stmtS->execute([$cutoff]);
            $eligibleSales = (int)$stmtS->fetchColumn();

            $stmtR = $pdo->prepare('SELECT COUNT(*) FROM receipts WHERE archived_at IS NULL AND created_at < ?');
            $stmtR->execute([$cutoff]);
            $eligibleReceipts = (int)$stmtR->fetchColumn();

            $archivedCount = (int)$pdo->query('SELECT COUNT(*) FROM sales WHERE archived_at IS NOT NULL')->fetchColumn();

            Helpers::json([
                'ok' => true,
                'retention_days' => $days,
                'retention_policy' => $settings['retention_policy'] ?? 'ARCHIVE',
                'last_backup_at' => $settings['last_backup_at'] ?? '',
                'cutoff_date' => $cutoff,
                'eligible_sales' => $eligibleSales,
                'eligible_receipts' => $eligibleReceipts,
                'already_archived_sales' => $archivedCount,
            ]);
        }

        if ($path === '/api/retention/backup' && $method === 'GET') {
            $backup = [
                'exported_at' => date('c'),
                'app' => 'FINCAISSE',
                'settings' => Helpers::getSettings($pdo),
                'categories' => $pdo->query('SELECT * FROM categories')->fetchAll(),
                'products' => $pdo->query('SELECT * FROM products')->fetchAll(),
                'customers' => $pdo->query('SELECT * FROM customers')->fetchAll(),
                'sales' => $pdo->query('SELECT * FROM sales')->fetchAll(),
                'sale_items' => $pdo->query('SELECT * FROM sale_items')->fetchAll(),
                'receipts' => $pdo->query('SELECT * FROM receipts')->fetchAll(),
                'expenses' => $pdo->query('SELECT * FROM expenses')->fetchAll(),
                'cash_sessions' => $pdo->query('SELECT * FROM cash_sessions')->fetchAll(),
                'cash_movements' => $pdo->query('SELECT * FROM cash_movements')->fetchAll(),
                'stock_movements' => $pdo->query('SELECT * FROM stock_movements')->fetchAll(),
                'audit_logs' => $pdo->query('SELECT * FROM audit_logs')->fetchAll(),
            ];
            Helpers::setSetting($pdo, 'last_backup_at', date('Y-m-d H:i:s'));
            Helpers::audit($pdo, 'SYSTEM', null, 'BACKUP_EXPORTED', 'Patron', 'Sauvegarde JSON complète téléchargée');

            $fname = 'FINCAISSE_Sauvegarde_' . date('Ymd_His') . '.json';
            header('Content-Type: application/json; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$fname}\"");
            echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($path === '/api/retention/apply' && $method === 'POST') {
            $b = Helpers::body();
            $action = Helpers::str($b['action'] ?? '', 20);
            $settings = Helpers::getSettings($pdo);
            $days = max(30, Helpers::intVal($settings['retention_days'] ?? 30, 30));
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            if ($action === 'KEEP') {
                Helpers::json(['ok' => true, 'affected' => 0, 'message' => 'Politique définie sur conservation durable. Aucune modification effectuée.']);
            }

            $pdo->beginTransaction();
            if ($action === 'ARCHIVE') {
                $s1 = $pdo->prepare('UPDATE sales SET archived_at = NOW() WHERE archived_at IS NULL AND created_at < ?');
                $s1->execute([$cutoff]);
                $affected = $s1->rowCount();
                $pdo->prepare('UPDATE receipts SET archived_at = NOW() WHERE archived_at IS NULL AND created_at < ?')->execute([$cutoff]);
                Helpers::audit($pdo, 'SYSTEM', null, 'RETENTION_ARCHIVED', 'Patron', "Archivage de {$affected} vente(s) antérieure(s) à {$days} jours");
                $pdo->commit();
                Helpers::json(['ok' => true, 'affected' => $affected, 'message' => "{$affected} vente(s) et reçus archivés avec succès."]);
            } elseif ($action === 'DELETE') {
                $sDel = $pdo->prepare('DELETE FROM sales WHERE created_at < ?');
                $sDel->execute([$cutoff]);
                $affected = $sDel->rowCount();
                Helpers::audit($pdo, 'SYSTEM', null, 'RETENTION_PURGED', 'Patron', "Suppression définitive de {$affected} vente(s) et reçus antérieurs à {$days} jours");
                $pdo->commit();
                Helpers::json(['ok' => true, 'affected' => $affected, 'message' => "{$affected} vente(s) et reçus supprimés définitivement."]);
            } else {
                $pdo->rollBack();
                Helpers::error('Action de conservation invalide (ARCHIVE ou DELETE attendu).');
            }
        }

        Helpers::error('Point de terminaison API introuvable : ' . $path, 404);
    } catch (Throwable $e) {
        Helpers::error('Erreur serveur : ' . $e->getMessage(), 500);
    }
}
