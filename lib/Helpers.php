<?php
declare(strict_types=1);

final class Helpers
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(array_merge(['ok' => false, 'error' => $message], $extra), $status);
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::error('Corps JSON invalide', 400);
        }
        return $decoded;
    }

    public static function str(mixed $val, int $maxLen = 255): string
    {
        $s = trim((string)($val ?? ''));
        if (mb_strlen($s, 'UTF-8') > $maxLen) {
            $s = mb_substr($s, 0, $maxLen, 'UTF-8');
        }
        return $s;
    }

    public static function intVal(mixed $val, int $default = 0): int
    {
        if ($val === null || $val === '') {
            return $default;
        }
        return (int)round((float)$val);
    }

    public static function audit(
        PDO $pdo,
        string $entityType,
        ?int $entityId,
        string $action,
        string $actor,
        string $details,
        array $metadata = []
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (entity_type, entity_id, action, actor, details, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $entityType,
            $entityId,
            $action,
            $actor !== '' ? $actor : 'Système',
            $details,
            empty($metadata) ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public static function getSettings(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT key_name, value_text FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['key_name']] = $row['value_text'];
        }
        return $out;
    }

    public static function setSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (key_name, value_text) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value_text = VALUES(value_text)'
        );
        $stmt->execute([$key, $value]);
    }

    public static function nextReceiptNumber(PDO $pdo): string
    {
        $prefix = 'FC-' . date('Ymd') . '-';
        $stmt = $pdo->prepare(
            "SELECT receipt_number FROM sales WHERE receipt_number LIKE ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $seq = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
            $seq = ((int)$m[1]) + 1;
        }
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    public static function nextOrderNumber(PDO $pdo): string
    {
        $prefix = 'CMD-' . date('Ymd') . '-';
        $stmt = $pdo->prepare(
            "SELECT order_number FROM orders WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $seq = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
            $seq = ((int)$m[1]) + 1;
        }
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algo);
        if ($hash === false) {
            throw new RuntimeException('Impossible de sécuriser le mot de passe.');
        }
        return $hash;
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function createSession(PDO $pdo, int $userId): string
    {
        $pdo->exec('DELETE FROM user_sessions WHERE expires_at <= NOW()');
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);

        $pdo->prepare('INSERT INTO user_sessions (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
            ->execute([$userId, $tokenHash, $expiresAt]);

        setcookie('fincaisse_session', $rawToken, [
            'expires' => time() + 86400,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return $rawToken;
    }

    public static function clearSession(PDO $pdo): void
    {
        $rawToken = self::extractToken();
        if ($rawToken !== '') {
            $pdo->prepare('DELETE FROM user_sessions WHERE token_hash = ?')
                ->execute([hash('sha256', $rawToken)]);
        }
        setcookie('fincaisse_session', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function extractToken(): string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($auth, 'Bearer ')) {
            return trim(substr($auth, 7));
        }
        return trim((string)($_COOKIE['fincaisse_session'] ?? ''));
    }

    public static function currentUser(PDO $pdo): ?array
    {
        $rawToken = self::extractToken();
        if ($rawToken === '') {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT u.id, u.username, u.full_name, u.role, u.active
             FROM user_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > NOW() AND u.active = 1
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $rawToken)]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function requireRole(PDO $pdo, array $roles): array
    {
        $user = self::currentUser($pdo);
        if (!$user) {
            self::error('Connexion requise.', 401);
        }
        if (!in_array($user['role'], $roles, true)) {
            self::error('Accès refusé pour votre rôle (' . $user['role'] . ').', 403);
        }
        return $user;
    }

    public static function actorName(array $user): string
    {
        $full = trim((string)($user['full_name'] ?? ''));
        return $full !== '' ? $full : (string)$user['username'];
    }
}
