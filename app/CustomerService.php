<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class CustomerService
{
    public static function emailKey(?string $email): ?string
    {
        $value = strtolower(trim((string) $email));
        return $value === '' ? null : $value;
    }

    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        return strlen($digits) >= 7 ? $digits : null;
    }

    public static function capture(array $data, ?PDO $pdo = null): ?int
    {
        $pdo ??= Database::pdo();
        $email = self::emailKey($data['email'] ?? null);
        $phoneKey = self::phoneKey($data['phone'] ?? null);
        $userId = !empty($data['user_id']) ? (int) $data['user_id'] : null;
        $preferredId = !empty($data['customer_id']) ? (int) $data['customer_id'] : null;
        if ($email === null && $phoneKey === null && $userId === null && $preferredId === null) {
            return null;
        }

        $profile = null;
        if ($preferredId) {
            $profile = self::one($pdo, 'SELECT * FROM customer_profiles WHERE id=?', [$preferredId]);
        }
        if (!$profile && $email !== null) {
            $profile = self::one($pdo, 'SELECT * FROM customer_profiles WHERE email_key=?', [$email]);
        }
        if (!$profile && $phoneKey !== null) {
            $profile = self::one($pdo, 'SELECT * FROM customer_profiles WHERE phone_key=?', [$phoneKey]);
        }
        if (!$profile && $userId !== null) {
            $profile = self::one($pdo, 'SELECT * FROM customer_profiles WHERE user_id=?', [$userId]);
        }

        $name = trim((string) ($data['name'] ?? '')) ?: ($profile['name'] ?? 'Customer');
        $phone = trim((string) ($data['phone'] ?? '')) ?: ($profile['phone'] ?? '');
        $address = [
            'address1' => trim((string) ($data['address1'] ?? '')),
            'address2' => trim((string) ($data['address2'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'state' => strtoupper(trim((string) ($data['state'] ?? 'NY'))) ?: 'NY',
            'postal_code' => trim((string) ($data['postal_code'] ?? '')),
        ];
        $marketing = !empty($data['marketing_opt_in']) ? 1 : (int) ($profile['marketing_opt_in'] ?? 0);

        if ($profile) {
            $emailKey = $email ?? ($profile['email_key'] ?: null);
            $resolvedPhoneKey = $phoneKey ?? ($profile['phone_key'] ?: null);
            $resolvedEmail = $email ?? ($profile['email'] ?? '');
            $resolvedPhone = $phone;
            if ($emailKey !== null && self::keyOwnedByAnother($pdo, 'email_key', $emailKey, (int) $profile['id'])) {
                $emailKey = $profile['email_key'] ?: null;
                $resolvedEmail = $profile['email'] ?? '';
            }
            if ($resolvedPhoneKey !== null && self::keyOwnedByAnother($pdo, 'phone_key', $resolvedPhoneKey, (int) $profile['id'])) {
                $resolvedPhoneKey = $profile['phone_key'] ?: null;
                $resolvedPhone = $profile['phone'] ?? '';
            }
            $stmt = $pdo->prepare(
                'UPDATE customer_profiles SET user_id=COALESCE(user_id,?),email_key=?,phone_key=?,name=?,email=?,phone=?,address1=?,address2=?,city=?,state=?,postal_code=?,marketing_opt_in=?,last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?'
            );
            $stmt->execute([
                $userId, $emailKey, $resolvedPhoneKey, $name, $resolvedEmail, $resolvedPhone,
                $address['address1'] ?: ($profile['address1'] ?? ''), $address['address2'] ?: ($profile['address2'] ?? ''),
                $address['city'] ?: ($profile['city'] ?? ''), $address['state'], $address['postal_code'] ?: ($profile['postal_code'] ?? ''),
                $marketing, (int) $profile['id'],
            ]);
            return (int) $profile['id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO customer_profiles (user_id,email_key,phone_key,name,email,phone,address1,address2,city,state,postal_code,marketing_opt_in) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $userId, $email, $phoneKey, $name, $email ?? '', $phone, $address['address1'], $address['address2'],
            $address['city'], $address['state'], $address['postal_code'], $marketing,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function backfillUnlinkedOrders(int $limit = 1000): int
    {
        $orders = Database::all(
            "SELECT * FROM orders WHERE customer_id IS NULL AND (TRIM(customer_email)!='' OR TRIM(customer_phone)!='') ORDER BY id LIMIT ?",
            [$limit]
        );
        $count = 0;
        $pdo = Database::pdo();
        foreach ($orders as $order) {
            $customerId = self::capture([
                'user_id' => $order['user_id'] ?? null,
                'name' => $order['customer_name'],
                'email' => $order['customer_email'],
                'phone' => $order['customer_phone'],
                'address1' => $order['address1'],
                'address2' => $order['address2'],
                'city' => $order['city'],
                'state' => $order['state'],
                'postal_code' => $order['postal_code'],
            ], $pdo);
            if ($customerId) {
                $pdo->prepare('UPDATE orders SET customer_id=? WHERE id=? AND customer_id IS NULL')->execute([$customerId, (int) $order['id']]);
                $count++;
            }
        }
        return $count;
    }

    public static function syncDirectory(int $limit = 2000): int
    {
        $count = 0;
        $users = Database::all(
            "SELECT u.* FROM users u LEFT JOIN customer_profiles c ON c.user_id=u.id
             WHERE u.role='customer' AND c.id IS NULL ORDER BY u.id LIMIT {$limit}"
        );
        foreach ($users as $user) {
            if (self::capture([
                'user_id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
            ])) {
                $count++;
            }
        }
        return $count + self::backfillUnlinkedOrders($limit);
    }

    public static function update(int $id, array $data): void
    {
        $profile = Database::one('SELECT * FROM customer_profiles WHERE id=?', [$id]);
        if (!$profile) {
            throw new RuntimeException('Customer not found.');
        }
        $email = self::emailKey($data['email'] ?? null);
        $phoneKey = self::phoneKey($data['phone'] ?? null);
        if ($email !== null && self::keyOwnedByAnother(Database::pdo(), 'email_key', $email, $id)) {
            throw new RuntimeException('That email already belongs to another customer profile.');
        }
        if ($phoneKey !== null && self::keyOwnedByAnother(Database::pdo(), 'phone_key', $phoneKey, $id)) {
            throw new RuntimeException('That phone number already belongs to another customer profile.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Customer name is required.');
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid customer email.');
        }
        $status = ($data['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        Database::execute(
            'UPDATE customer_profiles SET email_key=?,phone_key=?,name=?,email=?,phone=?,address1=?,address2=?,city=?,state=?,postal_code=?,status=?,marketing_opt_in=?,private_notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $email, $phoneKey, $name, $email ?? '', trim((string) ($data['phone'] ?? '')),
                trim((string) ($data['address1'] ?? '')), trim((string) ($data['address2'] ?? '')),
                trim((string) ($data['city'] ?? '')), strtoupper(trim((string) ($data['state'] ?? 'NY'))) ?: 'NY',
                trim((string) ($data['postal_code'] ?? '')), $status, !empty($data['marketing_opt_in']) ? 1 : 0,
                trim((string) ($data['private_notes'] ?? '')), $id,
            ]
        );
    }

    private static function keyOwnedByAnother(PDO $pdo, string $column, string $value, int $id): bool
    {
        if (!in_array($column, ['email_key', 'phone_key'], true)) {
            return true;
        }
        $stmt = $pdo->prepare("SELECT 1 FROM customer_profiles WHERE {$column}=? AND id!=? LIMIT 1");
        $stmt->execute([$value, $id]);
        return (bool) $stmt->fetchColumn();
    }

    private static function one(PDO $pdo, string $sql, array $params): ?array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
