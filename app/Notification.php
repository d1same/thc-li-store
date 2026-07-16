<?php
declare(strict_types=1);

namespace App;

final class Notification
{
    public static function orderReceived(int $orderId): void
    {
        if (!EmailService::readiness(false)['ready']) {
            return;
        }
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
        if (Store::setting('email_order_confirmation_enabled', true)) {
            EmailService::queue([
                'message_type' => 'order_confirmation',
                'recipient_email' => (string) $order['customer_email'],
                'recipient_name' => (string) $order['customer_name'],
                'subject' => $subject,
                'body_text' => $message,
                'body_html' => EmailService::emailShell('Order received', '<p>We received order request <strong>' . EmailService::html((string) $order['order_number']) . '</strong>.</p><p>Estimated total: <strong>' . EmailService::html(money((int) $order['total_cents'])) . '</strong><br>Fulfillment: ' . EmailService::html(ucfirst((string) $order['fulfillment'])) . '</p><p>The owner will confirm availability, timing, and payment before fulfillment.</p>'),
                'customer_id' => $order['customer_id'] ?? null,
                'order_id' => $orderId,
                'unique_key' => 'order-confirmation:' . $orderId,
            ]);
        }
        $ownerEmail = trim((string) Store::setting('store_email', ''));
        if ($ownerEmail !== '') {
            EmailService::queue([
                'message_type' => 'owner_notification', 'recipient_email' => $ownerEmail,
                'subject' => 'New order - ' . $order['order_number'],
                'body_text' => $message . "\nCustomer: {$order['customer_name']}\nPhone: {$order['customer_phone']}",
                'order_id' => $orderId, 'unique_key' => 'owner-order:' . $orderId,
            ]);
        }
    }

    public static function posReceipt(int $orderId): bool
    {
        if (!EmailService::readiness(false)['ready']) {
            return false;
        }
        $order = Database::one('SELECT * FROM orders WHERE id=? AND order_source=?', [$orderId, 'pos']);
        if (!$order || trim((string) $order['customer_email']) === '') {
            return false;
        }
        return EmailService::queueReceipt($orderId, (string) $order['customer_email']) !== null;
    }
}
