<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class CampaignService
{
    public static function createDraft(array $data, ?int $userId): int
    {
        $subject = mb_substr(trim((string) ($data['subject'] ?? '')), 0, 140);
        $title = mb_substr(trim((string) ($data['title'] ?? $subject)), 0, 120);
        $intro = mb_substr(trim((string) ($data['intro_text'] ?? '')), 0, 1000);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['product_ids'] ?? [])))));
        if ($title === '' || $subject === '' || $intro === '' || !$ids) throw new RuntimeException('Add a title, subject, message, and at least one product.');
        Database::execute('INSERT INTO email_campaigns (title,subject,intro_text,product_ids_json,created_by_user_id) VALUES (?,?,?,?,?)', [$title, $subject, $intro, json_encode($ids), $userId]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function approve(int $id, int $userId): int
    {
        $campaign = Database::one("SELECT * FROM email_campaigns WHERE id=? AND status='draft'", [$id]);
        if (!$campaign) throw new RuntimeException('Only a draft campaign can be approved.');
        $ready = EmailService::readiness(true);
        if (!$ready['ready']) throw new RuntimeException('Complete every email readiness check in Settings before approving a campaign.');
        $products = self::products($campaign);
        if (!$products) throw new RuntimeException('The selected products are no longer active.');
        $customers = Database::all("SELECT * FROM customer_profiles WHERE status='active' AND marketing_opt_in=1 AND marketing_unsubscribed_at IS NULL AND marketing_consent_at IS NOT NULL AND TRIM(marketing_consent_source)!='' AND marketing_age_verified_at IS NOT NULL AND TRIM(email)!=''");
        $queued = 0;
        foreach ($customers as $customer) {
            if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) continue;
            $token = EmailService::unsubscribeToken($customer);
            if (!$token) continue;
            [$text, $html] = self::body($campaign, $products, $token);
            if (EmailService::queue([
                'message_type' => 'campaign', 'recipient_email' => $customer['email'], 'recipient_name' => $customer['name'],
                'subject' => $campaign['subject'], 'body_text' => $text, 'body_html' => $html, 'customer_id' => $customer['id'],
                'campaign_id' => $id, 'unsubscribe_token' => $token, 'unique_key' => 'campaign:' . $id . ':customer:' . $customer['id'],
                'created_by_user_id' => $userId,
            ])) $queued++;
        }
        Database::execute("UPDATE email_campaigns SET status=?,recipient_count=?,approved_by_user_id=?,approved_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?", [$queued > 0 ? 'sending' : 'completed', $queued, $userId, $id]);
        return $queued;
    }

    public static function createMonthlyDraft(?int $userId = null): ?int
    {
        if (Store::setting('marketing_campaigns_enabled', false) !== true) return null;
        $month = date('Y-m');
        if (Database::one("SELECT id FROM email_campaigns WHERE title LIKE ?", ['Monthly new products ' . $month . '%'])) return null;
        $products = Database::all("SELECT id FROM products WHERE status='active' AND created_at>=datetime('now','-31 days') ORDER BY created_at DESC LIMIT 12");
        if (!$products) return null;
        return self::createDraft([
            'title' => 'Monthly new products ' . $month,
            'subject' => 'New this month at ' . Store::setting('store_name', 'our store'),
            'intro_text' => 'Here are the newest additions to our menu. Availability can change; view the current menu for details.',
            'product_ids' => array_column($products, 'id'),
        ], $userId);
    }

    public static function products(array $campaign): array
    {
        $ids = array_values(array_filter(array_map('intval', json_decode((string) $campaign['product_ids_json'], true) ?: [])));
        if (!$ids) return [];
        return Database::all('SELECT p.*,c.name category_name,MIN(COALESCE(v.sale_price_cents,v.price_cents)) price FROM products p JOIN categories c ON c.id=p.category_id JOIN product_variants v ON v.product_id=p.id WHERE p.status=\'active\' AND p.id IN (' . implode(',', $ids) . ') GROUP BY p.id ORDER BY p.created_at DESC');
    }

    private static function body(array $campaign, array $products, string $token): array
    {
        $rows = ''; $plain = $campaign['intro_text'] . "\n\n";
        foreach ($products as $product) {
            $link = EmailService::appUrl('product/' . rawurlencode($product['slug']));
            $plain .= $product['brand'] . ' ' . $product['name'] . ' - from ' . money((int) $product['price']) . "\n" . $link . "\n\n";
            $rows .= '<p><strong>' . EmailService::html(trim($product['brand'] . ' ' . $product['name'])) . '</strong><br>' . EmailService::html($product['category_name']) . ' · from ' . EmailService::html(money((int) $product['price'])) . '<br><a href="' . EmailService::html($link) . '">View product</a></p>';
        }
        $unsubscribe = EmailService::appUrl('unsubscribe/' . rawurlencode($token));
        $footer = trim((string) Store::setting('required_warning', 'For use only by persons 21 years of age and older.')) . "\n" .
            trim((string) Store::setting('marketing_physical_address', '')) . "\nLicense: " . Store::setting('license_number', '') . "\n" .
            Store::setting('marketing_hopeline', '') . "\nUnsubscribe: " . $unsubscribe;
        $plain .= $footer;
        $html = EmailService::emailShell((string) $campaign['title'], '<p>' . nl2br(EmailService::html((string) $campaign['intro_text'])) . '</p>' . $rows . '<hr><p style="font-size:12px;color:#66736c">' . nl2br(EmailService::html($footer)) . '</p>');
        return [$plain, $html];
    }
}
