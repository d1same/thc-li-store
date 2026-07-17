<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;

$directory = APP_ROOT . '/storage/backups';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create backup directory.');
}
@chmod($directory, 0700);

$target = $directory . '/shop-' . date('Y-m-d-His') . '.sqlite';
$escaped = str_replace("'", "''", $target);
Database::pdo()->exec("VACUUM INTO '{$escaped}'");
@chmod($target, 0600);

$files = glob($directory . '/shop-*.sqlite') ?: [];
rsort($files, SORT_STRING);
foreach (array_slice($files, 35) as $old) {
    if (is_file($old)) {
        unlink($old);
    }
}

echo 'Backup created: ' . basename($target) . PHP_EOL;
