<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function boot(): void
    {
        if (self::$pdo) {
            return;
        }
        $configured = getenv('DB_PATH') ?: APP_ROOT . '/storage/shop.sqlite';
        $path = str_starts_with($configured, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured)
            ? $configured
            : APP_ROOT . '/' . ltrim($configured, './');
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        @chmod($directory, 0700);
        self::$pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo->exec('PRAGMA busy_timeout = 5000');
        @chmod($path, 0600);
        @chmod($path . '-wal', 0600);
        @chmod($path . '-shm', 0600);
    }

    public static function pdo(): PDO
    {
        self::boot();
        return self::$pdo;
    }

    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function execute(string $sql, array $params = []): bool
    {
        $stmt = self::pdo()->prepare($sql);
        return $stmt->execute($params);
    }

    public static function executeAffected(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
