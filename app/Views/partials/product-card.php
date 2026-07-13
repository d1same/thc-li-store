<article class="product-card" data-product data-name="<?= e(strtolower($product['name'] . ' ' . $product['brand'])) ?>" data-category="<?= e($product['category_slug']) ?>">
  <a class="product-image" href="<?= url('product/' . $product['slug']) ?>">
    <?php if ($product['image_path']): ?><img src="<?= url($product['image_path']) ?>" alt="<?= e($product['name']) ?>" loading="lazy"><?php else: ?><span class="image-fallback"><i data-lucide="leaf"></i></span><?php endif; ?>
    <?php if ((int)$product['featured']): ?><span class="product-badge">Featured</span><?php endif; ?>
    <?php if ($product['status']==='sold_out'): ?><span class="sold-badge">Sold out</span><?php endif; ?>
  </a>
  <div class="product-copy">
    <span class="product-brand"><?= e($product['brand'] ?: $product['category_name']) ?></span>
    <h3><a href="<?= url('product/' . $product['slug']) ?>"><?= e($product['name']) ?></a></h3>
    <div class="product-meta"><span><?= e($product['potency'] ?: $product['strain_type'] ?: $product['category_name']) ?></span><strong>From <?= money((int)$product['from_price']) ?></strong></div>
  </div>
  <a class="card-action" href="<?= url('product/' . $product['slug']) ?>">View options<i data-lucide="arrow-up-right"></i></a>
</article>

