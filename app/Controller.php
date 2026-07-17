<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class Controller
{
    public static function home(): void
    {
        render('home', [
            'title' => Store::setting('store_name'),
            'featured' => Store::products(['featured' => true]),
            'categories' => Store::categories(),
            'promotions' => Store::promotions(),
        ]);
    }

    public static function menu(): void
    {
        render('menu', [
            'title' => 'Menu',
            'products' => Store::products(['category' => $_GET['category'] ?? '', 'search' => $_GET['q'] ?? '']),
            'categories' => Store::categories(),
            'activeCategory' => $_GET['category'] ?? '',
            'search' => $_GET['q'] ?? '',
        ]);
    }

    public static function product(string $slug): void
    {
        $product = Store::productBySlug($slug);
        if (!$product) {
            http_response_code(404);
            render('errors/404', ['title' => 'Product not found']);
            return;
        }
        render('product', ['title' => $product['name'], 'product' => $product]);
    }

    public static function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            if (Auth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                if (Auth::mfaPendingUser()) {
                    redirect('login/mfa');
                }
                if (Auth::needsSecuritySetup()) {
                    redirect('account/security');
                }
                redirect(Auth::isStaff() ? 'admin' : 'account');
            }
            if (Auth::retryAfter() > 0) {
                http_response_code(429);
                header('Retry-After: ' . Auth::retryAfter());
                flash('error', 'Too many sign-in attempts. Wait a few minutes before trying again.');
            } else {
                flash('error', 'The email or password was not recognized.');
            }
        }
        render('auth/login', ['title' => 'Sign in']);
    }

    public static function loginMfa(): void
    {
        $pending = Auth::mfaPendingUser();
        if (!$pending) {
            flash('warning', 'Your verification session expired. Sign in again.');
            redirect('login');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            if (Auth::completeMfa((string) ($_POST['code'] ?? ''))) {
                if (Auth::needsSecuritySetup()) {
                    redirect('account/security');
                }
                redirect(Auth::isStaff() ? 'admin' : 'account');
            }
            if (Auth::retryAfter() > 0) {
                http_response_code(429);
                header('Retry-After: ' . Auth::retryAfter());
                flash('error', 'Too many verification attempts. Wait before trying again.');
            } else {
                flash('error', 'That verification code was not accepted.');
            }
        }
        render('auth/mfa', ['title' => 'Security verification', 'pending' => $pending]);
    }

    public static function register(): void
    {
        if (!Store::setting('registration_enabled', true)) {
            flash('warning', 'New account registration is currently paused.');
            redirect('login');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            if (trim((string) ($_POST['website'] ?? '')) !== '') {
                http_response_code(202);
                render('auth/register', ['title' => 'Create account']);
                return;
            }
            $limit = RateLimiter::hit('register.ip', RateLimiter::ip(), 5, 3600, 3600);
            if (!$limit['allowed']) {
                http_response_code(429);
                header('Retry-After: ' . (int) $limit['retry_after']);
                flash('error', 'Too many account attempts from this connection. Try again later.');
                render('auth/register', ['title' => 'Create account']);
                return;
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
                flash('error', 'Enter your name, a valid email, and a password of at least 10 characters.');
            } else {
                try {
                    $id = Auth::register($name, $email, (string) ($_POST['phone'] ?? ''), $password);
                    Auth::loginById($id);
                    redirect('account');
                } catch (\Throwable) {
                    flash('error', 'An account with that email may already exist.');
                }
            }
        }
        render('auth/register', ['title' => 'Create account']);
    }

    public static function logout(): void
    {
        verify_csrf();
        Auth::logout();
        redirect('');
    }

    public static function account(): void
    {
        $user = Auth::requireUser();
        $orders = Database::all('SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC', [$user['id']]);
        $addresses = Database::all('SELECT * FROM addresses WHERE user_id=? ORDER BY created_at DESC', [$user['id']]);
        $favorites = Database::all('SELECT p.*,c.name category_name,c.slug category_slug,MIN(COALESCE(v.sale_price_cents,v.price_cents)) from_price FROM favorites f JOIN products p ON p.id=f.product_id JOIN categories c ON c.id=p.category_id JOIN product_variants v ON v.product_id=p.id WHERE f.user_id=? GROUP BY p.id ORDER BY f.created_at DESC', [$user['id']]);
        render('account/index', ['title' => 'Your account', 'orders' => $orders, 'addresses' => $addresses, 'favorites' => $favorites]);
    }

    public static function accountSecurity(): void
    {
        $user = Auth::requireUser();
        $secret = Auth::pendingMfaSecret($user);
        $recoveryCodes = $_SESSION['mfa_recovery_codes'] ?? [];
        unset($_SESSION['mfa_recovery_codes']);
        render('account/security', [
            'title' => 'Account security',
            'securityUser' => $user,
            'pendingSecret' => $secret,
            'provisioningUri' => $secret ? Totp::provisioningUri($secret, (string) $user['email'], (string) Store::setting('store_name', 'THC LI')) : null,
            'recoveryCodes' => is_array($recoveryCodes) ? $recoveryCodes : [],
        ]);
    }

    public static function accountSecurityPassword(): void
    {
        $user = Auth::requireUser();
        verify_csrf();
        try {
            Auth::changePassword($user, (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''));
            flash('success', 'Your password was changed and other sessions were invalidated.');
        } catch (RuntimeException $error) {
            flash('error', $error->getMessage());
        }
        redirect('account/security');
    }

    public static function accountSecurityMfaStart(): void
    {
        $user = Auth::requireUser();
        verify_csrf();
        try {
            Auth::beginMfa($user, (string) ($_POST['password'] ?? ''));
            flash('success', 'Authenticator setup started. Enter the key in your app, then confirm a code.');
        } catch (RuntimeException $error) {
            flash('error', $error->getMessage());
        }
        redirect('account/security');
    }

    public static function accountSecurityMfaConfirm(): void
    {
        $user = Auth::requireUser();
        verify_csrf();
        $limit = RateLimiter::hit('mfa.enroll', (string) $user['id'], 8, 900, 900);
        if (!$limit['allowed']) {
            flash('error', 'Too many setup attempts. Wait before trying again.');
            redirect('account/security');
        }
        try {
            $_SESSION['mfa_recovery_codes'] = Auth::confirmMfa($user, (string) ($_POST['code'] ?? ''));
            flash('success', 'Two-factor authentication is active. Save the recovery codes now.');
        } catch (RuntimeException $error) {
            flash('error', $error->getMessage());
        }
        redirect('account/security');
    }

    public static function accountSecurityMfaDisable(): void
    {
        $user = Auth::requireUser();
        verify_csrf();
        try {
            Auth::disableMfa($user, (string) ($_POST['password'] ?? ''), (string) ($_POST['code'] ?? ''));
            flash('success', 'Two-factor authentication was disabled.');
        } catch (RuntimeException $error) {
            flash('error', $error->getMessage());
        }
        redirect('account/security');
    }

    public static function accountOrder(string $id): void
    {
        $user = Auth::requireUser();
        $order = Database::one('SELECT * FROM orders WHERE id=? AND user_id=?', [(int) $id, $user['id']]);
        if (!$order) {
            http_response_code(404);
            render('errors/404', ['title' => 'Order not found']);
            return;
        }
        $items = Database::all('SELECT * FROM order_items WHERE order_id=?', [$order['id']]);
        render('account/order', ['title' => 'Order ' . $order['order_number'], 'order' => $order, 'items' => $items]);
    }

    public static function favorite(string $id): void
    {
        $user = Auth::requireUser();
        verify_csrf();
        $exists = Database::one('SELECT 1 FROM favorites WHERE user_id=? AND product_id=?', [$user['id'], (int) $id]);
        if ($exists) {
            Database::execute('DELETE FROM favorites WHERE user_id=? AND product_id=?', [$user['id'], (int) $id]);
            flash('success', 'Removed from favorites.');
        } else {
            Database::execute('INSERT OR IGNORE INTO favorites (user_id,product_id) VALUES (?,?)', [$user['id'], (int) $id]);
            flash('success', 'Saved to favorites.');
        }
        redirect('product/' . rawurlencode((string) ($_POST['slug'] ?? '')));
    }

    public static function deleteAddress(string $id): void
    {
        $user = Auth::requireUser();
        verify_csrf();
        Database::execute('DELETE FROM addresses WHERE id=? AND user_id=?', [(int) $id, $user['id']]);
        flash('success', 'Address removed.');
        redirect('account');
    }

    public static function checkout(): void
    {
        $user = Auth::user();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            if (trim((string) ($_POST['website'] ?? '')) !== '') {
                http_response_code(202);
                render('checkout', ['title' => 'Checkout', 'user' => $user]);
                return;
            }
            $ipLimit = RateLimiter::hit('checkout.ip.15m', RateLimiter::ip(), 8, 900, 1800);
            $dailyLimit = RateLimiter::hit('checkout.ip.day', RateLimiter::ip(), 30, 86400, 86400);
            $contact = strtolower(trim((string) ($_POST['customer_email'] ?? ''))) . '|' . preg_replace('/\D+/', '', (string) ($_POST['customer_phone'] ?? ''));
            $contactLimit = RateLimiter::hit('checkout.contact', $contact, 5, 3600, 3600);
            if (!$ipLimit['allowed'] || !$dailyLimit['allowed'] || !$contactLimit['allowed']) {
                $retry = max((int) $ipLimit['retry_after'], (int) $dailyLimit['retry_after'], (int) $contactLimit['retry_after']);
                http_response_code(429);
                header('Retry-After: ' . max(1, $retry));
                flash('error', 'Too many order attempts were received. Wait before trying again or contact the store.');
                render('checkout', ['title' => 'Checkout', 'user' => $user]);
                return;
            }
            try {
                $order = OrderService::create($_POST, $user);
                $_SESSION['last_order_id'] = $order['id'];
                $_SESSION['last_order_number'] = $order['number'];
                redirect('order/' . rawurlencode($order['number']) . '/success');
            } catch (RuntimeException $error) {
                flash('error', $error->getMessage());
            }
        }
        render('checkout', ['title' => 'Checkout', 'user' => $user]);
    }

    public static function orderSuccess(string $number): void
    {
        if (($number !== ($_SESSION['last_order_number'] ?? '')) && !Auth::isStaff()) {
            http_response_code(404);
            render('errors/404', ['title' => 'Order not found']);
            return;
        }
        $order = Database::one('SELECT * FROM orders WHERE order_number=?', [$number]);
        render('order-success', ['title' => 'Order received', 'order' => $order]);
    }

    public static function unsubscribe(string $token): void
    {
        $confirmed = $_SERVER['REQUEST_METHOD'] === 'POST';
        $customer = EmailService::unsubscribe($token, $confirmed);
        render('unsubscribe', ['title' => 'Email preferences', 'valid' => $customer !== null, 'confirmed' => $confirmed && $customer !== null, 'token' => $token]);
    }

    public static function setup(): void
    {
        if ((int) Database::pdo()->query("SELECT COUNT(*) FROM users WHERE role='owner'")->fetchColumn() > 0) {
            redirect('login');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $limit = RateLimiter::hit('setup.ip', RateLimiter::ip(), 5, 3600, 3600);
            if (!$limit['allowed']) {
                http_response_code(429);
                header('Retry-After: ' . (int) $limit['retry_after']);
                flash('error', 'Too many setup attempts. Wait before trying again.');
                render('auth/setup', ['title' => 'Set up owner account', 'setupReady' => true]);
                return;
            }
            $setupToken = (string) getenv('APP_SETUP_TOKEN');
            if (strlen($setupToken) < 32 || !hash_equals($setupToken, (string) ($_POST['setup_token'] ?? ''))) {
                Audit::record('setup.token_rejected', 'setup');
                flash('error', 'The one-time setup key was not accepted.');
                render('auth/setup', ['title' => 'Set up owner account', 'setupReady' => strlen($setupToken) >= 32]);
                return;
            }
            $password = (string) ($_POST['password'] ?? '');
            if (trim((string) ($_POST['name'] ?? '')) === '' || strlen($password) < 12 || !filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Use your name, a valid email and a password of at least 12 characters.');
            } else {
                $id = Auth::register((string) $_POST['name'], (string) $_POST['email'], (string) ($_POST['phone'] ?? ''), $password, 'owner');
                Auth::loginById($id);
                self::audit('setup.completed', 'user', (string) $id);
                redirect('admin');
            }
        }
        render('auth/setup', ['title' => 'Set up owner account', 'setupReady' => strlen((string) getenv('APP_SETUP_TOKEN')) >= 32]);
    }

    public static function admin(): void
    {
        $staff = Auth::requireStaff();
        $canViewOrders = Auth::can('orders.view', $staff);
        $canViewProducts = Auth::can('products.view', $staff);
        $canViewReports = Auth::can('reports.view', $staff);
        if ($canViewReports || Auth::can('customers.view', $staff)) {
            CustomerService::syncDirectory(500);
        }
        render('admin/dashboard', [
            'title' => $staff['role'] === 'owner' ? 'Owner dashboard' : 'Team dashboard',
            'counts' => [
                'attention' => $canViewOrders ? (int) Database::pdo()->query("SELECT COUNT(*) FROM orders WHERE status IN ('awaiting_confirmation','confirmed','preparing')")->fetchColumn() : 0,
                'products' => $canViewProducts ? (int) Database::pdo()->query("SELECT COUNT(*) FROM products WHERE status!='archived'")->fetchColumn() : 0,
                'sold_out' => $canViewProducts ? (int) Database::pdo()->query("SELECT COUNT(*) FROM product_variants WHERE stock_status='sold_out'")->fetchColumn() : 0,
                'low_stock' => $canViewProducts ? (int) Database::pdo()->query("SELECT COUNT(*) FROM product_variants WHERE stock_status='low_stock'")->fetchColumn() : 0,
            ],
            'orders' => $canViewOrders ? Database::all('SELECT * FROM orders ORDER BY created_at DESC LIMIT 8') : [],
            'sales' => $canViewReports ? ReportingService::report(['range' => 'today'])['kpis'] : null,
        ]);
    }

    public static function adminSecurity(): void
    {
        $owner = Auth::requireStaff();
        if ($owner['role'] !== 'owner') {
            http_response_code(403);
            exit('Only the owner can view the security center.');
        }
        $configuredDb = (string) (getenv('DB_PATH') ?: APP_ROOT . '/storage/shop.sqlite');
        $publicRoot = str_replace('\\', '/', realpath(APP_ROOT . '/public') ?: APP_ROOT . '/public');
        $dbPath = str_replace('\\', '/', $configuredDb);
        if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $configuredDb)) {
            $dbPath = str_replace('\\', '/', APP_ROOT . '/' . ltrim($configuredDb, './'));
        }
        $checks = [
            'Production mode' => (string) getenv('APP_ENV') === 'production',
            'Strong APP_KEY' => strlen((string) getenv('APP_KEY')) >= 32,
            'HTTPS request' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'Database outside public folder' => !str_starts_with($dbPath, $publicRoot . '/'),
            'Staff MFA requirement enabled' => Store::setting('staff_mfa_required', false) === true,
            'Owner MFA enabled' => !empty($owner['mfa_enabled_at']),
        ];
        render('admin/security', [
            'title' => 'Security center',
            'checks' => $checks,
            'staffSecurity' => Database::all("SELECT id,name,email,role,status,must_change_password,mfa_enabled_at,last_login_at FROM users WHERE role IN ('owner','manager','staff') ORDER BY CASE role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END,name"),
            'events' => Database::all("SELECT a.*,u.name user_name FROM audit_events a LEFT JOIN users u ON u.id=a.user_id WHERE a.action LIKE 'auth.%' OR a.action LIKE 'setup.%' OR a.action LIKE 'staff.%' OR a.action='customers.exported' ORDER BY a.id DESC LIMIT 100"),
        ]);
    }

    public static function adminReports(): void
    {
        Auth::requirePermission('reports.view');
        CustomerService::syncDirectory(2000);
        render('admin/reports', [
            'title' => 'Sales & Reports',
            'report' => ReportingService::report($_GET),
        ]);
    }

    public static function adminCustomers(): void
    {
        Auth::requirePermission('customers.view');
        CustomerService::syncDirectory(2000);
        $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? (string) $_GET['status'] : '';
        $marketing = ($_GET['marketing'] ?? '') === '1';
        $requestedPerPage = (int) ($_GET['per_page'] ?? 20);
        $perPage = in_array($requestedPerPage, [20, 50, 100], true) ? $requestedPerPage : 20;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.city LIKE ? OR c.postal_code LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term, $term);
        }
        if ($status !== '') {
            $where[] = 'c.status=?';
            $params[] = $status;
        }
        if ($marketing) {
            $where[] = 'c.marketing_opt_in=1';
        }
        $whereSql = implode(' AND ', $where);
        $count = (int) (Database::one("SELECT COUNT(*) count FROM customer_profiles c WHERE {$whereSql}", $params)['count'] ?? 0);
        $totalPages = max(1, (int) ceil($count / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $customers = Database::all(
            "SELECT c.*,
                    COUNT(o.id) order_count,
                    COALESCE(SUM(CASE WHEN o.status='completed' AND o.payment_status='paid' THEN o.total_cents ELSE 0 END),0) lifetime_value_cents,
                    MAX(o.created_at) last_order_at,
                    SUM(CASE WHEN o.order_source='pos' THEN 1 ELSE 0 END) pos_orders,
                    SUM(CASE WHEN o.order_source='online' THEN 1 ELSE 0 END) online_orders
             FROM customer_profiles c LEFT JOIN orders o ON o.customer_id=c.id
             WHERE {$whereSql}
             GROUP BY c.id
             ORDER BY COALESCE(MAX(o.created_at),c.last_seen_at) DESC,c.name
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $summary = Database::one(
            "SELECT COUNT(*) customers,
                    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) active,
                    SUM(CASE WHEN marketing_opt_in=1 THEN 1 ELSE 0 END) marketing
             FROM customer_profiles"
        ) ?? ['customers' => 0, 'active' => 0, 'marketing' => 0];
        $summary['lifetime_value_cents'] = (int) (Database::one(
            "SELECT COALESCE(SUM(total_cents),0) total FROM orders WHERE status='completed' AND payment_status='paid' AND customer_id IS NOT NULL"
        )['total'] ?? 0);
        render('admin/customers', [
            'title' => 'Customers',
            'customers' => $customers,
            'summary' => $summary,
            'filters' => ['q' => $search, 'status' => $status, 'marketing' => $marketing, 'per_page' => $perPage],
            'pagination' => ['page' => $page, 'pages' => $totalPages, 'total' => $count],
        ]);
    }

    public static function adminCustomerSearch(): void
    {
        $staff = Auth::requirePermission('pos.access');
        header('Content-Type: application/json; charset=utf-8');
        if (!Auth::can('customers.view', $staff)) {
            http_response_code(403);
            echo json_encode(['customers' => []]);
            return;
        }
        $limit = RateLimiter::hit('customer.search', (string) $staff['id'], 60, 60, 60);
        if (!$limit['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . (int) $limit['retry_after']);
            echo json_encode(['customers' => [], 'error' => 'rate_limited']);
            return;
        }
        CustomerService::syncDirectory(300);
        $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
        if (mb_strlen($search) < 2) {
            echo json_encode(['customers' => []]);
            return;
        }
        $term = '%' . $search . '%';
        $customers = Database::all(
            "SELECT id,name,email,phone,marketing_opt_in,address1,city,state,postal_code
             FROM customer_profiles
             WHERE status='active' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)
             ORDER BY last_seen_at DESC LIMIT 8",
            [$term, $term, $term]
        );
        echo json_encode(['customers' => $customers], JSON_UNESCAPED_SLASHES);
    }

    public static function adminCustomer(string $id): void
    {
        Auth::requirePermission('customers.view');
        CustomerService::syncDirectory(2000);
        $customerId = (int) $id;
        $customer = Database::one(
            "SELECT c.*,COUNT(o.id) order_count,
                    COALESCE(SUM(CASE WHEN o.status='completed' AND o.payment_status='paid' THEN o.total_cents ELSE 0 END),0) lifetime_value_cents,
                    COALESCE(AVG(CASE WHEN o.status='completed' AND o.payment_status='paid' THEN o.total_cents END),0) average_order_cents,
                    MAX(o.created_at) last_order_at
             FROM customer_profiles c LEFT JOIN orders o ON o.customer_id=c.id WHERE c.id=? GROUP BY c.id",
            [$customerId]
        );
        if (!$customer) {
            http_response_code(404);
            render('errors/404', ['title' => 'Customer not found']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Auth::requirePermission('customers.edit');
            verify_csrf();
            try {
                CustomerService::update($customerId, $_POST);
                self::audit('customer.updated', 'customer', (string) $customerId, ['status' => $_POST['status'] ?? 'active']);
                flash('success', 'Customer profile updated. Historical orders were not changed.');
            } catch (RuntimeException $error) {
                flash('error', $error->getMessage());
            }
            redirect('admin/customers/' . $customerId);
        }
        $orders = Database::all('SELECT * FROM orders WHERE customer_id=? ORDER BY created_at DESC', [$customerId]);
        $products = Database::all(
            "SELECT oi.product_name,oi.variant_label,SUM(oi.quantity) units,SUM(oi.line_total_cents) revenue
             FROM order_items oi JOIN orders o ON o.id=oi.order_id
             WHERE o.customer_id=? AND o.status='completed' AND o.payment_status='paid'
             GROUP BY oi.product_name,oi.variant_label ORDER BY units DESC,revenue DESC LIMIT 8",
            [$customerId]
        );
        $addresses = Database::all(
            "SELECT address1,address2,city,state,postal_code,MAX(created_at) last_used
             FROM orders WHERE customer_id=? AND TRIM(address1)!=''
             GROUP BY address1,address2,city,state,postal_code ORDER BY last_used DESC",
            [$customerId]
        );
        render('admin/customer', [
            'title' => $customer['name'],
            'customer' => $customer,
            'orders' => $orders,
            'products' => $products,
            'addresses' => $addresses,
        ]);
    }

    public static function adminCustomersExport(): void
    {
        $user = Auth::requirePermission('customers.export');
        verify_csrf();
        $limit = RateLimiter::hit('customers.export', (string) $user['id'], 5, 3600, 3600);
        if (!$limit['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . (int) $limit['retry_after']);
            exit('Too many customer exports. Try again later.');
        }
        CustomerService::syncDirectory(5000);
        $scope = ($_POST['scope'] ?? '') === 'marketing' ? 'marketing' : 'all';
        $where = $scope === 'marketing' ? 'WHERE c.marketing_opt_in=1 AND c.status=\'active\'' : '';
        $customers = Database::all(
            "SELECT c.*,
                    COUNT(o.id) order_count,
                    COALESCE(SUM(CASE WHEN o.status='completed' AND o.payment_status='paid' THEN o.total_cents ELSE 0 END),0) lifetime_value_cents,
                    MAX(o.created_at) last_order_at
             FROM customer_profiles c LEFT JOIN orders o ON o.customer_id=c.id {$where}
             GROUP BY c.id ORDER BY c.name"
        );
        self::audit('customers.exported', 'customer_export', $scope, ['count' => count($customers)]);
        $filename = 'customers-' . $scope . '-' . date('Y-m-d-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, private');
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Unable to create customer export.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Name','Email','Phone','Address 1','Address 2','City','State','ZIP','Status','Marketing opt-in','Orders','Lifetime spend','First seen','Last seen','Last order']);
        foreach ($customers as $customer) {
            fputcsv($output, [
                self::safeCsvCell($customer['name']), self::safeCsvCell($customer['email']), self::safeCsvCell($customer['phone']),
                self::safeCsvCell($customer['address1']), self::safeCsvCell($customer['address2']), self::safeCsvCell($customer['city']),
                self::safeCsvCell($customer['state']), self::safeCsvCell($customer['postal_code']), $customer['status'],
                (int) $customer['marketing_opt_in'] === 1 ? 'Yes' : 'No', (int) $customer['order_count'],
                number_format((int) $customer['lifetime_value_cents'] / 100, 2, '.', ''),
                $customer['first_seen_at'], $customer['last_seen_at'], $customer['last_order_at'],
            ]);
        }
        fclose($output);
        exit;
    }

    public static function adminCustomersImport(): void
    {
        $user = Auth::requirePermission('customers.import');
        $preview = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $limit = RateLimiter::hit('customers.import', (string) $user['id'], 10, 3600, 3600);
            if (!$limit['allowed']) {
                http_response_code(429);
                header('Retry-After: ' . (int) $limit['retry_after']);
                flash('error', 'Too many import attempts. Try again later.');
                render('admin/customer-import', ['title' => 'Import customers', 'preview' => null]);
                return;
            }
            try {
                $preview = CustomerImportService::preview($_FILES['customer_file'] ?? [], (int) $user['id']);
                self::audit('customers.import.previewed', 'customer_import', (string) $preview['job_id'], ['rows' => $preview['total']]);
            } catch (RuntimeException $error) {
                flash('error', $error->getMessage());
            }
        }
        render('admin/customer-import', ['title' => 'Import customers', 'preview' => $preview]);
    }

    public static function adminCustomersImportTemplate(): void
    {
        Auth::requirePermission('customers.import');
        CustomerImportService::template();
        exit;
    }

    public static function adminCustomersImportConfirm(): void
    {
        $user = Auth::requirePermission('customers.import'); verify_csrf();
        try {
            $result = CustomerImportService::confirm((int) $user['id']);
            self::audit('customers.import.completed', 'customer_import', 'latest', $result);
            flash('success', "Import complete: {$result['created']} created and {$result['updated']} matched/updated.");
            redirect('admin/customers');
        } catch (RuntimeException $error) { flash('error', $error->getMessage()); redirect('admin/customers/import'); }
    }

    public static function adminCustomersImportCancel(): void
    {
        $user = Auth::requirePermission('customers.import'); verify_csrf();
        CustomerImportService::cancel((int) $user['id']);
        flash('success', 'Import cancelled and the encrypted preview was removed.'); redirect('admin/customers');
    }

    private static function safeCsvCell(mixed $value): string
    {
        $cell = (string) $value;
        return preg_match('/^[\s]*[=+\-@]/u', $cell) === 1 ? "'" . $cell : $cell;
    }

    public static function adminProducts(): void
    {
        Auth::requirePermission('products.view');
        $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $category = trim((string) ($_GET['category'] ?? ''));
        $perPage = (int) ($_GET['per_page'] ?? 20);
        if (!in_array($perPage, [20, 50, 100], true)) {
            $perPage = 20;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $where = ['1=1'];
        $params = [];
        if ($category !== '') {
            $where[] = 'c.slug=:category';
            $params[':category'] = $category;
        }
        if ($search !== '') {
            $where[] = "(p.name LIKE :search OR p.brand LIKE :search OR p.description LIKE :search OR p.potency LIKE :search OR p.strain_type LIKE :search OR c.name LIKE :search OR EXISTS (
                SELECT 1 FROM product_variants sv WHERE sv.product_id=p.id AND (sv.label LIKE :search OR sv.flavors LIKE :search OR sv.sku LIKE :search)
            ))";
            $params[':search'] = '%' . $search . '%';
        }

        $pdo = Database::pdo();
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM products p JOIN categories c ON c.id=p.category_id WHERE ' . implode(' AND ', $where)
        );
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $productStmt = $pdo->prepare(
            "SELECT p.*, c.name category_name,
                    MIN(COALESCE(v.sale_price_cents,v.price_cents)) from_price,
                    COUNT(v.id) variant_count,
                    SUM(CASE WHEN v.stock_quantity IS NOT NULL THEN v.stock_quantity ELSE 0 END) tracked_quantity,
                    SUM(CASE WHEN v.stock_quantity IS NOT NULL THEN 1 ELSE 0 END) tracked_variants,
                    SUM(CASE WHEN v.id IS NOT NULL AND v.stock_quantity IS NULL THEN 1 ELSE 0 END) untracked_variants,
                    SUM(CASE WHEN v.stock_status='low_stock' THEN 1 ELSE 0 END) low_stock_variants,
                    SUM(CASE WHEN v.stock_status='sold_out' THEN 1 ELSE 0 END) sold_out_variants,
                    GROUP_CONCAT(v.label || ' ' || COALESCE(v.flavors,''), ' ') variant_search
             FROM products p
             JOIN categories c ON c.id=p.category_id
             LEFT JOIN product_variants v ON v.product_id=p.id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY p.id
             ORDER BY c.position,p.name
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $productStmt->bindValue($key, $value);
        }
        $productStmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $productStmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $productStmt->execute();
        $products = $productStmt->fetchAll();

        render('admin/products', [
            'title' => 'Products',
            'products' => $products,
            'categories' => Store::categories(),
            'filters' => ['q' => $search, 'category' => $category, 'per_page' => $perPage],
            'pagination' => [
                'page' => $page,
                'total_pages' => $totalPages,
                'total' => $total,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
        ]);
    }

    public static function adminProduct(?string $id = null): void
    {
        Auth::requirePermission($id ? 'products.edit' : 'products.create');
        $product = $id ? Database::one('SELECT * FROM products WHERE id=?', [(int) $id]) : null;
        if ($id && !$product) {
            http_response_code(404); render('errors/404', ['title' => 'Product not found']); return;
        }
        $variants = $product
            ? Database::all('SELECT * FROM product_variants WHERE product_id=? ORDER BY position,id', [$product['id']])
            : [['label' => 'Single', 'price' => '', 'sale_price' => '', 'flavors' => '', 'stock_status' => 'in_stock', 'stock_quantity' => '']];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $name = trim((string) ($_POST['name'] ?? ''));
            try {
                if ($name === '') {
                    throw new RuntimeException('Product name is required.');
                }
                $variantsToSave = self::normalizeProductVariants($_POST['variants'] ?? []);
                $imagePath = $product['image_path'] ?? null;
                if (!empty($_FILES['image']['tmp_name'])) {
                    $imagePath = self::saveUpload($_FILES['image']);
                }
                $slug = self::uniqueSlug((string) ($_POST['brand'] ?? '') . '-' . $name, $product['id'] ?? null);
                $status = in_array($_POST['status'] ?? '', ['draft','active','sold_out','archived'], true) ? $_POST['status'] : 'draft';
                $pdo = Database::pdo();
                $pdo->beginTransaction();
                try {
                    if ($product) {
                        $productId = (int) $product['id'];
                        Database::execute('UPDATE products SET category_id=?,name=?,brand=?,slug=?,description=?,image_path=?,strain_type=?,potency=?,status=?,featured=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [
                            (int) ($_POST['category_id'] ?? 0), $name, trim((string) ($_POST['brand'] ?? '')), $slug,
                            trim((string) ($_POST['description'] ?? '')), $imagePath, trim((string) ($_POST['strain_type'] ?? '')),
                            trim((string) ($_POST['potency'] ?? '')), $status, isset($_POST['featured']) ? 1 : 0, $productId,
                        ]);
                    } else {
                        Database::execute('INSERT INTO products (category_id,name,brand,slug,description,image_path,strain_type,potency,status,featured) VALUES (?,?,?,?,?,?,?,?,?,?)', [
                            (int) ($_POST['category_id'] ?? 0), $name, trim((string) ($_POST['brand'] ?? '')), $slug,
                            trim((string) ($_POST['description'] ?? '')), $imagePath, trim((string) ($_POST['strain_type'] ?? '')),
                            trim((string) ($_POST['potency'] ?? '')), $status, isset($_POST['featured']) ? 1 : 0,
                        ]);
                        $productId = (int) $pdo->lastInsertId();
                    }

                    $existingRows = Database::all('SELECT id FROM product_variants WHERE product_id=?', [$productId]);
                    $existingIds = array_fill_keys(array_map(static fn(array $row): int => (int) $row['id'], $existingRows), true);
                    $keptIds = [];
                    foreach ($variantsToSave as $position => $variant) {
                        $variantId = (int) ($variant['id'] ?? 0);
                        $values = [
                            $variant['label'], $variant['price_cents'], $variant['sale_price_cents'], $variant['flavors'],
                            $variant['stock_status'], $variant['stock_quantity'], $position,
                        ];
                        if ($variantId > 0 && isset($existingIds[$variantId])) {
                            Database::execute('UPDATE product_variants SET label=?,price_cents=?,sale_price_cents=?,flavors=?,stock_status=?,stock_quantity=?,position=? WHERE id=? AND product_id=?', [...$values, $variantId, $productId]);
                            $keptIds[$variantId] = true;
                        } else {
                            Database::execute('INSERT INTO product_variants (product_id,label,price_cents,sale_price_cents,flavors,stock_status,stock_quantity,position) VALUES (?,?,?,?,?,?,?,?)', [$productId, ...$values]);
                            $keptIds[(int) $pdo->lastInsertId()] = true;
                        }
                    }
                    foreach (array_keys($existingIds) as $existingId) {
                        if (!isset($keptIds[$existingId])) {
                            Database::execute('DELETE FROM product_variants WHERE id=? AND product_id=?', [$existingId, $productId]);
                        }
                    }

                    self::audit($product ? 'product.updated' : 'product.created', 'product', (string) $productId, ['variants' => count($variantsToSave)]);
                    $pdo->commit();
                } catch (\Throwable $error) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $error;
                }
                flash('success', 'Product saved.');
                redirect('admin/products');
            } catch (RuntimeException $error) {
                flash('error', $error->getMessage());
                $product = array_merge($product ?? [], [
                    'name' => $name,
                    'brand' => trim((string) ($_POST['brand'] ?? '')),
                    'category_id' => (int) ($_POST['category_id'] ?? 0),
                    'description' => trim((string) ($_POST['description'] ?? '')),
                    'strain_type' => trim((string) ($_POST['strain_type'] ?? '')),
                    'potency' => trim((string) ($_POST['potency'] ?? '')),
                    'status' => (string) ($_POST['status'] ?? 'draft'),
                    'featured' => isset($_POST['featured']) ? 1 : 0,
                ]);
                $submittedVariants = $_POST['variants'] ?? [];
                if (is_array($submittedVariants) && $submittedVariants !== []) {
                    $variants = array_values(array_filter($submittedVariants, 'is_array'));
                }
            }
        }
        render('admin/product-form', ['title' => $id ? 'Edit product' : 'Add product', 'product' => $product, 'variants' => $variants, 'categories' => Store::categories()]);
    }

    public static function adminProductArchive(string $id): void
    {
        Auth::requirePermission('products.archive');
        verify_csrf();
        $product = Database::one('SELECT id,name,status FROM products WHERE id=?', [(int) $id]);
        if (!$product) {
            flash('error', 'Product not found.');
        } else {
            Database::execute("UPDATE products SET status='archived',updated_at=CURRENT_TIMESTAMP WHERE id=?", [(int) $product['id']]);
            self::audit('product.archived', 'product', (string) $product['id'], ['name' => $product['name']]);
            flash('success', 'Product archived. Existing receipts and order history were preserved.');
        }
        redirect('admin/products');
    }

    public static function adminPos(): void
    {
        $staff = Auth::requirePermission('pos.access');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Auth::can('pos.complete', $staff)) {
                http_response_code(403);
                exit('Your staff account can open the POS but cannot complete sales.');
            }
            verify_csrf();
            try {
                $order = OrderService::createPos($_POST, $staff);
                self::audit('pos.sale.completed', 'order', (string) $order['id'], ['number' => $order['number']]);
                redirect('admin/pos/receipt/' . $order['id']);
            } catch (RuntimeException $error) {
                flash('error', $error->getMessage());
            }
        }
        render('admin/pos', [
            'title' => 'Point of sale',
            'catalog' => Store::posCatalog(),
            'categories' => Store::categories(),
            'canComplete' => Auth::can('pos.complete', $staff),
            'canDiscount' => Auth::can('pos.discount', $staff),
        ]);
    }

    public static function adminPosReceipt(string $id): void
    {
        $staff = Auth::requirePermission('pos.access');
        $order = Database::one(
            'SELECT o.*,u.name created_by_name FROM orders o LEFT JOIN users u ON u.id=o.created_by_user_id WHERE o.id=? AND o.order_source=?',
            [(int) $id, 'pos']
        );
        if (!$order) {
            http_response_code(404);
            render('errors/404', ['title' => 'Receipt not found']);
            return;
        }
        if (!Auth::can('orders.view', $staff) && (int) ($order['created_by_user_id'] ?? 0) !== (int) $staff['id']) {
            http_response_code(403);
            exit('You may only view receipts for sales completed with your account.');
        }
        render('admin/pos-receipt', [
            'title' => 'Receipt ' . $order['order_number'],
            'order' => $order,
            'items' => Database::all('SELECT * FROM order_items WHERE order_id=? ORDER BY id', [$order['id']]),
        ]);
    }

    public static function adminOrders(): void
    {
        Auth::requirePermission('orders.view');
        render('admin/orders', ['title' => 'Orders', 'orders' => Database::all('SELECT * FROM orders ORDER BY created_at DESC')]);
    }

    public static function adminOrder(string $id): void
    {
        Auth::requirePermission('orders.view');
        $order = Database::one('SELECT * FROM orders WHERE id=?', [(int)$id]);
        if (!$order) { http_response_code(404); render('errors/404',['title'=>'Order not found']); return; }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Auth::requirePermission('orders.manage');
            verify_csrf();
            $statuses=['awaiting_confirmation','confirmed','preparing','ready','out_for_delivery','completed','cancelled'];
            $paymentStatuses=['pending','due','paid','failed','refunded','cancelled'];
            $status=in_array($_POST['status']??'', $statuses, true)?$_POST['status']:$order['status'];
            $paymentStatus=in_array($_POST['payment_status']??'', $paymentStatuses, true)?$_POST['payment_status']:$order['payment_status'];
            try {
                if ($order['status'] === 'cancelled' && $status !== 'cancelled') {
                    throw new RuntimeException('Cancelled orders are final because their inventory has already been restored.');
                }
                $pdo = Database::pdo();
                $pdo->beginTransaction();
                try {
                    Database::execute('UPDATE orders SET status=?,payment_status=?,staff_notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$status,$paymentStatus,mb_substr(trim((string)($_POST['staff_notes'] ?? '')),0,2000),$order['id']]);
                    if ($status === 'cancelled' && $order['status'] !== 'cancelled') {
                        OrderService::releaseInventory((int) $order['id']);
                    }
                    self::audit('order.updated','order',(string)$order['id'],['status'=>$status]);
                    $pdo->commit();
                } catch (\Throwable $error) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $error;
                }
                flash('success', $status === 'cancelled' && $order['status'] !== 'cancelled' ? 'Order cancelled and reserved stock restored.' : 'Order updated.');
                redirect('admin/orders/'.$order['id']);
            } catch (RuntimeException $error) {
                flash('error', $error->getMessage());
                redirect('admin/orders/'.$order['id']);
            }
        }
        render('admin/order', ['title'=>'Order '.$order['order_number'],'order'=>$order,'items'=>Database::all('SELECT * FROM order_items WHERE order_id=?',[$order['id']])]);
    }

    public static function adminSettings(): void
    {
        Auth::requirePermission('settings.manage');
        $schema = self::settingsSchema();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            if (isset($_POST['ordering_enabled']) && !isset($_POST['pickup_enabled']) && !isset($_POST['delivery_enabled'])) {
                flash('error', 'Enable pickup or delivery before turning on online ordering.');
                redirect('admin/settings');
            }
            if (isset($_POST['pos_enabled']) && !isset($_POST['pos_cash_enabled']) && !isset($_POST['pos_external_card_enabled'])) {
                flash('error', 'Enable cash or external card terminal before turning on the POS.');
                redirect('admin/settings');
            }
            $taxRate = max(0, min(30, (float) ($_POST['pos_tax_rate'] ?? 0)));
            $_POST['pos_tax_rate'] = rtrim(rtrim(number_format($taxRate, 3, '.', ''), '0'), '.');
            $_POST['pos_max_discount_percent'] = max(0, min(100, (int) ($_POST['pos_max_discount_percent'] ?? 20)));
            $timezone = trim((string) ($_POST['report_timezone'] ?? 'America/New_York'));
            try {
                $_POST['report_timezone'] = (new \DateTimeZone($timezone))->getName();
            } catch (\Throwable) {
                $_POST['report_timezone'] = 'America/New_York';
            }
            $_POST['business_city'] = mb_substr(trim((string) ($_POST['business_city'] ?? '')), 0, 100);
            foreach ($schema as $key => $type) {
                $value = $type === 'bool' ? isset($_POST[$key]) : ($_POST[$key] ?? '');
                if ($type === 'int' && str_ends_with($key, '_cents')) {
                    $value = (int) round(((float) $value) * 100);
                }
                Store::setSetting($key, $value, $type);
            }
            self::audit('settings.updated','settings','store');
            flash('success','Store settings saved.'); redirect('admin/settings');
        }
        render('admin/settings', ['title'=>'Store settings','schema'=>$schema]);
    }

    public static function adminOrderEmailReceipt(string $id): void
    {
        $user = Auth::requirePermission('emails.receipts'); verify_csrf();
        $order = Database::one('SELECT * FROM orders WHERE id=?', [(int) $id]);
        $email = strtolower(trim((string) ($_POST['email'] ?? $order['customer_email'] ?? '')));
        if (!EmailService::readiness(false)['ready']) {
            flash('error', 'Enable email and configure a valid From address in Settings first.');
        } elseif (!$order || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid receipt email address.');
        } elseif (!EmailService::queueReceipt((int) $order['id'], $email, (int) $user['id'], true)) {
            flash('error', 'The receipt could not be queued.');
        } else {
            self::audit('receipt.queued', 'order', (string) $order['id'], ['recipient' => $email]);
            flash('success', 'Receipt queued. Its sent or failed status will be recorded here.');
        }
        redirect('admin/orders/' . (int) $id);
    }

    public static function adminEmail(): void
    {
        Auth::requirePermission('campaigns.manage');
        render('admin/email', [
            'title' => 'Email center', 'readiness' => EmailService::readiness(true),
            'campaigns' => Database::all('SELECT * FROM email_campaigns ORDER BY created_at DESC LIMIT 30'),
            'queue' => Database::all('SELECT * FROM email_queue ORDER BY created_at DESC LIMIT 30'),
            'products' => Database::all("SELECT id,name,brand,created_at FROM products WHERE status='active' ORDER BY created_at DESC,name LIMIT 20"),
            'counts' => Database::one("SELECT SUM(status='queued') queued,SUM(status='sent') sent,SUM(status='failed') failed FROM email_queue") ?: [],
        ]);
    }

    public static function adminEmailCampaignCreate(): void
    {
        $user = Auth::requirePermission('campaigns.manage'); verify_csrf();
        try {
            $id = CampaignService::createDraft($_POST, (int) $user['id']);
            self::audit('campaign.created', 'email_campaign', (string) $id);
            flash('success', 'Campaign saved as a draft. Review it before approving delivery.');
        } catch (RuntimeException $error) { flash('error', $error->getMessage()); }
        redirect('admin/email');
    }

    public static function adminEmailCampaignApprove(string $id): void
    {
        $user = Auth::requirePermission('campaigns.manage'); verify_csrf();
        $limit = RateLimiter::hit('campaign.approve', (string) $user['id'], 10, 3600, 3600);
        if (!$limit['allowed']) {
            flash('error', 'Too many campaign approval attempts. Try again later.');
            redirect('admin/email');
        }
        try {
            $count = CampaignService::approve((int) $id, (int) $user['id']);
            self::audit('campaign.approved', 'email_campaign', $id, ['recipients' => $count]);
            flash('success', "Campaign approved and queued for {$count} eligible customers.");
        } catch (RuntimeException $error) { flash('error', $error->getMessage()); }
        redirect('admin/email');
    }

    public static function adminEmailCampaignCancel(string $id): void
    {
        Auth::requirePermission('campaigns.manage'); verify_csrf();
        Database::execute("UPDATE email_campaigns SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='draft'", [(int) $id]);
        self::audit('campaign.cancelled', 'email_campaign', $id); flash('success', 'Draft campaign cancelled.'); redirect('admin/email');
    }

    public static function adminEmailQueueRun(): void
    {
        Auth::requirePermission('campaigns.manage'); verify_csrf();
        $result = EmailService::processQueue(25);
        self::audit('email.queue.processed', 'email_queue', 'manual', $result);
        flash('success', "Processed {$result['processed']} messages: {$result['sent']} sent, {$result['failed']} permanently failed.");
        redirect('admin/email');
    }

    public static function adminPromotions(): void
    {
        Auth::requirePermission('promotions.manage');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') {
                flash('error', 'Promotion title is required.');
            } else {
                Database::execute('INSERT INTO promotions (title,description,starts_at,ends_at,active,position) VALUES (?,?,?,?,1,?)', [
                    $title, trim((string) ($_POST['description'] ?? '')), ($_POST['starts_at'] ?? '') ?: null, ($_POST['ends_at'] ?? '') ?: null, 100,
                ]);
                self::audit('promotion.created','promotion',(string)Database::pdo()->lastInsertId());
                flash('success', 'Promotion created.');
            }
            redirect('admin/promotions');
        }
        render('admin/promotions', ['title'=>'Promotions','promotions'=>Database::all('SELECT * FROM promotions ORDER BY active DESC,position,id DESC')]);
    }

    public static function adminPromotionToggle(string $id): void
    {
        Auth::requirePermission('promotions.manage');
        verify_csrf();
        Database::execute('UPDATE promotions SET active=CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=?', [(int)$id]);
        self::audit('promotion.toggled','promotion',$id);
        redirect('admin/promotions');
    }

    public static function adminPromotionDelete(string $id): void
    {
        Auth::requirePermission('promotions.manage');
        verify_csrf();
        $promotion = Database::one('SELECT id,title FROM promotions WHERE id=?', [(int) $id]);
        if (!$promotion) {
            flash('error', 'Promotion not found. It may already have been deleted.');
        } else {
            Database::execute('DELETE FROM promotions WHERE id=?', [(int) $promotion['id']]);
            self::audit('promotion.deleted', 'promotion', (string) $promotion['id'], ['title' => $promotion['title']]);
            flash('success', 'Promotion deleted.');
        }
        redirect('admin/promotions');
    }

    public static function adminStaff(): void
    {
        $user = Auth::requireStaff();
        if ($user['role'] !== 'owner') {
            http_response_code(403); exit('Only the owner can manage staff.');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $password=(string)($_POST['password']??'');
            if (!filter_var($_POST['email']??'',FILTER_VALIDATE_EMAIL) || strlen($password)<12) {
                flash('error','Use a valid email and a temporary password of at least 12 characters.');
            } else {
                try {
                    $id=Auth::register((string)$_POST['name'],(string)$_POST['email'],(string)($_POST['phone']??''),$password,in_array($_POST['role']??'staff',['staff','manager'],true)?$_POST['role']:'staff');
                    Auth::syncPermissions($id, self::submittedPermissions());
                    self::audit('staff.created','user',(string)$id);
                    flash('success','Staff account created.');
                } catch (\Throwable) { flash('error','A user with that email already exists.'); }
            }
            redirect('admin/staff');
        }
        render('admin/staff',[
            'title'=>'Staff access',
            'staff'=>Database::all("SELECT * FROM users WHERE role IN ('staff','manager','owner') ORDER BY CASE role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END,name"),
            'permissions'=>Auth::permissionDefinitions(),
            'staffPermissions'=>self::staffPermissionMap(),
        ]);
    }

    public static function adminStaffUpdate(string $id): void
    {
        $owner = Auth::requireStaff();
        if ($owner['role'] !== 'owner') {
            http_response_code(403);
            exit('Only the owner can manage staff.');
        }
        verify_csrf();
        $member = Database::one("SELECT * FROM users WHERE id=? AND role IN ('staff','manager')", [(int) $id]);
        if (!$member) {
            flash('error', 'Staff account not found.');
            redirect('admin/staff');
        }
        $role = in_array($_POST['role'] ?? '', ['staff','manager'], true) ? (string) $_POST['role'] : 'staff';
        $status = ($_POST['status'] ?? '') === 'disabled' ? 'disabled' : 'active';
        $temporaryPassword = (string) ($_POST['temporary_password'] ?? '');
        if ($temporaryPassword !== '' && (strlen($temporaryPassword) < 12 || strlen($temporaryPassword) > 200)) {
            flash('error', 'A reset password must be at least 12 characters.');
            redirect('admin/staff');
        }
        if ($temporaryPassword !== '') {
            Database::execute(
                'UPDATE users SET role=?,status=?,password_hash=?,must_change_password=1,password_changed_at=CURRENT_TIMESTAMP,auth_version=auth_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$role,$status,password_hash($temporaryPassword,PASSWORD_DEFAULT),(int)$member['id']]
            );
        } else {
            Database::execute('UPDATE users SET role=?,status=?,auth_version=auth_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$role,$status,(int)$member['id']]);
        }
        Auth::syncPermissions((int) $member['id'], self::submittedPermissions());
        self::audit('staff.permissions.updated', 'user', (string) $member['id'], ['role'=>$role,'status'=>$status,'password_reset'=>$temporaryPassword!=='']);
        flash('success', 'Staff access updated.');
        redirect('admin/staff');
    }

    public static function health(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $checks=['sqlite'=>extension_loaded('pdo_sqlite'),'database'=>false,'uploads_writable'=>is_writable(APP_ROOT.'/public/uploads')];
        try { Database::pdo()->query('SELECT 1')->fetchColumn(); $checks['database']=true; } catch (\Throwable) {}
        $ok=$checks['sqlite']&&$checks['database']&&$checks['uploads_writable'];
        http_response_code($ok?200:503);
        $payload=['ok'=>$ok,'service'=>'thc-li'];
        $user=Auth::user();
        if ($user && $user['role']==='owner') {
            $payload['checks']=$checks;
        }
        echo json_encode($payload, JSON_PRETTY_PRINT);
    }

    private static function settingsSchema(): array
    {
        return [
            'store_name'=>'string','store_tagline'=>'string','store_phone'=>'string','store_email'=>'string','store_status'=>'string','announcement'=>'string','hours'=>'string','license_number'=>'string','required_warning'=>'string','business_city'=>'string','report_timezone'=>'string',
            'ordering_enabled'=>'bool','pickup_enabled'=>'bool','delivery_enabled'=>'bool','guest_checkout_enabled'=>'bool','registration_enabled'=>'bool','manual_confirmation'=>'bool','same_day_enabled'=>'bool','scheduled_enabled'=>'bool',
            'pay_at_pickup_enabled'=>'bool','manual_prepaid_enabled'=>'bool','pickup_minimum_cents'=>'int','delivery_minimum_cents'=>'int','extended_delivery_minimum_cents'=>'int','delivery_fee_cents'=>'int','service_areas'=>'string','extended_areas'=>'string','pickup_address'=>'string',
            'pos_enabled'=>'bool','pos_cash_enabled'=>'bool','pos_external_card_enabled'=>'bool','pos_tax_enabled'=>'bool','pos_tax_rate'=>'string','pos_print_receipt_enabled'=>'bool','pos_email_receipt_enabled'=>'bool','pos_manual_discount_enabled'=>'bool','pos_max_discount_percent'=>'int','customer_capture_enabled'=>'bool','marketing_opt_in_enabled'=>'bool',
            'email_enabled'=>'bool','email_order_confirmation_enabled'=>'bool','email_from_name'=>'string','email_from_address'=>'string','email_reply_to'=>'string','order_notification_email'=>'string','email_dns_verified'=>'bool','marketing_campaigns_enabled'=>'bool','marketing_from_name'=>'string','marketing_from_address'=>'string','marketing_reply_to'=>'string','marketing_physical_address'=>'string','marketing_hopeline'=>'string','marketing_campaign_day'=>'int','staff_mfa_required'=>'bool',
        ];
    }

    private static function submittedPermissions(): array
    {
        $submitted = $_POST['permissions'] ?? [];
        return is_array($submitted) ? array_values(array_filter($submitted, 'is_string')) : [];
    }

    private static function staffPermissionMap(): array
    {
        $map = [];
        foreach (Database::all('SELECT user_id,permission FROM staff_permissions WHERE allowed=1') as $row) {
            $map[(int) $row['user_id']][$row['permission']] = true;
        }
        return $map;
    }

    private static function normalizeProductVariants(mixed $input): array
    {
        if (!is_array($input) || $input === []) {
            throw new RuntimeException('Add at least one product option.');
        }
        if (count($input) > 50) {
            throw new RuntimeException('A product can have up to 50 options.');
        }

        ksort($input, SORT_NUMERIC);
        $variants = [];
        $submittedIds = [];
        foreach (array_values($input) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $number = $index + 1;
            $label = trim((string) ($row['label'] ?? ''));
            $priceValue = trim((string) ($row['price'] ?? ''));
            if ($label === '') {
                throw new RuntimeException("Option {$number} needs a label such as 3.5g or 5 pack.");
            }
            if ($priceValue === '' || !is_numeric($priceValue) || (float) $priceValue <= 0) {
                throw new RuntimeException("Option {$number} needs a valid regular price.");
            }
            $price = (int) round((float) $priceValue * 100);

            $saleValue = trim((string) ($row['sale_price'] ?? ''));
            $salePrice = null;
            if ($saleValue !== '') {
                if (!is_numeric($saleValue) || (float) $saleValue <= 0) {
                    throw new RuntimeException("Option {$number} has an invalid sale price.");
                }
                $salePrice = (int) round((float) $saleValue * 100);
                if ($salePrice >= $price) {
                    throw new RuntimeException("Option {$number} sale price must be lower than its regular price.");
                }
            }

            $quantityValue = trim((string) ($row['stock_quantity'] ?? ''));
            $stockQuantity = null;
            if ($quantityValue !== '') {
                if (!ctype_digit($quantityValue)) {
                    throw new RuntimeException("Option {$number} stock quantity must be a whole number.");
                }
                $stockQuantity = min(999999, (int) $quantityValue);
            }

            $variantId = max(0, (int) ($row['id'] ?? 0));
            if ($variantId > 0) {
                if (isset($submittedIds[$variantId])) {
                    throw new RuntimeException('The same saved option was submitted twice. Please reload and try again.');
                }
                $submittedIds[$variantId] = true;
            }
            $stockStatus = in_array($row['stock_status'] ?? '', ['in_stock','low_stock','sold_out'], true)
                ? $row['stock_status']
                : 'in_stock';
            if ($stockQuantity !== null) {
                $stockStatus = $stockQuantity === 0 ? 'sold_out' : ($stockQuantity <= 5 ? 'low_stock' : 'in_stock');
            }
            $variants[] = [
                'id' => $variantId,
                'label' => $label,
                'price_cents' => $price,
                'sale_price_cents' => $salePrice,
                'flavors' => trim((string) ($row['flavors'] ?? '')),
                'stock_status' => $stockStatus,
                'stock_quantity' => $stockQuantity,
            ];
        }
        if ($variants === []) {
            throw new RuntimeException('Add at least one product option.');
        }
        return $variants;
    }

    private static function saveUpload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 8_000_000) {
            throw new RuntimeException('The product image could not be uploaded.');
        }
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($extensions[$mime])) { throw new RuntimeException('Use a JPG, PNG, or WebP image.'); }
        $name=bin2hex(random_bytes(12)).'.'.$extensions[$mime];
        $directory=APP_ROOT.'/public/uploads/products';
        if(!is_dir($directory)){mkdir($directory,0750,true);}
        @chmod($directory, 0750);
        if(!move_uploaded_file($file['tmp_name'],$directory.'/'.$name)){throw new RuntimeException('Unable to save the image.');}
        @chmod($directory.'/'.$name, 0640);
        return 'uploads/products/'.$name;
    }

    private static function uniqueSlug(string $value, ?int $exclude=null): string
    {
        $base=trim(preg_replace('/[^a-z0-9]+/','-',strtolower($value))??'','-') ?: 'product';
        $slug=$base;$suffix=2;
        while(Database::one('SELECT id FROM products WHERE slug=?'.($exclude?' AND id!=?':''),$exclude?[$slug,$exclude]:[$slug])){$slug=$base.'-'.$suffix++;}
        return $slug;
    }

    private static function audit(string $action,string $type,string $id,array $details=[]): void
    {
        Audit::record($action,$type,$id,$details,Auth::user()['id']??null);
    }
}
