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

    private static function send(string $to, string $subject, string $message): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || str_ends_with($to, '.test')) {
            return;
        }
        $host = preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: Local Shop <no-reply@' . $host . '>',
        ];
        @mail($to, $subject, $message, implode("\r\n", $headers));
    }
}

