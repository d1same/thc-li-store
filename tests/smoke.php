<?php
declare(strict_types=1);

if (getenv('DB_PATH') === false || !str_contains((string) getenv('DB_PATH'), 'test.sqlite')) {
    fwrite(STDERR, "Refusing to run without an isolated test SQLite database.\n");
    exit(2);
}

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Auth;
use App\CustomerService;
use App\OrderService;
use App\ReportingService;
use App\Seed;
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

// Inventory lifecycle: reserve on order, auto-mark low stock, restore once on cancellation.
$pdo->exec("DELETE FROM products WHERE slug='inventory-lifecycle-test'");
$categoryId = (int) $pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$productStmt = $pdo->prepare("INSERT INTO products (category_id,name,brand,slug,description,status) VALUES (?,?,?,?,?,'active')");
$productStmt->execute([$categoryId, 'Inventory Test Product', 'Test Brand', 'inventory-lifecycle-test', 'Automated inventory test product.']);
$inventoryProductId = (int) $pdo->lastInsertId();
$variantStmt = $pdo->prepare("INSERT INTO product_variants (product_id,label,price_cents,stock_status,stock_quantity) VALUES (?,?,?,'in_stock',6)");
$variantStmt->execute([$inventoryProductId, 'Test option', 1000]);
$inventoryVariantId = (int) $pdo->lastInsertId();
$inventoryOrder = OrderService::create([
    'cart_json' => json_encode([['variant_id' => $inventoryVariantId, 'quantity' => 2]]),
    'fulfillment' => 'pickup',
    'payment_method' => 'pay_at_pickup',
    'customer_name' => 'Inventory Tester',
    'customer_email' => 'inventory@example.test',
    'customer_phone' => '6315550199',
    'age_attestation' => '1',
], null);
$reservedVariant = Database::one('SELECT stock_quantity,stock_status FROM product_variants WHERE id=?', [$inventoryVariantId]);
Database::execute("UPDATE orders SET status='cancelled' WHERE id=?", [$inventoryOrder['id']]);
$releasedFirst = OrderService::releaseInventory((int) $inventoryOrder['id']);
$restoredVariant = Database::one('SELECT stock_quantity,stock_status FROM product_variants WHERE id=?', [$inventoryVariantId]);
$releasedSecond = OrderService::releaseInventory((int) $inventoryOrder['id']);
$restoredAgain = Database::one('SELECT stock_quantity FROM product_variants WHERE id=?', [$inventoryVariantId]);
Database::execute("UPDATE product_variants SET stock_quantity=1,stock_status='low_stock' WHERE id=?", [$inventoryVariantId]);
$oversellBlocked = false;
try {
    OrderService::create([
        'cart_json' => json_encode([['variant_id' => $inventoryVariantId, 'quantity' => 2]]),
        'fulfillment' => 'pickup',
        'payment_method' => 'pay_at_pickup',
        'customer_name' => 'Inventory Tester',
        'customer_email' => 'inventory@example.test',
        'customer_phone' => '6315550199',
        'age_attestation' => '1',
    ], null);
} catch (RuntimeException) {
    $oversellBlocked = true;
}
$afterOversell = Database::one('SELECT stock_quantity FROM product_variants WHERE id=?', [$inventoryVariantId]);

