<?php
declare(strict_types=1);

if (getenv('DB_PATH') === false || !str_contains((string) getenv('DB_PATH'), 'test.sqlite')) {
    fwrite(STDERR, "Refusing to run without an isolated test SQLite database.\n");
    exit(2);
}

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Auth;
use App\OrderService;
use App\Store;

$pdo = Database::pdo();
if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='owner'")->fetchColumn() === 0) {
    $ownerId = Auth::register('Test Owner', 'owner@example.test', '6315550100', 'TestingOnly123!', 'owner');
} else {
    $ownerId = (int) $pdo->query("SELECT id FROM users WHERE role='owner' LIMIT 1")->fetchColumn();
}
if ((int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 0) {
    OrderService::create([
        'cart_json' => json_encode([['variant_id' => 1, 'quantity' => 3]]),
        'fulfillment' => 'pickup',
        'payment_method' => 'pay_at_pickup',
        'customer_name' => 'Test Owner',
        'customer_email' => 'owner@example.test',
        'customer_phone' => '6315550100',
        'age_attestation' => '1',
    ], Database::one('SELECT * FROM users WHERE id=?', [$ownerId]));
}
$imageRows = Database::all("SELECT image_path FROM products WHERE image_path IS NOT NULL AND image_path != ''");
$imageFiles = array_filter($imageRows, static fn(array $row): bool => is_file(APP_ROOT . '/public/' . $row['image_path']));

$checks = [
    'products seeded' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() >= 40,
    'variants seeded' => (int) $pdo->query('SELECT COUNT(*) FROM product_variants')->fetchColumn() >= 40,
    'categories seeded' => (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 5,
    'owner setup works' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='owner'")->fetchColumn() === 1,
    'checkout created order' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() >= 1,
    'order snapshot exists' => (int) $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn() >= 1,
    'pickup enabled' => Store::setting('pickup_enabled') === true,
    'online payment safely disabled' => Store::setting('online_payment_enabled') === false,
    'seed images available' => count($imageFiles) >= 40,
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
}
if ($failed) {
    exit(1);
}
echo 'Smoke suite passed.' . PHP_EOL;
