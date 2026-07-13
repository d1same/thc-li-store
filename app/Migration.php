<?php
declare(strict_types=1);

namespace App;

final class Migration
{
    public static function run(): void
    {
        $pdo = Database::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)');
        $current = (int) ($pdo->query('SELECT COALESCE(MAX(version), 0) FROM migrations')->fetchColumn() ?: 0);
        $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        foreach ($files as $file) {
            $version = (int) basename($file);
            if ($version <= $current) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec((string) file_get_contents($file));
                $stmt = $pdo->prepare('INSERT INTO migrations (version, applied_at) VALUES (?, ?)');
                $stmt->execute([$version, gmdate('c')]);
                $pdo->commit();
            } catch (\Throwable $error) {
                $pdo->rollBack();
                throw $error;
            }
        }
        Seed::run();
    }
}

