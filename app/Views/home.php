<section class="home-intro container">
  <div class="intro-copy">
    <span class="eyebrow"><span class="status-dot"></span><?= e(setting('store_status') === 'open' ? 'Open for orders' : 'Browse the menu') ?></span>
    <h1>A better way to shop the local menu.</h1>
    <p><?= e(setting('store_tagline')) ?> Browse clear prices and options, then choose pickup or delivery at checkout.</p>
    <div class="button-row"><a class="button button-primary" href="<?= url('menu') ?>">Shop the menu<i data-lucide="arrow-right"></i></a><a class="button button-secondary" href="#how-it-works">How it works</a></div>
    <div class="trust-row"><span><i data-lucide="shield-check"></i>21+ only</span><span><i data-lucide="map-pin"></i><?= e(setting('service_areas')) ?></span><span><i data-lucide="clock-3"></i><?= e(setting('hours')) ?></span></div>
  </div>
  <div class="intro-feature">
    <?php $hero=$featured[0]??null; if($hero): ?>
      <img src="<?= url($hero['image_path']) ?>" alt="<?= e($hero['name']) ?>">
      <div class="feature-float"><span><?= e($hero['category_name']) ?></span><strong><?= e($hero['name']) ?></strong><small>From <?= money((int)$hero['from_price']) ?></small></div>
    <?php endif; ?>
  </div>
</section>

<section class="mobile-fast-shop container" aria-label="Quick menu access">
  <div class="mobile-fast-heading"><div><span class="eyebrow">Start shopping</span><h2>Explore the menu</h2></div><a href="<?= url('menu') ?>">View all<i data-lucide="arrow-right"></i></a></div>
  <div class="mobile-category-rail"><?php $mobileIcons=['flower'=>'flower-2','vapes'=>'battery-charging','edibles'=>'cookie','concentrates'=>'gem','pre-rolls'=>'cigarette']; foreach($categories as $category): ?><a href="<?= url('menu?category='.$category['slug']) ?>"><span><i data-lucide="<?= e($mobileIcons[$category['slug']]??'leaf') ?>"></i></span><?= e($category['name']) ?></a><?php endforeach; ?></div>
  <div class="mobile-product-rail"><?php foreach(array_slice($featured,0,8) as $product) require APP_ROOT.'/app/Views/partials/product-card.php'; ?></div>
</section>

<section class="promo-section container">
  <div class="section-heading"><div><span class="eyebrow">Current offers</span><h2>Worth knowing today</h2></div></div>
  <div class="promo-grid"><?php foreach($promotions as $i=>$promo): ?><article class="promo-card promo-<?= ($i%3)+1 ?>"><span class="promo-number">0<?= $i+1 ?></span><div><h3><?= e($promo['title']) ?></h3><p><?= e($promo['description']) ?></p></div><i data-lucide="sparkles"></i></article><?php endforeach; ?></div>
</section>

<section class="category-section container">
  <div class="section-heading"><div><span class="eyebrow">Browse your way</span><h2>Shop by category</h2></div><a href="<?= url('menu') ?>">See everything<i data-lucide="arrow-right"></i></a></div>
  <div class="category-grid"><?php $icons=['flower'=>'flower-2','vapes'=>'battery-charging','edibles'=>'cookie','concentrates'=>'gem','pre-rolls'=>'cigarette']; foreach($categories as $category): ?><a class="category-card" href="<?= url('menu?category='.$category['slug']) ?>"><span class="category-icon"><i data-lucide="<?= e($icons[$category['slug']]??'leaf') ?>"></i></span><span><?= e($category['name']) ?></span><i data-lucide="arrow-up-right"></i></a><?php endforeach; ?></div>
</section>

<section class="product-section home-product-section container">
  <div class="section-heading"><div><span class="eyebrow">Popular right now</span><h2>Featured from the July menu</h2></div><a href="<?= url('menu') ?>">Shop full menu<i data-lucide="arrow-right"></i></a></div>
  <div class="product-grid"><?php foreach(array_slice($featured,0,8) as $product) require APP_ROOT.'/app/Views/partials/product-card.php'; ?></div>
</section>

<section id="how-it-works" class="how-section">
  <div class="container"><div class="section-heading light"><div><span class="eyebrow">Simple by design</span><h2>From menu to confirmed order.</h2></div></div><div class="steps"><article><span>1</span><i data-lucide="search"></i><h3>Browse clearly</h3><p>Search products and choose the exact size or flavor you want.</p></article><article><span>2</span><i data-lucide="shopping-basket"></i><h3>Choose fulfillment</h3><p>Select pickup or delivery based on what the store has enabled.</p></article><article><span>3</span><i data-lucide="badge-check"></i><h3>Get confirmation</h3><p>The owner confirms availability, timing and payment before fulfillment.</p></article></div></div>
</section>
