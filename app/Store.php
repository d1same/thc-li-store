<?php
declare(strict_types=1);

namespace App;

final class Store
{
    private static array $settings = [];

    public static function setting(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$settings)) {
            $row = Database::one('SELECT value, type FROM settings WHERE key = ?', [$key]);
            if (!$row) {
                return $default;
            }
            self::$settings[$key] = match ($row['type']) {
                'bool' => $row['value'] === '1',
                'int' => (int) $row['value'],
                default => $row['value'],
            };
        }
        return self::$settings[$key];
    }

    public static function setSetting(string $key, mixed $value, string $type = 'string'): void
    {
        $stored = $type === 'bool' ? ($value ? '1' : '0') : (string) $value;
        Database::execute(
            'INSERT INTO settings (key, value, type, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(key) DO UPDATE SET value=excluded.value, type=excluded.type, updated_at=CURRENT_TIMESTAMP',
            [$key, $stored, $type]
        );
        unset(self::$settings[$key]);
    }

    public static function categories(): array
    {
        return Database::all('SELECT * FROM categories WHERE active = 1 ORDER BY position, name');
    }

    public static function products(array $filters = []): array
    {
        $where = ["p.status IN ('active','sold_out')"];
        $params = [];
        if (!empty($filters['category'])) {
            $where[] = 'c.slug = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(p.name LIKE ? OR p.brand LIKE ? OR p.description LIKE ? OR v.flavors LIKE ?)';
            $query = '%' . trim($filters['search']) . '%';
            array_push($params, $query, $query, $query, $query);
        }
        if (!empty($filters['featured'])) {
            $where[] = 'p.featured = 1';
        }
        $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug,
                    MIN(COALESCE(v.sale_price_cents, v.price_cents)) AS from_price,
                    COUNT(v.id) AS variant_count
                FROM products p
                JOIN categories c ON c.id = p.category_id
                JOIN product_variants v ON v.product_id = p.id
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY p.id ORDER BY p.featured DESC, c.position, p.name';
        return Database::all($sql, $params);
    }

    public static function productBySlug(string $slug): ?array
    {
        $product = Database::one('SELECT p.*, c.name category_name, c.slug category_slug FROM products p JOIN categories c ON c.id=p.category_id WHERE p.slug=?', [$slug]);
        if ($product) {
            $product['variants'] = Database::all('SELECT * FROM product_variants WHERE product_id=? ORDER BY position, price_cents', [$product['id']]);
        }
        return $product;
    }

    public static function promotions(): array
    {
        return Database::all("SELECT * FROM promotions WHERE active=1 AND (starts_at IS NULL OR starts_at <= CURRENT_TIMESTAMP) AND (ends_at IS NULL OR ends_at >= CURRENT_TIMESTAMP) ORDER BY position");
    }
}

