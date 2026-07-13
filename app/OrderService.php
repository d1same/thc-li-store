<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class OrderService
{
    public static function create(array $payload, ?array $user): array
    {
        if (!Store::setting('ordering_enabled', false) || Store::setting('store_status') !== 'open') {
            throw new RuntimeException('Online ordering is currently paused.');
        }

        $fulfillment = $payload['fulfillment'] ?? '';
        if (!in_array($fulfillment, ['pickup', 'delivery'], true)) {
            throw new RuntimeException('Choose pickup or delivery.');
        }
        if ($fulfillment === 'pickup' && !Store::setting('pickup_enabled', false)) {
            throw new RuntimeException('Pickup is not currently available.');
        }
        if ($fulfillment === 'delivery' && !Store::setting('delivery_enabled', false)) {
            throw new RuntimeException('Delivery is not currently available.');
        }

        $items = json_decode((string) ($payload['cart_json'] ?? ''), true);
        if (!is_array($items) || !$items) {
            throw new RuntimeException('Your cart is empty.');
        }

        $validated = [];
        $inventory = [];
        $subtotal = 0;
        $flowerGrams = 0.0;
        $concentrateGrams = 0.0;
        foreach ($items as $item) {
            $variantId = (int) ($item['variant_id'] ?? 0);
            $quantity = max(1, min(10, (int) ($item['quantity'] ?? 1)));
            $row = Database::one(
                "SELECT v.*, p.id product_id, p.name product_name, p.status product_status, p.potency, c.slug category_slug
                 FROM product_variants v JOIN products p ON p.id=v.product_id JOIN categories c ON c.id=p.category_id WHERE v.id=?",
                [$variantId]
            );
            if (!$row || $row['product_status'] !== 'active' || $row['stock_status'] === 'sold_out') {
                throw new RuntimeException('An item in your cart is no longer available.');
            }
            if ($row['stock_quantity'] !== null && $quantity > (int) $row['stock_quantity']) {
                throw new RuntimeException('Only ' . (int) $row['stock_quantity'] . ' of ' . $row['product_name'] . ' (' . $row['label'] . ') remain.');
            }
            $unit = (int) ($row['sale_price_cents'] ?: $row['price_cents']);
            $line = $unit * $quantity;
            $subtotal += $line;
            $validated[] = [
                'product_id' => (int) $row['product_id'], 'variant_id' => $variantId,
                'product_name' => $row['product_name'], 'variant_label' => $row['label'],
                'unit_price_cents' => $unit, 'quantity' => $quantity, 'line_total_cents' => $line,
            ];
            $inventory[] = [
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'tracked' => $row['stock_quantity'] !== null,
                'description' => $row['product_name'] . ' (' . $row['label'] . ')',
            ];
            $grams = self::grams((string) $row['label']);
            if ($row['category_slug'] === 'flower') {
                $flowerGrams += $grams * $quantity;
            } elseif (in_array($row['category_slug'], ['concentrates', 'vapes'], true)) {
                $concentrateGrams += $grams * $quantity;
            }
        }
        if ($flowerGrams > 85.0 || $concentrateGrams > 24.0) {
            throw new RuntimeException('This cart exceeds the permitted purchase quantity. Reduce the quantities and try again.');
        }

        $minimum = $fulfillment === 'delivery'
            ? (int) Store::setting('delivery_minimum_cents', 0)
            : (int) Store::setting('pickup_minimum_cents', 0);
        if ($subtotal < $minimum) {
            throw new RuntimeException('The ' . $fulfillment . ' minimum is ' . money($minimum) . '.');
        }

        $payment = (string) ($payload['payment_method'] ?? '');
        $allowed = $fulfillment === 'pickup' && Store::setting('pay_at_pickup_enabled', false)
            ? ['pay_at_pickup', 'manual_prepaid'] : ['manual_prepaid'];
        if (Store::setting('online_payment_enabled', false)) {
            $allowed[] = 'online';
        }
        if (!in_array($payment, $allowed, true)) {
            throw new RuntimeException('Choose an available payment method.');
        }
        if ($payment === 'manual_prepaid' && !Store::setting('manual_prepaid_enabled', false)) {
            throw new RuntimeException('Prepaid ordering is not configured.');
        }

        foreach (['customer_name', 'customer_email', 'customer_phone'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                throw new RuntimeException('Complete your contact information.');
            }
        }
        if (!filter_var($payload['customer_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if ($fulfillment === 'delivery') {
            foreach (['address1', 'city', 'postal_code'] as $field) {
                if (trim((string) ($payload[$field] ?? '')) === '') {
                    throw new RuntimeException('Complete the delivery address.');
                }
            }
            if (strtoupper(trim((string) ($payload['state'] ?? 'NY'))) !== 'NY') {
                throw new RuntimeException('Delivery is currently available only within New York.');
            }
        }
        if (($payload['age_attestation'] ?? '') !== '1') {
            throw new RuntimeException('You must confirm that you are 21 or older.');
        }

        $fee = $fulfillment === 'delivery' ? (int) Store::setting('delivery_fee_cents', 0) : 0;
        $tax = 0;
        $total = $subtotal + $fee + $tax;
        $number = 'LI-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            self::reserveInventory($pdo, $inventory);
            $customerId = CustomerService::capture([
                'user_id' => $user['id'] ?? null,
                'name' => $payload['customer_name'],
                'email' => $payload['customer_email'],
                'phone' => $payload['customer_phone'],
                'address1' => $payload['address1'] ?? '',
                'address2' => $payload['address2'] ?? '',
                'city' => $payload['city'] ?? '',
                'state' => $payload['state'] ?? 'NY',
                'postal_code' => $payload['postal_code'] ?? '',
                'marketing_opt_in' => $payload['marketing_opt_in'] ?? 0,
            ], $pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO orders (order_number,user_id,customer_id,status,fulfillment,payment_method,payment_status,subtotal_cents,fee_cents,tax_cents,total_cents,customer_name,customer_email,customer_phone,address1,address2,city,state,postal_code,requested_time,customer_notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $number, $user['id'] ?? null, $customerId, 'awaiting_confirmation', $fulfillment, $payment,
                $payment === 'pay_at_pickup' ? 'due' : 'pending', $subtotal, $fee, $tax, $total,
                trim($payload['customer_name']), strtolower(trim($payload['customer_email'])), trim($payload['customer_phone']),
                trim((string) ($payload['address1'] ?? '')), trim((string) ($payload['address2'] ?? '')),
                trim((string) ($payload['city'] ?? '')), 'NY', trim((string) ($payload['postal_code'] ?? '')),
                trim((string) ($payload['requested_time'] ?? '')), trim((string) ($payload['customer_notes'] ?? '')),
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,variant_id,product_name,variant_label,unit_price_cents,quantity,line_total_cents) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($validated as $item) {
                $itemStmt->execute([$orderId, ...array_values($item)]);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        if ($user && $fulfillment === 'delivery' && !empty($payload['save_address'])) {
            Database::execute(
                'INSERT INTO addresses (user_id,label,address1,address2,city,state,postal_code) VALUES (?,?,?,?,?,?,?)',
                [$user['id'], 'Delivery', trim($payload['address1']), trim((string) ($payload['address2'] ?? '')), trim($payload['city']), 'NY', trim($payload['postal_code'])]
            );
        }
        Notification::orderReceived($orderId);
        return ['id' => $orderId, 'number' => $number, 'total_cents' => $total];
    }

    public static function createPos(array $payload, array $staff): array
    {
        if (!Store::setting('pos_enabled', true)) {
            throw new RuntimeException('The POS register is currently disabled in settings.');
        }
        if (($payload['age_verified'] ?? '') !== '1') {
            throw new RuntimeException('Confirm that the customer identification and age were checked.');
        }

        $payment = (string) ($payload['payment_method'] ?? '');
        $allowedPayments = [];
        if (Store::setting('pos_cash_enabled', true)) {
            $allowedPayments[] = 'cash';
        }
        if (Store::setting('pos_external_card_enabled', true)) {
            $allowedPayments[] = 'external_card';
        }
        if (!in_array($payment, $allowedPayments, true)) {
            throw new RuntimeException('Choose an enabled in-store payment method.');
        }

        $items = json_decode((string) ($payload['cart_json'] ?? ''), true);
        if (!is_array($items) || !$items) {
            throw new RuntimeException('Add at least one product to the POS cart.');
        }

        $validated = [];
        $inventory = [];
        $subtotal = 0;
        $flowerGrams = 0.0;
        $concentrateGrams = 0.0;
        foreach ($items as $item) {
            $variantId = (int) ($item['variant_id'] ?? 0);
            $quantity = max(1, min(10, (int) ($item['quantity'] ?? 1)));
            $row = Database::one(
                "SELECT v.*,p.id product_id,p.name product_name,p.status product_status,p.potency,c.slug category_slug
                 FROM product_variants v JOIN products p ON p.id=v.product_id JOIN categories c ON c.id=p.category_id WHERE v.id=?",
                [$variantId]
            );
            if (!$row || $row['product_status'] !== 'active' || $row['stock_status'] === 'sold_out') {
                throw new RuntimeException('An item in the POS cart is no longer available.');
            }
            if ($row['stock_quantity'] !== null && $quantity > (int) $row['stock_quantity']) {
                throw new RuntimeException('Only ' . (int) $row['stock_quantity'] . ' of ' . $row['product_name'] . ' (' . $row['label'] . ') remain.');
            }
            $unit = (int) ($row['sale_price_cents'] ?: $row['price_cents']);
            $line = $unit * $quantity;
            $subtotal += $line;
            $validated[] = [
                'product_id' => (int) $row['product_id'],
                'variant_id' => $variantId,
                'product_name' => $row['product_name'],
                'variant_label' => $row['label'],
                'unit_price_cents' => $unit,
                'quantity' => $quantity,
                'line_total_cents' => $line,
            ];
            $inventory[] = [
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'tracked' => $row['stock_quantity'] !== null,
                'description' => $row['product_name'] . ' (' . $row['label'] . ')',
            ];
            $grams = self::grams((string) $row['label']);
            if ($row['category_slug'] === 'flower') {
                $flowerGrams += $grams * $quantity;
            } elseif (in_array($row['category_slug'], ['concentrates', 'vapes'], true)) {
                $concentrateGrams += $grams * $quantity;
            }
        }
        if ($flowerGrams > 85.0 || $concentrateGrams > 24.0) {
            throw new RuntimeException('This sale exceeds the configured purchase quantity limit.');
        }

        $discountPercent = max(0.0, (float) ($payload['discount_percent'] ?? 0));
        if ($discountPercent > 0 && !Store::setting('pos_manual_discount_enabled', false)) {
            throw new RuntimeException('Manual POS discounts are disabled.');
        }
        if ($discountPercent > 0 && !Auth::can('pos.discount', $staff)) {
            throw new RuntimeException('Your staff account cannot apply manual discounts.');
        }
        $maximumDiscount = max(0, min(100, (int) Store::setting('pos_max_discount_percent', 20)));
        if ($discountPercent > $maximumDiscount) {
            throw new RuntimeException('The manual discount cannot exceed ' . $maximumDiscount . '%.');
        }
        $discount = min($subtotal, (int) round($subtotal * ($discountPercent / 100)));
        $taxRate = Store::setting('pos_tax_enabled', false)
            ? max(0.0, min(30.0, (float) Store::setting('pos_tax_rate', '0')))
            : 0.0;
        $tax = (int) round(($subtotal - $discount) * ($taxRate / 100));
        $total = $subtotal - $discount + $tax;

        $skipCustomer = ($payload['skip_customer'] ?? '') === '1';
        $customerName = $skipCustomer ? 'Walk-in customer' : trim((string) ($payload['customer_name'] ?? ''));
        $customerEmail = $skipCustomer ? '' : strtolower(trim((string) ($payload['customer_email'] ?? '')));
        $customerPhone = $skipCustomer ? '' : trim((string) ($payload['customer_phone'] ?? ''));
        if (!$skipCustomer && ($customerName === '' || ($customerEmail === '' && CustomerService::phoneKey($customerPhone) === null))) {
            throw new RuntimeException('Add the customer name and either a phone number or email, or choose anonymous walk-in.');
        }
        if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid receipt email or leave it blank.');
        }
        $customer = $customerEmail === '' ? null : Database::one(
            "SELECT id FROM users WHERE email=? COLLATE NOCASE AND role='customer' AND status='active'",
            [$customerEmail]
        );

        $number = 'POS-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            self::reserveInventory($pdo, $inventory);
            $customerId = $skipCustomer ? null : CustomerService::capture([
                'customer_id' => Auth::can('customers.view', $staff) ? ($payload['customer_id'] ?? null) : null,
                'user_id' => $customer['id'] ?? null,
                'name' => $customerName,
                'email' => $customerEmail,
                'phone' => $customerPhone,
                'marketing_opt_in' => $payload['marketing_opt_in'] ?? 0,
            ], $pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO orders (order_number,user_id,customer_id,status,fulfillment,payment_method,payment_status,subtotal_cents,discount_cents,fee_cents,tax_cents,total_cents,customer_name,customer_email,customer_phone,requested_time,customer_notes,staff_notes,order_source,created_by_user_id,age_verified,discount_label) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $number, $customer['id'] ?? null, $customerId, 'completed', 'pickup', $payment, 'paid',
                $subtotal, $discount, 0, $tax, $total, $customerName, $customerEmail, $customerPhone,
                'In store', trim((string) ($payload['customer_notes'] ?? '')), 'Completed at POS',
                'pos', (int) $staff['id'], 1,
                $discount > 0 ? rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.') . '% staff discount' : null,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,variant_id,product_name,variant_label,unit_price_cents,quantity,line_total_cents) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($validated as $item) {
                $itemStmt->execute([$orderId, ...array_values($item)]);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        if ($customerEmail !== '' && Store::setting('pos_email_receipt_enabled', true) && Notification::posReceipt($orderId)) {
            Database::execute('UPDATE orders SET receipt_email_sent=1 WHERE id=?', [$orderId]);
        }
        return ['id' => $orderId, 'number' => $number, 'total_cents' => $total];
    }

    public static function releaseInventory(int $orderId): bool
    {
        $pdo = Database::pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $orderStmt = $pdo->prepare('SELECT status, inventory_released FROM orders WHERE id=?');
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            if (!$order) {
                throw new RuntimeException('Order not found.');
            }
            if ($order['status'] !== 'cancelled') {
                throw new RuntimeException('Inventory can only be restored for a cancelled order.');
            }
            if ((int) $order['inventory_released'] === 1) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return false;
            }

            $itemStmt = $pdo->prepare(
                'SELECT variant_id, SUM(quantity) quantity FROM order_items WHERE order_id=? AND variant_id IS NOT NULL GROUP BY variant_id'
            );
            $itemStmt->execute([$orderId]);
            $restoreStmt = $pdo->prepare(
                "UPDATE product_variants
                 SET stock_quantity=stock_quantity+?,
                     stock_status=CASE WHEN stock_quantity+? <= 5 THEN 'low_stock' ELSE 'in_stock' END
                 WHERE id=? AND stock_quantity IS NOT NULL"
            );
            foreach ($itemStmt->fetchAll() as $item) {
                $quantity = (int) $item['quantity'];
                $restoreStmt->execute([$quantity, $quantity, (int) $item['variant_id']]);
            }
            $markStmt = $pdo->prepare('UPDATE orders SET inventory_released=1 WHERE id=? AND inventory_released=0');
            $markStmt->execute([$orderId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return true;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private static function reserveInventory(PDO $pdo, array $inventory): void
    {
        $stmt = $pdo->prepare(
            "UPDATE product_variants
             SET stock_quantity=stock_quantity-?,
                 stock_status=CASE
                     WHEN stock_quantity-? <= 0 THEN 'sold_out'
                     WHEN stock_quantity-? <= 5 THEN 'low_stock'
                     ELSE 'in_stock'
                 END
             WHERE id=? AND stock_quantity IS NOT NULL AND stock_quantity>=? AND stock_status!='sold_out'"
        );
        foreach ($inventory as $item) {
            if (!$item['tracked']) {
                continue;
            }
            $quantity = (int) $item['quantity'];
            $stmt->execute([$quantity, $quantity, $quantity, (int) $item['variant_id'], $quantity]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException($item['description'] . ' no longer has enough stock. Refresh your cart and try again.');
            }
        }
    }

    private static function grams(string $label): float
    {
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*oz/i', $label, $match)) {
            return (float) $match[1] * 28.35;
        }
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*g\b/i', $label, $match)) {
            return (float) $match[1];
        }
        return 0.0;
    }
}
