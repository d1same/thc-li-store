<?php
declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/../app/bootstrap.php') ? dirname(__DIR__) : dirname(__DIR__) . '/dispensary-app';
if (!is_file($appRoot . '/app/bootstrap.php')) {
    http_response_code(500);
    exit('Application files were not found. Check the cPanel deployment path.');
}
require $appRoot . '/app/bootstrap.php';

use App\Controller;
use App\Router;

$router = new Router();
$router->get('/', [Controller::class, 'home']);
$router->get('/menu', [Controller::class, 'menu']);
$router->get('/product/{slug}', [Controller::class, 'product']);
$router->get('/login', [Controller::class, 'login']);
$router->post('/login', [Controller::class, 'login']);
$router->get('/register', [Controller::class, 'register']);
$router->post('/register', [Controller::class, 'register']);
$router->post('/logout', [Controller::class, 'logout']);
$router->get('/account', [Controller::class, 'account']);
$router->get('/account/orders/{id}', [Controller::class, 'accountOrder']);
$router->post('/account/favorites/{id}', [Controller::class, 'favorite']);
$router->post('/account/addresses/{id}/delete', [Controller::class, 'deleteAddress']);
$router->get('/checkout', [Controller::class, 'checkout']);
$router->post('/checkout', [Controller::class, 'checkout']);
$router->get('/order/{number}/success', [Controller::class, 'orderSuccess']);
$router->get('/setup', [Controller::class, 'setup']);
$router->post('/setup', [Controller::class, 'setup']);
$router->get('/admin', [Controller::class, 'admin']);
$router->get('/admin/products', [Controller::class, 'adminProducts']);
$router->get('/admin/products/new', static fn() => Controller::adminProduct());
$router->post('/admin/products/new', static fn() => Controller::adminProduct());
$router->get('/admin/products/{id}/edit', [Controller::class, 'adminProduct']);
$router->post('/admin/products/{id}/edit', [Controller::class, 'adminProduct']);
$router->post('/admin/products/{id}/archive', [Controller::class, 'adminProductArchive']);
$router->get('/admin/pos', [Controller::class, 'adminPos']);
$router->post('/admin/pos', [Controller::class, 'adminPos']);
$router->get('/admin/pos/receipt/{id}', [Controller::class, 'adminPosReceipt']);
$router->get('/admin/orders', [Controller::class, 'adminOrders']);
$router->get('/admin/orders/{id}', [Controller::class, 'adminOrder']);
$router->post('/admin/orders/{id}', [Controller::class, 'adminOrder']);
$router->get('/admin/settings', [Controller::class, 'adminSettings']);
$router->post('/admin/settings', [Controller::class, 'adminSettings']);
$router->get('/admin/promotions', [Controller::class, 'adminPromotions']);
$router->post('/admin/promotions', [Controller::class, 'adminPromotions']);
$router->post('/admin/promotions/{id}/toggle', [Controller::class, 'adminPromotionToggle']);
$router->post('/admin/promotions/{id}/delete', [Controller::class, 'adminPromotionDelete']);
$router->get('/admin/staff', [Controller::class, 'adminStaff']);
$router->post('/admin/staff', [Controller::class, 'adminStaff']);
$router->post('/admin/staff/{id}', [Controller::class, 'adminStaffUpdate']);
$router->get('/health', [Controller::class, 'health']);
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
