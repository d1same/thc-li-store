<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class Auth
{
    private static ?array $cachedUser = null;
    private static array $permissionCache = [];
    private static int $retryAfter = 0;

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
            'customers.import' => 'Import customer spreadsheets',
            'emails.receipts' => 'Send and resend customer receipts',
            'campaigns.manage' => 'Draft and approve customer email campaigns',
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
        $user = Database::one('SELECT * FROM users WHERE id = ? AND status = ?', [$id, 'active']);
        if (!$user || (int) ($_SESSION['auth_version'] ?? 0) !== (int) ($user['auth_version'] ?? 1)) {
            self::clearSession();
            return null;
        }
        self::$cachedUser = $user;
        return self::$cachedUser;
    }

    public static function attempt(string $email, string $password): bool
    {
        self::$retryAfter = 0;
        $normalized = strtolower(trim($email));
        $ip = RateLimiter::ip();
        $accountLimit = RateLimiter::check('login.account', $normalized, 5, 900);
        $ipLimit = RateLimiter::check('login.ip', $ip, 20, 900);
        if (!$accountLimit['allowed'] || !$ipLimit['allowed']) {
            self::$retryAfter = max((int) $accountLimit['retry_after'], (int) $ipLimit['retry_after']);
            return false;
        }

        $user = Database::one('SELECT * FROM users WHERE email = ? COLLATE NOCASE AND status = ?', [$normalized, 'active']);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $accountLimit = RateLimiter::hit('login.account', $normalized, 5, 900, 900);
            $ipLimit = RateLimiter::hit('login.ip', $ip, 20, 900, 1800);
            if (!$accountLimit['allowed'] || !$ipLimit['allowed']) {
                self::$retryAfter = max((int) $accountLimit['retry_after'], (int) $ipLimit['retry_after']);
                Audit::record('auth.login_blocked', 'user', $user ? (string) $user['id'] : null, [
                    'account_hash' => hash('sha256', $normalized),
                    'retry_after' => self::$retryAfter,
                ], $user ? (int) $user['id'] : null);
            }
            return false;
        }

        RateLimiter::clear('login.account', $normalized);
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Database::execute('UPDATE users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
        }
        if (!empty($user['mfa_enabled_at']) && !empty($user['mfa_secret_encrypted'])) {
            session_regenerate_id(true);
            $_SESSION = [
                'mfa_pending_user_id' => (int) $user['id'],
                'mfa_pending_started_at' => time(),
            ];
            csrf_token();
            Audit::record('auth.password_verified', 'user', (string) $user['id'], ['mfa_pending' => true], (int) $user['id']);
            return true;
        }
        self::completeLogin($user);
        return true;
    }

    public static function retryAfter(): int
    {
        return self::$retryAfter;
    }

    public static function mfaPendingUser(): ?array
    {
        $id = (int) ($_SESSION['mfa_pending_user_id'] ?? 0);
        $started = (int) ($_SESSION['mfa_pending_started_at'] ?? 0);
        if (!$id || $started < time() - 300) {
            unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_started_at']);
            return null;
        }
        return Database::one("SELECT * FROM users WHERE id=? AND status='active' AND mfa_enabled_at IS NOT NULL", [$id]);
    }

    public static function completeMfa(string $code): bool
    {
        self::$retryAfter = 0;
        $user = self::mfaPendingUser();
        if (!$user) {
            return false;
        }
        $identifier = (string) $user['id'];
        $accountLimit = RateLimiter::check('mfa.account', $identifier, 6, 600);
        $ipLimit = RateLimiter::check('mfa.ip', RateLimiter::ip(), 20, 600);
        if (!$accountLimit['allowed'] || !$ipLimit['allowed']) {
            self::$retryAfter = max((int) $accountLimit['retry_after'], (int) $ipLimit['retry_after']);
            return false;
        }

        $valid = false;
        try {
            $valid = Totp::verify(Totp::decryptSecret((string) $user['mfa_secret_encrypted']), $code);
        } catch (RuntimeException) {
            $valid = false;
        }
        $recoveryHashes = json_decode((string) ($user['mfa_recovery_codes_json'] ?? '[]'), true) ?: [];
        $recoveryHash = Totp::recoveryHash($code);
        $recoveryIndex = array_search($recoveryHash, $recoveryHashes, true);
        if (!$valid && $recoveryIndex !== false) {
            unset($recoveryHashes[$recoveryIndex]);
            Database::execute(
                'UPDATE users SET mfa_recovery_codes_json=?,auth_version=auth_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [json_encode(array_values($recoveryHashes)), (int) $user['id']]
            );
            $user = Database::one('SELECT * FROM users WHERE id=?', [(int) $user['id']]) ?? $user;
            $valid = true;
            Audit::record('auth.mfa_recovery_used', 'user', (string) $user['id'], ['remaining' => count($recoveryHashes)], (int) $user['id']);
        }

        if (!$valid) {
            $accountLimit = RateLimiter::hit('mfa.account', $identifier, 6, 600, 900);
            $ipLimit = RateLimiter::hit('mfa.ip', RateLimiter::ip(), 20, 600, 1800);
            self::$retryAfter = max((int) $accountLimit['retry_after'], (int) $ipLimit['retry_after']);
            return false;
        }
        RateLimiter::clear('mfa.account', $identifier);
        self::completeLogin($user, true);
        return true;
    }

    public static function register(string $name, string $email, string $phone, string $password, string $role = 'customer'): int
    {
        $mustChange = in_array($role, ['staff', 'manager'], true) ? 1 : 0;
        Database::execute(
            'INSERT INTO users (name,email,phone,password_hash,role,must_change_password,password_changed_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP)',
            [trim($name), strtolower(trim($email)), trim($phone), password_hash($password, PASSWORD_DEFAULT), $role, $mustChange]
        );
        return (int) Database::pdo()->lastInsertId();
    }

    public static function loginById(int $id): void
    {
        $user = Database::one("SELECT * FROM users WHERE id=? AND status='active'", [$id]);
        if (!$user) {
            throw new RuntimeException('The account could not be signed in.');
        }
        self::completeLogin($user);
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            Audit::record('auth.logout', 'user', (string) $user['id'], [], (int) $user['id']);
        }
        self::clearSession(true);
    }

    public static function enforceSessionPolicy(): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }
        $now = time();
        $loginAt = (int) ($_SESSION['auth_login_at'] ?? 0);
        $lastSeen = (int) ($_SESSION['auth_last_seen_at'] ?? 0);
        if (!$loginAt || !$lastSeen || $lastSeen < $now - 1800 || $loginAt < $now - 28800) {
            self::clearSession(true);
            return;
        }
        if ((int) ($_SESSION['auth_regenerated_at'] ?? 0) < $now - 900) {
            session_regenerate_id(true);
            $_SESSION['auth_regenerated_at'] = $now;
        }
        $_SESSION['auth_last_seen_at'] = $now;
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
        if (self::needsSecuritySetup($user) && !self::onSecurityRoute()) {
            flash('warning', 'Complete your account security setup before continuing.');
            redirect('account/security');
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

    public static function needsSecuritySetup(?array $user = null): bool
    {
        $user ??= self::user();
        if (!$user) {
            return false;
        }
        if (!empty($user['must_change_password'])) {
            return true;
        }
        return in_array($user['role'], ['staff', 'manager', 'owner'], true)
            && Store::setting('staff_mfa_required', false)
            && empty($user['mfa_enabled_at']);
    }

    public static function changePassword(array $user, string $currentPassword, string $newPassword): void
    {
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new RuntimeException('The current password was not correct.');
        }
        $minimum = in_array($user['role'], ['staff', 'manager', 'owner'], true) ? 12 : 10;
        if (strlen($newPassword) < $minimum || strlen($newPassword) > 200) {
            throw new RuntimeException("Use a new password of at least {$minimum} characters.");
        }
        if (password_verify($newPassword, (string) $user['password_hash'])) {
            throw new RuntimeException('Choose a password you have not already been using.');
        }
        Database::execute(
            'UPDATE users SET password_hash=?,must_change_password=0,password_changed_at=CURRENT_TIMESTAMP,auth_version=auth_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]
        );
        self::refreshCurrentSession((int) $user['id']);
        Audit::record('auth.password_changed', 'user', (string) $user['id'], [], (int) $user['id']);
    }

    public static function beginMfa(array $user, string $password): string
    {
        if (!empty($user['mfa_enabled_at'])) {
            throw new RuntimeException('Two-factor authentication is already enabled. Disable it before starting a replacement.');
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            throw new RuntimeException('The current password was not correct.');
        }
        $secret = Totp::generateSecret();
        $_SESSION['mfa_enrollment'] = ['user_id' => (int) $user['id'], 'secret' => $secret, 'started_at' => time()];
        return $secret;
    }

    public static function pendingMfaSecret(array $user): ?string
    {
        $pending = $_SESSION['mfa_enrollment'] ?? [];
        if ((int) ($pending['user_id'] ?? 0) !== (int) $user['id'] || (int) ($pending['started_at'] ?? 0) < time() - 900) {
            unset($_SESSION['mfa_enrollment']);
            return null;
        }
        return (string) ($pending['secret'] ?? '') ?: null;
    }

    public static function confirmMfa(array $user, string $code): array
    {
        $secret = self::pendingMfaSecret($user);
        if (!$secret || !Totp::verify($secret, $code)) {
            throw new RuntimeException('The authenticator code was not valid. Check the time on your device and try again.');
        }
        $codes = Totp::recoveryCodes();
        $hashes = array_map([Totp::class, 'recoveryHash'], $codes);
        Database::execute(
            'UPDATE users SET mfa_secret_encrypted=?,mfa_enabled_at=CURRENT_TIMESTAMP,mfa_recovery_codes_json=?,auth_version=auth_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [Totp::encryptSecret($secret), json_encode($hashes), (int) $user['id']]
        );
        unset($_SESSION['mfa_enrollment']);
        self::refreshCurrentSession((int) $user['id']);
        Audit::record('auth.mfa_enabled', 'user', (string) $user['id'], [], (int) $user['id']);
        return $codes;
    }

    public static function disableMfa(array $user, string $password, string $code): void
    {
        if (!password_verify($password, (string) $user['password_hash'])) {
            throw new RuntimeException('The current password was not correct.');
        }
        $valid = false;
        if (!empty($user['mfa_secret_encrypted'])) {
            $valid = Totp::verify(Totp::decryptSecret((string) $user['mfa_secret_encrypted']), $code);
        }
        if (!$valid) {
            $hashes = json_decode((string) ($user['mfa_recovery_codes_json'] ?? '[]'), true) ?: [];
            $valid = in_array(Totp::recoveryHash($code), $hashes, true);
        }
        if (!$valid) {
            throw new RuntimeException('Enter a current authenticator or recovery code.');
        }
        Database::execute(
            'UPDATE users SET mfa_secret_encrypted=NULL,mfa_enabled_at=NULL,mfa_recovery_codes_json=NULL,auth_version=auth_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [(int) $user['id']]
        );
        self::refreshCurrentSession((int) $user['id']);
        Audit::record('auth.mfa_disabled', 'user', (string) $user['id'], [], (int) $user['id']);
    }

    private static function completeLogin(array $user, bool $mfa = false): void
    {
        session_regenerate_id(true);
        $_SESSION = [
            'user_id' => (int) $user['id'],
            'auth_version' => (int) ($user['auth_version'] ?? 1),
            'auth_login_at' => time(),
            'auth_last_seen_at' => time(),
            'auth_regenerated_at' => time(),
        ];
        csrf_token();
        Database::execute('UPDATE users SET last_login_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int) $user['id']]);
        self::$cachedUser = Database::one('SELECT * FROM users WHERE id=?', [(int) $user['id']]);
        Audit::record($mfa ? 'auth.mfa_login_succeeded' : 'auth.login_succeeded', 'user', (string) $user['id'], [], (int) $user['id']);
    }

    private static function refreshCurrentSession(int $userId): void
    {
        $row = Database::one('SELECT auth_version FROM users WHERE id=?', [$userId]);
        $_SESSION['auth_version'] = (int) ($row['auth_version'] ?? 1);
        $_SESSION['auth_regenerated_at'] = time();
        session_regenerate_id(true);
        self::$cachedUser = null;
    }

    private static function onSecurityRoute(): bool
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return str_contains($path, '/account/security') || str_ends_with($path, '/logout');
    }

    private static function clearSession(bool $destroy = false): void
    {
        $_SESSION = [];
        if ($destroy && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
            session_destroy();
            if (PHP_SAPI !== 'cli') {
                session_start();
            }
        }
        self::$cachedUser = null;
        self::$permissionCache = [];
    }
}
