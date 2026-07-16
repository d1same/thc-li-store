<?php
declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

final class EmailService
{
    public static function queue(array $message): ?int
    {
        $email = strtolower(trim((string) ($message['recipient_email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $subject = self::headerValue((string) ($message['subject'] ?? ''));
        $uniqueKey = trim((string) ($message['unique_key'] ?? ''));
        if ($subject === '' || $uniqueKey === '') {
            throw new RuntimeException('Email subject and unique key are required.');
        }
        Database::execute(
            "INSERT OR IGNORE INTO email_queue
             (message_type,recipient_email,recipient_name,subject,body_text,body_html,customer_id,order_id,campaign_id,unsubscribe_token,unique_key,created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $message['message_type'] ?? 'receipt', $email, trim((string) ($message['recipient_name'] ?? '')),
                $subject, (string) ($message['body_text'] ?? ''), $message['body_html'] ?? null,
                $message['customer_id'] ?? null, $message['order_id'] ?? null, $message['campaign_id'] ?? null,
                $message['unsubscribe_token'] ?? null, $uniqueKey, $message['created_by_user_id'] ?? null,
            ]
        );
        $row = Database::one('SELECT id FROM email_queue WHERE unique_key=?', [$uniqueKey]);
        return $row ? (int) $row['id'] : null;
    }

    public static function queueReceipt(int $orderId, string $recipient, ?int $createdBy = null, bool $resend = false): ?int
    {
        $order = Database::one('SELECT * FROM orders WHERE id=?', [$orderId]);
        if (!$order || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $items = Database::all('SELECT * FROM order_items WHERE order_id=? ORDER BY id', [$orderId]);
        $lines = [];
        $htmlItems = '';
        foreach ($items as $item) {
            $label = $item['product_name'] . ' (' . $item['variant_label'] . ')';
            $lines[] = $item['quantity'] . ' x ' . $label . ' - ' . money((int) $item['line_total_cents']);
            $htmlItems .= '<tr><td>' . (int) $item['quantity'] . ' × ' . self::html($label) . '</td><td style="text-align:right">' . self::html(money((int) $item['line_total_cents'])) . '</td></tr>';
        }
        $store = (string) Store::setting('store_name', 'Local Shop');
        $warning = (string) Store::setting('required_warning', 'For use only by persons 21 years of age and older.');
        $text = $store . " receipt {$order['order_number']}\n\n" . implode("\n", $lines) . "\n\n" .
            'Subtotal: ' . money((int) $order['subtotal_cents']) . "\n" .
            ((int) $order['discount_cents'] > 0 ? 'Discount: -' . money((int) $order['discount_cents']) . "\n" : '') .
            ((int) $order['fee_cents'] > 0 ? 'Fee: ' . money((int) $order['fee_cents']) . "\n" : '') .
            ((int) $order['tax_cents'] > 0 ? 'Tax: ' . money((int) $order['tax_cents']) . "\n" : '') .
            'Total: ' . money((int) $order['total_cents']) . "\n" .
            'Payment: ' . self::friendly((string) $order['payment_method']) . "\n" .
            'Fulfillment: ' . ucfirst((string) $order['fulfillment']) . "\n\n" . $warning;
        $html = self::emailShell(
            'Receipt ' . $order['order_number'],
            '<p>Thank you for your order.</p><table style="width:100%;border-collapse:collapse">' . $htmlItems . '</table>' .
            '<hr><p><strong>Total: ' . self::html(money((int) $order['total_cents'])) . '</strong><br>Payment: ' . self::html(self::friendly((string) $order['payment_method'])) . '<br>Fulfillment: ' . self::html(ucfirst((string) $order['fulfillment'])) . '</p>' .
            '<p style="font-size:12px;color:#66736c">' . self::html($warning) . '</p>'
        );
        $key = $resend
            ? 'receipt:resend:' . $orderId . ':' . bin2hex(random_bytes(8))
            : 'receipt:auto:' . $orderId . ':' . hash('sha256', strtolower($recipient));
        $queueId = self::queue([
            'message_type' => 'receipt', 'recipient_email' => $recipient, 'recipient_name' => $order['customer_name'],
            'subject' => $store . ' receipt ' . $order['order_number'], 'body_text' => $text, 'body_html' => $html,
            'customer_id' => $order['customer_id'] ?? null, 'order_id' => $orderId, 'unique_key' => $key,
            'created_by_user_id' => $createdBy,
        ]);
        if ($queueId) {
            Database::execute("UPDATE orders SET receipt_email_status='queued',updated_at=CURRENT_TIMESTAMP WHERE id=?", [$orderId]);
        }
        return $queueId;
    }

    public static function processQueue(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        Database::execute("UPDATE email_queue SET status='queued',locked_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE status='processing' AND locked_at<datetime('now','-15 minutes')");
        $result = ['processed' => 0, 'sent' => 0, 'failed' => 0];
        for ($i = 0; $i < $limit; $i++) {
            $row = Database::one("SELECT * FROM email_queue WHERE status='queued' AND available_at<=CURRENT_TIMESTAMP ORDER BY id LIMIT 1");
            if (!$row) {
                break;
            }
            $claimed = Database::executeAffected(
                "UPDATE email_queue SET status='processing',locked_at=CURRENT_TIMESTAMP,attempts=attempts+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='queued'",
                [(int) $row['id']]
            );
            if ($claimed !== 1) {
                continue;
            }
            $row = Database::one('SELECT * FROM email_queue WHERE id=?', [(int) $row['id']]);
            $result['processed']++;
            try {
                $sent = self::deliver($row);
                if (!$sent) {
                    throw new RuntimeException('The server mail transport rejected the message.');
                }
                Database::execute("UPDATE email_queue SET status='sent',sent_at=CURRENT_TIMESTAMP,locked_at=NULL,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int) $row['id']]);
                self::event((int) $row['id'], 'sent', 'Accepted by the server mail transport.');
                self::afterStatus($row, true);
                $result['sent']++;
            } catch (Throwable $error) {
                $final = (int) $row['attempts'] >= (int) $row['max_attempts'];
                Database::execute(
                    "UPDATE email_queue SET status=?,available_at=datetime('now','+5 minutes'),locked_at=NULL,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE id=?",
                    [$final ? 'failed' : 'queued', mb_substr($error->getMessage(), 0, 300), (int) $row['id']]
                );
                self::event((int) $row['id'], $final ? 'failed' : 'retry_scheduled', mb_substr($error->getMessage(), 0, 300));
                if ($final) {
                    self::afterStatus($row, false);
                    $result['failed']++;
                }
            }
        }
        return $result;
    }

    public static function readiness(bool $marketing = false): array
    {
        $checks = [
            'email_enabled' => Store::setting('email_enabled', false) === true,
            'from_address' => filter_var(Store::setting('email_from_address', ''), FILTER_VALIDATE_EMAIL) !== false,
        ];
        if ($marketing) {
            $license = trim((string) Store::setting('license_number', ''));
            $checks += [
                'campaigns_enabled' => Store::setting('marketing_campaigns_enabled', false) === true,
                'physical_address' => trim((string) Store::setting('marketing_physical_address', '')) !== '',
                'hopeline_disclosure' => trim((string) Store::setting('marketing_hopeline', '')) !== '',
                'license_configured' => $license !== '' && stripos($license, 'pending') === false,
                'dns_verified' => Store::setting('email_dns_verified', false) === true,
                'app_key' => strlen((string) getenv('APP_KEY')) >= 32,
            ];
        }
        return ['ready' => !in_array(false, $checks, true), 'checks' => $checks];
    }

    public static function unsubscribeToken(array $customer): ?string
    {
        $key = (string) getenv('APP_KEY');
        $email = strtolower(trim((string) ($customer['email'] ?? '')));
        if (strlen($key) < 32 || empty($customer['id']) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $id = (int) $customer['id'];
        $signature = hash_hmac('sha256', $id . '|' . $email, $key, true);
        return self::base64Url((string) $id) . '.' . self::base64Url($signature);
    }

    public static function unsubscribe(string $token, bool $apply = true): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $idText = self::base64UrlDecode($parts[0]);
        $signature = self::base64UrlDecode($parts[1]);
        if ($idText === null || $signature === null || !ctype_digit($idText)) {
            return null;
        }
        $customer = Database::one('SELECT * FROM customer_profiles WHERE id=?', [(int) $idText]);
        $key = (string) getenv('APP_KEY');
        if (!$customer || strlen($key) < 32) {
            return null;
        }
        $expected = hash_hmac('sha256', (int) $customer['id'] . '|' . strtolower(trim((string) $customer['email'])), $key, true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        if (!$apply) {
            return $customer;
        }
        Database::execute("UPDATE customer_profiles SET marketing_opt_in=0,marketing_unsubscribed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int) $customer['id']]);
        Database::execute(
            'INSERT INTO audit_events (action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?)',
            ['marketing.unsubscribed', 'customer', (string) $customer['id'], json_encode(['source' => 'email_link']), $_SERVER['REMOTE_ADDR'] ?? null]
        );
        return $customer;
    }

    private static function deliver(array $row): bool
    {
        if (Store::setting('email_enabled', false) !== true) {
            throw new RuntimeException('Email sending is disabled in store settings.');
        }
        $to = strtolower(trim((string) $row['recipient_email']));
        $from = strtolower(trim((string) Store::setting('email_from_address', '')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid recipient and From address are required.');
        }
        if ((string) getenv('APP_ENV') === 'testing' || str_ends_with($to, '.test')) {
            return true;
        }
        $fromName = self::headerValue((string) Store::setting('email_from_name', Store::setting('store_name', 'Local Shop')));
        $replyTo = strtolower(trim((string) Store::setting('email_reply_to', $from)));
        if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = $from;
        }
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $fromName . ' <' . $from . '>',
            'Reply-To: ' . $replyTo,
            'X-Auto-Response-Suppress: All',
        ];
        $body = (string) $row['body_text'];
        if (!empty($row['body_html'])) {
            $boundary = 'b' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" .
                (string) $row['body_text'] . "\r\n--" . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" .
                (string) $row['body_html'] . "\r\n--" . $boundary . "--";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }
        if ($row['message_type'] === 'campaign' && !empty($row['unsubscribe_token'])) {
            $unsubscribe = self::appUrl('unsubscribe/' . rawurlencode((string) $row['unsubscribe_token']));
            $headers[] = 'List-Unsubscribe: <' . $unsubscribe . '>';
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }
        return mail($to, self::headerValue((string) $row['subject']), $body, implode("\r\n", $headers));
    }

    private static function afterStatus(array $row, bool $sent): void
    {
        if ($row['message_type'] === 'receipt' && !empty($row['order_id'])) {
            if ($sent) {
                Database::execute("UPDATE orders SET receipt_email_sent=1,receipt_email_status='sent',receipt_email_last_sent_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int) $row['order_id']]);
            } else {
                Database::execute("UPDATE orders SET receipt_email_sent=0,receipt_email_status='failed',updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int) $row['order_id']]);
            }
        }
        if ($row['message_type'] === 'campaign' && !empty($row['campaign_id'])) {
            Database::execute(
                'UPDATE email_campaigns SET sent_count=sent_count+?,failed_count=failed_count+?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$sent ? 1 : 0, $sent ? 0 : 1, (int) $row['campaign_id']]
            );
            $remaining = (int) (Database::one("SELECT COUNT(*) count FROM email_queue WHERE campaign_id=? AND status IN ('queued','processing')", [(int) $row['campaign_id']])['count'] ?? 0);
            if ($remaining === 0) {
                Database::execute("UPDATE email_campaigns SET status='completed',updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int) $row['campaign_id']]);
            }
        }
    }

    private static function event(int $queueId, string $type, string $details): void
    {
        Database::execute('INSERT INTO email_delivery_events (queue_id,event_type,details) VALUES (?,?,?)', [$queueId, $type, $details]);
    }

    public static function appUrl(string $path = ''): string
    {
        $base = rtrim((string) getenv('APP_URL'), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = preg_replace('/[^a-z0-9.:-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
            $base = $scheme . '://' . $host . rtrim((string) getenv('APP_BASE'), '/');
        }
        return $base . '/' . ltrim($path, '/');
    }

    public static function emailShell(string $heading, string $body): string
    {
        $store = self::html((string) Store::setting('store_name', 'Local Shop'));
        return '<!doctype html><html><body style="margin:0;background:#f1f3ef;font-family:Arial,sans-serif;color:#15291f"><div style="max-width:640px;margin:auto;padding:28px"><div style="background:#164c35;color:white;padding:18px 22px;border-radius:18px 18px 0 0"><strong>' . $store . '</strong></div><div style="background:white;padding:26px 22px;border-radius:0 0 18px 18px"><h1 style="font-size:24px;margin-top:0">' . self::html($heading) . '</h1>' . $body . '</div></div></body></html>';
    }

    public static function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function friendly(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value === 'external_card' ? 'external terminal' : $value));
    }

    private static function headerValue(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded = $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
