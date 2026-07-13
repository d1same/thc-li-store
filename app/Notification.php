<?php
declare(strict_types=1);

namespace App;

final class Notification
{
    public static function orderReceived(int $orderId): void
    {
        $order = Database::one('SELECT * FROM orders WHERE id=?', [$orderId]);
        if (!$order) {
            return;
        }
        $storeName = (string) Store::setting('store_name', 'Local Shop');
        $subject = $storeName . ' order received - ' . $order['order_number'];
        $message = "We received your order request {$order['order_number']}.\n\n" .
            "Estimated total: " . money((int) $order['total_cents']) . "\n" .
            "Fulfillment: " . ucfirst($order['fulfillment']) . "\n\n" .
            "The owner will confirm availability, timing, and payment before fulfillment.";
        self::send((string) $order['customer_email'], $subject, $message);
        $ownerEmail = trim((string) Store::setting('store_email', ''));
        if ($ownerEmail !== '') {
            self::send($ownerEmail, 'New order - ' . $order['order_number'], $message . "\nCustomer: {$order['customer_name']}\nPhone: {$order['customer_phone']}");
        }
    }

    public static function posReceipt(int $orderId): bool
    {
        $order = Database::one('SELECT * FROM orders WHERE id=? AND order_source=?', [$orderId, 'pos']);
        if (!$order || trim((string) $order['customer_email']) === '') {
            return false;
        }
        $items = Database::all('SELECT * FROM order_items WHERE order_id=? ORDER BY id', [$orderId]);
        $lines = [];
        foreach ($items as $item) {
            $lines[] = $item['quantity'] . ' x ' . $item['product_name'] . ' (' . $item['variant_label'] . ') - ' . money((int) $item['line_total_cents']);
        }
        $message = Store::setting('store_name', 'Local Shop') . " receipt {$order['order_number']}\n\n" .
            implode("\n", $lines) . "\n\n" .
            'Subtotal: ' . money((int) $order['subtotal_cents']) . "\n" .
            ((int) $order['discount_cents'] > 0 ? 'Discount: -' . money((int) $order['discount_cents']) . "\n" : '') .
            ((int) $order['tax_cents'] > 0 ? 'Tax: ' . money((int) $order['tax_cents']) . "\n" : '') .
            'Total: ' . money((int) $order['total_cents']) . "\n" .
            'Payment: ' . ($order['payment_method'] === 'cash' ? 'Cash' : 'External card terminal') . "\n\n" .
            (string) Store::setting('required_warning', 'For use only by persons 21 years of age and older.');
        return self::send((string) $order['customer_email'], Store::setting('store_name', 'Local Shop') . ' receipt ' . $order['order_number'], $message);
    }

    private static function send(string $to, string $subject, string $message): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || str_ends_with($to, '.test')) {
            return false;
        }
        $host = preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: Local Shop <no-reply@' . $host . '>',
        ];
        return @mail($to, $subject, $message, implode("\r\n", $headers));
    }
}
