<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Schema.php';

final class Database
{
    private static ?PDO $pdo = null;

    public static function getConfig(): array
    {
        $url = getenv('FINCAISSE_DB_URL') ?: getenv('MYSQL_URL') ?: getenv('DATABASE_URL') ?: '';
        if ($url !== '' && str_starts_with($url, 'mysql://')) {
            $parts = parse_url($url);
            if (is_array($parts)) {
                return [
                    'host' => $parts['host'] ?? '127.0.0.1',
                    'port' => (string)($parts['port'] ?? 3306),
                    'name' => ltrim((string)($parts['path'] ?? 'fincaisse'), '/'),
                    'user' => urldecode((string)($parts['user'] ?? 'root')),
                    'pass' => urldecode((string)($parts['pass'] ?? '')),
                    'socket' => '',
                    'ssl_ca' => getenv('FINCAISSE_DB_SSL_CA') ?: '',
                ];
            }
        }

        $mampSocket = '/Applications/MAMP/tmp/mysql/mysql.sock';
        $hasMamp = file_exists($mampSocket);

        $host = getenv('FINCAISSE_DB_HOST') ?: getenv('MYSQLHOST') ?: '127.0.0.1';
        $port = getenv('FINCAISSE_DB_PORT') ?: getenv('MYSQLPORT') ?: ($hasMamp ? '8889' : '3306');
        $name = getenv('FINCAISSE_DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'fincaisse';
        $user = getenv('FINCAISSE_DB_USER') ?: getenv('MYSQLUSER') ?: 'root';
        $passEnv = getenv('FINCAISSE_DB_PASS');
        if ($passEnv === false) {
            $passEnv = getenv('MYSQLPASSWORD');
        }
        $pass = $passEnv !== false ? $passEnv : ($hasMamp ? 'root' : '');
        $socket = getenv('FINCAISSE_DB_SOCKET') ?: ($hasMamp ? $mampSocket : '');

        return [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
            'socket' => $socket,
            'ssl_ca' => getenv('FINCAISSE_DB_SSL_CA') ?: '',
        ];
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = self::getConfig();
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (!empty($cfg['ssl_ca']) && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $cfg['ssl_ca'];
        }

        $dbName = preg_replace('/[^a-zA-Z0-9_-]/', '', $cfg['name']) ?: 'fincaisse';
        $dsn = !empty($cfg['socket'])
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $cfg['socket'], $dbName)
            : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $dbName);

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
        } catch (PDOException $e) {
            // Auto-create database locally if it does not exist yet (MySQL error 1049)
            if (str_contains($e->getMessage(), '1049') || str_contains($e->getMessage(), 'Unknown database')) {
                $baseDsn = !empty($cfg['socket'])
                    ? sprintf('mysql:unix_socket=%s;charset=utf8mb4', $cfg['socket'])
                    : sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['host'], $cfg['port']);
                $bootstrap = new PDO($baseDsn, $cfg['user'], $cfg['pass'], $options);
                $bootstrap->exec(
                    "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                );
                self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
            } else {
                throw $e;
            }
        }

        self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        self::$pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        Schema::ensureInitialized(self::$pdo);
        return self::$pdo;
    }
}
