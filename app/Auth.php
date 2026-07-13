<?php
declare(strict_types=1);

namespace App;

final class Auth
{
    private static ?array $cachedUser = null;
    private static array $permissionCache = [];

    public static function permissionDefinitions(): array
    {
        return [
            'pos.access' => 'Open the POS register',
            'pos.complete' => 'Complete in-store sales',
            'pos.discount' => 'Apply manual POS discounts',
            'orders.view' => 'View customer and POS orders',
            'orders.manage' => 'Update, complete or cancel orders',
            'reports.view' => 'View sales reports and business charts',
            'customers.view' => 'View customer profiles and purchase history',
            'customers.edit' => 'Edit customer contact details and private notes',
            'customers.export' => 'Export customer contact lists',
            'products.view' => 'View products and inventory',
            'products.create' => 'Add products and options',
            'products.edit' => 'Edit products, prices and stock',
            'products.archive' => 'Archive products from the menu',
            'promotions.manage' => 'Create, disable or delete promotions',
            'settings.manage' => 'Change store and POS settings',
        ];
    }

    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if (!$id) {
            return null;
        }
        self::$cachedUser = Database::one('SELECT * FROM users WHERE id = ? AND status = ?', [$id, 'active']);
        return self::$cachedUser;
    }

    public static function attempt(string $email, string $password): bool
    {
        $window = (int) ($_SESSION['login_window'] ?? 0);
        if ($window < time() - 900) {
            $_SESSION['login_window'] = time();
            $_SESSION['login_attempts'] = 0;
        }
        if ((int) ($_SESSION['login_attempts'] ?? 0) >= 5) {
            return false;
        }
        $user = Database::one('SELECT * FROM users WHERE email = ? COLLATE NOCASE AND status = ?', [trim($email), 'active']);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $_SESSION['login_attempts'] = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
            return false;
        }
        unset($_SESSION['login_attempts'], $_SESSION['login_window']);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::$cachedUser = $user;
        return true;
    }

    public static function register(string $name, string $email, string $phone, string $password, string $role = 'customer'): int
    {
        Database::execute(
            'INSERT INTO users (name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?)',
            [trim($name), strtolower(trim($email)), trim($phone), password_hash($password, PASSWORD_DEFAULT), $role]
        );
        return (int) Database::pdo()->lastInsertId();
    }

    public static function loginById(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        self::$cachedUser = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        self::$cachedUser = null;
        self::$permissionCache = [];
    }

    public static function isStaff(): bool
    {
        $user = self::user();
        return $user && in_array($user['role'], ['staff', 'manager', 'owner'], true);
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if (!$user) {
            flash('warning', 'Please sign in to continue.');
            redirect('login');
        }
        return $user;
    }

    public static function requireStaff(): array
    {
        $user = self::requireUser();
        if (!in_array($user['role'], ['staff', 'manager', 'owner'], true)) {
            http_response_code(403);
            exit('You do not have permission to view this page.');
        }
        return $user;
    }

    public static function can(string $permission, ?array $user = null): bool
    {
        $user ??= self::user();
        if (!$user || !in_array($user['role'], ['staff', 'manager', 'owner'], true)) {
            return false;
        }
        if ($user['role'] === 'owner') {
            return true;
        }
        $userId = (int) $user['id'];
        if (!array_key_exists($userId, self::$permissionCache)) {
            self::$permissionCache[$userId] = array_fill_keys(array_column(Database::all(
                'SELECT permission FROM staff_permissions WHERE user_id=? AND allowed=1',
                [$userId]
            ), 'permission'), true);
        }
        return isset(self::$permissionCache[$userId][$permission]);
    }

    public static function requirePermission(string $permission): array
    {
        $user = self::requireStaff();
        if (!self::can($permission, $user)) {
            http_response_code(403);
            exit('You do not have permission to perform this action.');
        }
        return $user;
    }

    public static function syncPermissions(int $userId, array $permissions): void
    {
        $allowed = array_intersect(array_keys(self::permissionDefinitions()), $permissions);
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM staff_permissions WHERE user_id=?')->execute([$userId]);
        $stmt = $pdo->prepare('INSERT INTO staff_permissions (user_id,permission,allowed,updated_at) VALUES (?,?,1,CURRENT_TIMESTAMP)');
        foreach ($allowed as $permission) {
            $stmt->execute([$userId, $permission]);
        }
        unset(self::$permissionCache[$userId]);
    }
}
