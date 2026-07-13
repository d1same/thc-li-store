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
        Auth::requireStaff();
        render('admin/dashboard', [
            'title' => 'Owner dashboard',
            'counts' => [
                'attention' => (int) Database::pdo()->query("SELECT COUNT(*) FROM orders WHERE status IN ('awaiting_confirmation','confirmed','preparing')")->fetchColumn(),
                'products' => (int) Database::pdo()->query("SELECT COUNT(*) FROM products WHERE status!='archived'")->fetchColumn(),
                'sold_out' => (int) Database::pdo()->query("SELECT COUNT(*) FROM products WHERE status='sold_out'")->fetchColumn(),
                'customers' => (int) Database::pdo()->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn(),
            ],
            'orders' => Database::all('SELECT * FROM orders ORDER BY created_at DESC LIMIT 8'),
        ]);
    }

    public static function adminProducts(): void
    {
        Auth::requireStaff();
        render('admin/products', ['title' => 'Products', 'products' => Database::all('SELECT p.*,c.name category_name,(SELECT MIN(COALESCE(sale_price_cents,price_cents)) FROM product_variants WHERE product_id=p.id) from_price FROM products p JOIN categories c ON c.id=p.category_id ORDER BY c.position,p.name')]);
    }

    public static function adminProduct(?string $id = null): void
    {
        $user = Auth::requireStaff();
        $product = $id ? Database::one('SELECT * FROM products WHERE id=?', [(int) $id]) : null;
        if ($id && !$product) {
            http_response_code(404); render('errors/404', ['title' => 'Product not found']); return;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $name = trim((string) ($_POST['name'] ?? ''));
            $price = (int) round(((float) ($_POST['price'] ?? 0)) * 100);
            if ($name === '' || $price <= 0) {
                flash('error', 'Product name and a valid price are required.');
            } else {
                $imagePath = $product['image_path'] ?? null;
                if (!empty($_FILES['image']['tmp_name'])) {
                    $imagePath = self::saveUpload($_FILES['image']);
                }
                $slug = self::uniqueSlug((string) ($_POST['brand'] ?? '') . '-' . $name, $product['id'] ?? null);
                if ($product) {
                    Database::execute('UPDATE products SET category_id=?,name=?,brand=?,slug=?,description=?,image_path=?,strain_type=?,potency=?,status=?,featured=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [
                        (int) $_POST['category_id'],$name,trim((string) $_POST['brand']),$slug,trim((string) $_POST['description']),$imagePath,trim((string) $_POST['strain_type']),trim((string) $_POST['potency']),$_POST['status'],isset($_POST['featured'])?1:0,$product['id']
                    ]);
                    Database::execute('UPDATE product_variants SET label=?,price_cents=?,sale_price_cents=?,flavors=?,stock_status=? WHERE id=(SELECT id FROM product_variants WHERE product_id=? ORDER BY position LIMIT 1)', [
                        trim((string) $_POST['variant_label']),$price,($_POST['sale_price'] ?? '')!==''?(int) round((float)$_POST['sale_price']*100):null,trim((string)$_POST['flavors']),$_POST['stock_status'],$product['id']
                    ]);
                    self::audit('product.updated', 'product', (string) $product['id']);
                } else {
                    Database::execute('INSERT INTO products (category_id,name,brand,slug,description,image_path,strain_type,potency,status,featured) VALUES (?,?,?,?,?,?,?,?,?,?)', [
                        (int)$_POST['category_id'],$name,trim((string)$_POST['brand']),$slug,trim((string)$_POST['description']),$imagePath,trim((string)$_POST['strain_type']),trim((string)$_POST['potency']),$_POST['status'],isset($_POST['featured'])?1:0
                    ]);
                    $newId=(int)Database::pdo()->lastInsertId();
                    Database::execute('INSERT INTO product_variants (product_id,label,price_cents,sale_price_cents,flavors,stock_status) VALUES (?,?,?,?,?,?)', [$newId,trim((string)$_POST['variant_label']),$price,($_POST['sale_price']??'')!==''?(int)round((float)$_POST['sale_price']*100):null,trim((string)$_POST['flavors']),$_POST['stock_status']]);
                    self::audit('product.created', 'product', (string) $newId);
                }
                flash('success', 'Product saved.');
                redirect('admin/products');
            }
        }
        $variant = $product ? Database::one('SELECT * FROM product_variants WHERE product_id=? ORDER BY position LIMIT 1', [$product['id']]) : null;
        render('admin/product-form', ['title' => $product ? 'Edit product' : 'Add product', 'product' => $product, 'variant' => $variant, 'categories' => Store::categories()]);
    }

    public static function adminOrders(): void
    {
        Auth::requireStaff();
        render('admin/orders', ['title' => 'Orders', 'orders' => Database::all('SELECT * FROM orders ORDER BY created_at DESC')]);
    }

    public static function adminOrder(string $id): void
    {
        Auth::requireStaff();
        $order = Database::one('SELECT * FROM orders WHERE id=?', [(int)$id]);
        if (!$order) { http_response_code(404); render('errors/404',['title'=>'Order not found']); return; }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $statuses=['awaiting_confirmation','confirmed','preparing','ready','out_for_delivery','completed','cancelled'];
            $status=in_array($_POST['status']??'', $statuses, true)?$_POST['status']:$order['status'];
            Database::execute('UPDATE orders SET status=?,payment_status=?,staff_notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$status,$_POST['payment_status'],trim((string)$_POST['staff_notes']),$order['id']]);
            self::audit('order.updated','order',(string)$order['id'],['status'=>$status]);
            flash('success','Order updated.'); redirect('admin/orders/'.$order['id']);
        }
        render('admin/order', ['title'=>'Order '.$order['order_number'],'order'=>$order,'items'=>Database::all('SELECT * FROM order_items WHERE order_id=?',[$order['id']])]);
    }

    public static function adminSettings(): void
    {
        Auth::requireStaff();
        $schema = self::settingsSchema();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            if (isset($_POST['ordering_enabled']) && !isset($_POST['pickup_enabled']) && !isset($_POST['delivery_enabled'])) {
                flash('error', 'Enable pickup or delivery before turning on online ordering.');
                redirect('admin/settings');
            }
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
        Auth::requireStaff();
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
        Auth::requireStaff();
        verify_csrf();
        Database::execute('UPDATE promotions SET active=CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=?', [(int)$id]);
        self::audit('promotion.toggled','promotion',$id);
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
                    self::audit('staff.created','user',(string)$id);
                    flash('success','Staff account created.');
                } catch (\Throwable) { flash('error','A user with that email already exists.'); }
            }
            redirect('admin/staff');
        }
        render('admin/staff',['title'=>'Staff access','staff'=>Database::all("SELECT * FROM users WHERE role IN ('staff','manager','owner') ORDER BY role DESC,name")]);
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
        ];
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
