<?php
$availableVariants = array_values(array_filter(
    $product['variants'],
    static fn(array $variant): bool => $variant['stock_status'] !== 'sold_out' && ($variant['stock_quantity'] === null || (int) $variant['stock_quantity'] > 0)
));
$firstAvailableId = isset($availableVariants[0]) ? (int) $availableVariants[0]['id'] : null;
$canOrder = $product['status'] !== 'sold_out' && $firstAvailableId !== null;
?>
<section class="product-detail container">
  <a class="back-link" href="<?= url('menu?category='.$product['category_slug']) ?>"><i data-lucide="arrow-left"></i>Back to <?= e($product['category_name']) ?></a>
  <div class="product-detail-grid">
    <div class="detail-image"><?php if ($product['image_path']): ?><img src="<?= url($product['image_path']) ?>" alt="<?= e($product['name']) ?>"><?php else: ?><span class="image-fallback"><i data-lucide="leaf"></i></span><?php endif; ?></div>
    <div class="detail-copy">
      <span class="eyebrow"><?= e($product['brand'] ?: $product['category_name']) ?></span>
      <h1><?= e($product['name']) ?></h1>
      <div class="detail-tags"><?php if ($product['strain_type']): ?><span><?= e($product['strain_type']) ?></span><?php endif; ?><?php if ($product['potency']): ?><span><?= e($product['potency']) ?></span><?php endif; ?><span><?= e($product['category_name']) ?></span></div>
      <p class="detail-description"><?= e($product['description']) ?></p>
      <div class="variant-list">
        <span class="field-label">Choose an option</span>
        <?php foreach ($product['variants'] as $variant):
          $quantity = $variant['stock_quantity'] === null ? null : (int) $variant['stock_quantity'];
          $available = $variant['stock_status'] !== 'sold_out' && ($quantity === null || $quantity > 0);
        ?>
          <label class="variant-option <?= $available ? '' : 'is-sold-out' ?>">
            <input type="radio" name="variant" value="<?= (int) $variant['id'] ?>" data-variant data-product-id="<?= (int) $product['id'] ?>" data-product-name="<?= e($product['name']) ?>" data-variant-label="<?= e($variant['label']) ?>" data-price="<?= (int) ($variant['sale_price_cents'] ?: $variant['price_cents']) ?>" data-stock-quantity="<?= $quantity === null ? '' : $quantity ?>" <?= (int) $variant['id'] === $firstAvailableId ? 'checked' : '' ?> <?= $available ? '' : 'disabled' ?>>
            <span><strong><?= e($variant['label']) ?></strong><?php if ($variant['flavors']): ?><small><?= e($variant['flavors']) ?></small><?php endif; ?><?php if (!$available): ?><small class="stock-note sold-out-note">Sold out</small><?php elseif ($quantity !== null && $quantity <= 5): ?><small class="stock-note">Only <?= $quantity ?> left</small><?php endif; ?></span>
            <span class="variant-price"><?php if ($variant['sale_price_cents']): ?><del><?= money((int) $variant['price_cents']) ?></del><?php endif; ?><b><?= money((int) ($variant['sale_price_cents'] ?: $variant['price_cents'])) ?></b></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="detail-actions">
        <button class="button button-primary button-wide button-large" type="button" data-add-selected <?= $canOrder ? '' : 'disabled' ?>><i data-lucide="shopping-bag"></i><?= $canOrder ? 'Add to cart' : 'Sold out' ?></button>
        <?php if (current_user()): ?><form method="post" action="<?= url('account/favorites/'.$product['id']) ?>"><?= csrf_field() ?><input type="hidden" name="slug" value="<?= e($product['slug']) ?>"><button class="button button-secondary button-large" type="submit" aria-label="Toggle favorite"><i data-lucide="heart"></i></button></form><?php endif; ?>
      </div>
      <div class="detail-assurance"><span><i data-lucide="badge-check"></i>Live inventory is checked at checkout</span><span><i data-lucide="shield-check"></i>ID required at fulfillment</span></div>
    </div>
  </div>
</section>
