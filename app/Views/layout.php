<?php $user = current_user(); $adminArea = str_starts_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '', '/admin'); ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#15291f">
  <meta name="description" content="<?= e(setting('store_tagline')) ?>">
  <title><?= e($title ?? setting('store_name')) ?> · <?= e(setting('store_name')) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset('app.css') ?>">
  <script>window.SHOP={base:<?= json_encode(rtrim(url(''), '/')) ?>,csrf:<?= json_encode(csrf_token()) ?>};</script>
  <script defer src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
  <script defer src="<?= asset('app.js') ?>"></script>
</head>
<body class="<?= $adminArea ? 'admin-body' : '' ?>">
  <?php if (!$adminArea): ?>
    <div class="announcement"><span class="status-dot"></span><?= e(setting('announcement')) ?></div>
    <header class="site-header">
      <a class="brand" href="<?= url('') ?>" aria-label="<?= e(setting('store_name')) ?> home">
        <img class="brand-wordmark" src="<?= asset('brand/thc-li-wordmark.png') ?>" alt="<?= e(setting('store_name')) ?>">
      </a>
      <nav class="desktop-nav" aria-label="Main navigation">
        <a href="<?= url('menu') ?>">Menu</a>
        <a href="<?= url('menu?category=flower') ?>">Flower</a>
        <a href="<?= url('menu?category=vapes') ?>">Vapes</a>
        <a href="<?= url('menu?category=edibles') ?>">Edibles</a>
      </nav>
      <div class="header-actions">
        <a class="icon-button" href="<?= $user ? url('account') : url('login') ?>" aria-label="Account"><i data-lucide="user-round"></i></a>
        <button class="cart-button" type="button" data-cart-open><i data-lucide="shopping-bag"></i><span>Cart</span><b data-cart-count>0</b></button>
        <button class="icon-button mobile-menu-button" type="button" data-menu-toggle aria-label="Open menu"><i data-lucide="menu"></i></button>
      </div>
    </header>
    <nav class="mobile-nav" data-mobile-nav aria-label="Mobile navigation">
      <a href="<?= url('menu') ?>">Shop all</a><a href="<?= url('menu?category=flower') ?>">Flower</a><a href="<?= url('menu?category=vapes') ?>">Vapes</a><a href="<?= url('menu?category=edibles') ?>">Edibles</a><a href="<?= $user ? url('account') : url('login') ?>">Account</a>
    </nav>
  <?php else: ?>
    <header class="admin-header">
      <a class="brand brand-light" href="<?= url('admin') ?>"><img class="brand-icon" src="<?= asset('brand/thc-li-icon.webp') ?>" alt=""><span>Owner desk</span></a>
      <button class="icon-button admin-menu-button" data-admin-menu aria-label="Open admin navigation"><i data-lucide="menu"></i></button>
      <nav class="admin-nav" data-admin-nav>
        <a href="<?= url('admin') ?>"><i data-lucide="layout-dashboard"></i>Overview</a>
        <a href="<?= url('admin/orders') ?>"><i data-lucide="package-check"></i>Orders</a>
        <a href="<?= url('admin/products') ?>"><i data-lucide="package-open"></i>Products</a>
        <a href="<?= url('admin/promotions') ?>"><i data-lucide="badge-percent"></i>Promotions</a>
        <a href="<?= url('admin/settings') ?>"><i data-lucide="sliders-horizontal"></i>Settings</a>
        <?php if (($user['role'] ?? '') === 'owner'): ?><a href="<?= url('admin/staff') ?>"><i data-lucide="users-round"></i>Staff</a><?php endif; ?>
        <a href="<?= url('') ?>"><i data-lucide="store"></i>View shop</a>
        <form method="post" action="<?= url('logout') ?>"><?= csrf_field() ?><button type="submit"><i data-lucide="log-out"></i>Sign out</button></form>
      </nav>
    </header>
  <?php endif; ?>

  <?php foreach (flashes() as $item): ?>
    <div class="flash flash-<?= e($item['type']) ?>" role="status"><?= e($item['message']) ?></div>
  <?php endforeach; ?>

  <main class="<?= $adminArea ? 'admin-main' : 'site-main' ?>"><?= $content ?></main>

  <?php if (!$adminArea): ?>
    <footer class="site-footer">
      <div><a class="brand brand-light" href="<?= url('') ?>"><img class="brand-wordmark brand-wordmark-light" src="<?= asset('brand/thc-li-wordmark.png') ?>" alt="<?= e(setting('store_name')) ?>"></a><p><?= e(setting('store_tagline')) ?></p></div>
      <div><strong>Shop</strong><a href="<?= url('menu') ?>">Full menu</a><a href="<?= url('account') ?>">Order history</a><a href="<?= url('checkout') ?>">Checkout</a></div>
      <div><strong>Store</strong><span><?= e(setting('hours')) ?></span><span><?= e(setting('service_areas')) ?></span><span><?= e(setting('store_phone')) ?></span></div>
      <p class="compliance-note"><?= e(setting('required_warning')) ?><br>License: <?= e(setting('license_number')) ?></p>
    </footer>

    <div class="cart-drawer" data-cart-drawer aria-hidden="true">
      <button class="drawer-scrim" type="button" data-cart-close aria-label="Close cart"></button>
      <aside class="drawer-panel" aria-label="Shopping cart">
        <div class="drawer-head"><div><span class="eyebrow">Your order</span><h2>Cart</h2></div><button class="icon-button" data-cart-close aria-label="Close cart"><i data-lucide="x"></i></button></div>
        <div data-cart-items class="cart-items"></div>
        <div class="cart-footer"><div class="cart-total"><span>Estimated subtotal</span><strong data-cart-total>$0.00</strong></div><p>Availability, minimums and final totals are confirmed at checkout.</p><a class="button button-primary button-wide" href="<?= url('checkout') ?>">Continue to checkout<i data-lucide="arrow-right"></i></a></div>
      </aside>
    </div>

    <div class="age-gate" data-age-gate hidden>
      <div class="age-card"><img class="age-logo" src="<?= asset('brand/thc-li-wordmark.png') ?>" alt="<?= e(setting('store_name')) ?>"><span class="eyebrow">Welcome to <?= e(setting('store_name')) ?></span><h2>Are you 21 or older?</h2><p>This menu is intended only for adults of legal purchasing age. Identification is required when an order is fulfilled.</p><button class="button button-primary button-wide" data-age-yes>Yes, I am 21+</button><a href="https://www.google.com" class="button button-quiet">No, take me away</a></div>
    </div>
  <?php endif; ?>
</body>
</html>