// POS lifecycle: granular staff permission, anonymous sale, tax snapshot and cancellable stock reservation.
$staffId = Auth::register('POS Cashier', 'pos-cashier@example.test', '6315550120', 'TestingOnly123!', 'staff');
Auth::syncPermissions($staffId, ['pos.access','pos.complete','orders.view','products.view']);
$posStaff = Database::one('SELECT * FROM users WHERE id=?', [$staffId]);
Store::setSetting('pos_enabled', true, 'bool');
Store::setSetting('pos_cash_enabled', true, 'bool');
Store::setSetting('pos_external_card_enabled', true, 'bool');
Store::setSetting('pos_tax_enabled', true, 'bool');
Store::setSetting('pos_tax_rate', '8.875');
Store::setSetting('ordering_enabled', false, 'bool');
$productStmt->execute([$categoryId, 'POS Test Product', 'Test Brand', 'pos-sale-test', 'Automated POS test product.']);
$posProductId = (int) $pdo->lastInsertId();
$variantStmt->execute([$posProductId, 'Single', 1000]);
$posVariantId = (int) $pdo->lastInsertId();
Database::execute("UPDATE product_variants SET stock_quantity=8,stock_status='in_stock' WHERE id=?", [$posVariantId]);
$posOrderResult = OrderService::createPos([
    'cart_json' => json_encode([['variant_id' => $posVariantId, 'quantity' => 2]]),
    'payment_method' => 'cash',
    'customer_name' => '',
    'customer_email' => '',
    'customer_phone' => '',
    'skip_customer' => '1',
    'age_verified' => '1',
], $posStaff);
$posOrder = Database::one('SELECT * FROM orders WHERE id=?', [$posOrderResult['id']]);
$posReserved = Database::one('SELECT stock_quantity FROM product_variants WHERE id=?', [$posVariantId]);
Database::execute("UPDATE orders SET status='cancelled' WHERE id=?", [$posOrderResult['id']]);
$posReleased = OrderService::releaseInventory((int) $posOrderResult['id']);
$posRestored = Database::one('SELECT stock_quantity FROM product_variants WHERE id=?', [$posVariantId]);
Auth::syncPermissions($staffId, ['pos.access','pos.complete','pos.discount','orders.view','products.view']);
Store::setSetting('pos_manual_discount_enabled', true, 'bool');
Store::setSetting('pos_max_discount_percent', 20, 'int');
$discountedPosResult = OrderService::createPos([
    'cart_json' => json_encode([['variant_id' => $posVariantId, 'quantity' => 1]]),
    'payment_method' => 'external_card',
    'discount_percent' => '10',
    'customer_name' => 'Receipt Customer',
    'customer_email' => 'receipt@example.test',
    'customer_phone' => '6315550166',
    'marketing_opt_in' => '1',
    'age_verified' => '1',
], $posStaff);
$discountedPosOrder = Database::one('SELECT * FROM orders WHERE id=?', [$discountedPosResult['id']]);
$receiptCustomer = Database::one('SELECT * FROM customer_profiles WHERE id=?', [(int) $discountedPosOrder['customer_id']]);
CustomerService::capture([
    'customer_id' => (int) $discountedPosOrder['customer_id'],
    'name' => 'Receipt Customer',
    'email' => 'owner@example.test',
    'phone' => '6315550100',
]);
$receiptAfterConflict = Database::one('SELECT * FROM customer_profiles WHERE id=?', [(int) $discountedPosOrder['customer_id']]);
CustomerService::syncDirectory();
$salesReport = ReportingService::report(['range' => '30d']);
$imageRows = Database::all("SELECT image_path FROM products WHERE image_path IS NOT NULL AND image_path != ''");
$imageFiles = array_filter($imageRows, static fn(array $row): bool => is_file(APP_ROOT . '/public/' . $row['image_path']));
$initialPromotionCount = (int) $pdo->query('SELECT COUNT(*) FROM promotions')->fetchColumn();
if ($initialPromotionCount === 0) {
    $pdo->exec("INSERT INTO promotions (title,description,active,position) VALUES ('Promotion deletion regression','Test fixture',1,999)");
    $initialPromotionCount = 1;
}
$promotionSeedInitialized = (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key='seed_promotions_initialized'")->fetchColumn() === 1;
$pdo->exec('DELETE FROM promotions');
Seed::run();
$promotionsRemainDeleted = (int) $pdo->query('SELECT COUNT(*) FROM promotions')->fetchColumn() === 0;

$checks = [
    'products seeded' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() >= 40,
    'variants seeded' => (int) $pdo->query('SELECT COUNT(*) FROM product_variants')->fetchColumn() >= 40,
    'categories seeded' => (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 5,
    'owner setup works' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='owner'")->fetchColumn() === 1,
    'checkout created order' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() >= 1,
    'order snapshot exists' => (int) $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn() >= 1,
    'tracked stock reserved' => (int) $reservedVariant['stock_quantity'] === 4 && $reservedVariant['stock_status'] === 'low_stock',
    'cancelled stock restored' => $releasedFirst && (int) $restoredVariant['stock_quantity'] === 6 && $restoredVariant['stock_status'] === 'in_stock',
    'stock restored exactly once' => !$releasedSecond && (int) $restoredAgain['stock_quantity'] === 6,
    'overselling blocked' => $oversellBlocked && (int) $afterOversell['stock_quantity'] === 1,
    'POS migration applied' => in_array('order_source', array_column(Database::all('PRAGMA table_info(orders)'), 'name'), true),
    'customer reporting migration applied' => in_array('customer_id', array_column(Database::all('PRAGMA table_info(orders)'), 'name'), true),
    'staff POS permission enforced' => Auth::can('pos.access', $posStaff) && !Auth::can('products.edit', $posStaff),
    'POS works while online ordering paused' => $posOrder !== null && $posOrder['order_source'] === 'pos',
    'POS anonymous cash sale completed' => $posOrder['status'] === 'completed' && $posOrder['payment_status'] === 'paid' && $posOrder['payment_method'] === 'cash' && $posOrder['customer_name'] === 'Walk-in customer',
    'POS tax snapshot calculated' => (int) $posOrder['subtotal_cents'] === 2000 && (int) $posOrder['tax_cents'] === 178 && (int) $posOrder['total_cents'] === 2178,
    'POS stock reserved and void restored' => (int) $posReserved['stock_quantity'] === 6 && $posReleased && (int) $posRestored['stock_quantity'] === 8,
    'POS external terminal and discount calculated' => $discountedPosOrder['payment_method'] === 'external_card' && (int) $discountedPosOrder['discount_cents'] === 100 && (int) $discountedPosOrder['tax_cents'] === 80 && (int) $discountedPosOrder['total_cents'] === 980,
    'identified POS sale linked to customer' => (int) $discountedPosOrder['customer_id'] > 0 && $receiptCustomer !== null && $receiptCustomer['email_key'] === 'receipt@example.test',
    'marketing consent preserved' => (int) $receiptCustomer['marketing_opt_in'] === 1,
    'conflicting contact data cannot overwrite another customer' => $receiptAfterConflict['email'] === 'receipt@example.test' && $receiptAfterConflict['phone'] === '6315550166',
    'anonymous POS sale stays out of client list' => $posOrder['customer_id'] === null,
    'sales report aggregates paid history' => (int) $salesReport['kpis']['orders'] >= 1 && (int) $salesReport['kpis']['revenue'] >= 980 && $salesReport['top_products'] !== [],
    'pickup enabled' => Store::setting('pickup_enabled') === true,
    'online payment safely disabled' => Store::setting('online_payment_enabled') === false,
    'seed images available' => count($imageFiles) >= 40,
    'promotion deletion fixture available' => $initialPromotionCount >= 1,
    'promotion seed initialized' => $promotionSeedInitialized,
    'deleted promotions stay deleted' => $promotionsRemainDeleted,
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
}
if ($failed) {
    exit(1);
}
echo 'Smoke suite passed.' . PHP_EOL;
