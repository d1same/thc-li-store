<?php
declare(strict_types=1);

namespace App;

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
            $unit = (int) ($row['sale_price_cents'] ?: $row['price_cents']);
            $line = $unit * $quantity;
            $subtotal += $line;
            $validated[] = [
                'product_id' => (int) $row['product_id'], 'variant_id' => $variantId,
                'product_name' => $row['product_name'], 'variant_label' => $row['label'],
                'unit_price_cents' => $unit, 'quantity' => $quantity, 'line_total_cents' => $line,
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
            $stmt = $pdo->prepare(
                'INSERT INTO orders (order_number,user_id,status,fulfillment,payment_method,payment_status,subtotal_cents,fee_cents,tax_cents,total_cents,customer_name,customer_email,customer_phone,address1,address2,city,state,postal_code,requested_time,customer_notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $number, $user['id'] ?? null, 'awaiting_confirmation', $fulfillment, $payment,
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
