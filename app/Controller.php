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
                redirect(Auth::isStaff() ? 'admin' : 'account');
            }
            flash('error', 'The email or password was not recognized.');
        }
        render('auth/login', ['title' => 'Sign in']);
    }

    public static function register(): void
    {
        if (!Store::setting('registration_enabled', true)) {
            flash('warning', 'New account registration is currently paused.');
            redirect('login');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
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

    public static function setup(): void
    {
        if ((int) Database::pdo()->query("SELECT COUNT(*) FROM users WHERE role='owner'")->fetchColumn() > 0) {
            redirect('login');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $password = (string) ($_POST['password'] ?? '');
            if (strlen($password) < 12 || !filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Use a valid email and a password of at least 12 characters.');
            } else {
                $id = Auth::register((string) $_POST['name'], (string) $_POST['email'], (string) ($_POST['phone'] ?? ''), $password, 'owner');
                Auth::loginById($id);
                self::audit('setup.completed', 'user', (string) $id);
                redirect('admin');
            }
        }
        render('auth/setup', ['title' => 'Set up owner account']);
    }

    public static function admin(): void
    {
        $staff = Auth::requireStaff();
        $canViewOrders = Auth::can('orders.view', $staff);
        $canViewProducts = Auth::can('products.view', $staff);
        render('admin/dashboard', [
            'title' => $staff['role'] === 'owner' ? 'Owner dashboard' : 'Team dashboard',
            'counts' => [
                'attention' => $canViewOrders ? (int) Database::pdo()->query("SELECT COUNT(*) FROM orders WHERE status IN ('awaiting_confirmation','confirmed','preparing')")->fetchColumn() : 0,
                'products' => $canViewProducts ? (int) Database::pdo()->query("SELECT COUNT(*) FROM products WHERE status!='archived'")->fetchColumn() : 0,
                'sold_out' => $canViewProducts ? (int) Database::pdo()->query("SELECT COUNT(*) FROM product_variants WHERE stock_status='sold_out'")->fetchColumn() : 0,
                'low_stock' => $canViewProducts ? (int) Database::pdo()->query("SELECT COUNT(*) FROM product_variants WHERE stock_status='low_stock'")->fetchColumn() : 0,
            ],
            'orders' => $canViewOrders ? Database::all('SELECT * FROM orders ORDER BY created_at DESC LIMIT 8') : [],
        ]);
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
        Auth::requirePermission('pos.access');
        $order = Database::one(
            'SELECT o.*,u.name created_by_name FROM orders o LEFT JOIN users u ON u.id=o.created_by_user_id WHERE o.id=? AND o.order_source=?',
            [(int) $id, 'pos']
        );
        if (!$order) {
            http_response_code(404);
            render('errors/404', ['title' => 'Receipt not found']);
            return;
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
            $status=in_array($_POST['status']??'', $statuses, true)?$_POST['status']:$order['status'];
            try {
                if ($order['status'] === 'cancelled' && $status !== 'cancelled') {
                    throw new RuntimeException('Cancelled orders are final because their inventory has already been restored.');
                }
                $pdo = Database::pdo();
                $pdo->beginTransaction();
                try {
                    Database::execute('UPDATE orders SET status=?,payment_status=?,staff_notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$status,$_POST['payment_status'],trim((string)$_POST['staff_notes']),$order['id']]);
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
        Database::execute('UPDATE users SET role=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$role,$status,(int)$member['id']]);
        Auth::syncPermissions((int) $member['id'], self::submittedPermissions());
        self::audit('staff.permissions.updated', 'user', (string) $member['id'], ['role'=>$role,'status'=>$status]);
        flash('success', 'Staff access updated.');
        redirect('admin/staff');
    }

    public static function health(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $checks=['php'=>PHP_VERSION,'sqlite'=>extension_loaded('pdo_sqlite'),'database'=>false,'uploads_writable'=>is_writable(APP_ROOT.'/public/uploads')];
        try { Database::pdo()->query('SELECT 1')->fetchColumn(); $checks['database']=true; } catch (\Throwable) {}
        $ok=$checks['sqlite']&&$checks['database']&&$checks['uploads_writable'];
        http_response_code($ok?200:503);
        echo json_encode(['ok'=>$ok,'service'=>'local-shop','checks'=>$checks], JSON_PRETTY_PRINT);
    }

    private static function settingsSchema(): array
    {
        return [
            'store_name'=>'string','store_tagline'=>'string','store_phone'=>'string','store_email'=>'string','store_status'=>'string','announcement'=>'string','hours'=>'string','license_number'=>'string','required_warning'=>'string',
            'ordering_enabled'=>'bool','pickup_enabled'=>'bool','delivery_enabled'=>'bool','guest_checkout_enabled'=>'bool','registration_enabled'=>'bool','manual_confirmation'=>'bool','same_day_enabled'=>'bool','scheduled_enabled'=>'bool',
            'pay_at_pickup_enabled'=>'bool','manual_prepaid_enabled'=>'bool','pickup_minimum_cents'=>'int','delivery_minimum_cents'=>'int','extended_delivery_minimum_cents'=>'int','delivery_fee_cents'=>'int','service_areas'=>'string','extended_areas'=>'string','pickup_address'=>'string',
            'pos_enabled'=>'bool','pos_cash_enabled'=>'bool','pos_external_card_enabled'=>'bool','pos_tax_enabled'=>'bool','pos_tax_rate'=>'string','pos_print_receipt_enabled'=>'bool','pos_email_receipt_enabled'=>'bool','pos_manual_discount_enabled'=>'bool','pos_max_discount_percent'=>'int',
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
        if(!is_dir($directory)){mkdir($directory,0770,true);}
        if(!move_uploaded_file($file['tmp_name'],$directory.'/'.$name)){throw new RuntimeException('Unable to save the image.');}
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
        Database::execute('INSERT INTO audit_events (user_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?)', [Auth::user()['id']??null,$action,$type,$id,json_encode($details),$_SERVER['REMOTE_ADDR']??'']);
    }
}
