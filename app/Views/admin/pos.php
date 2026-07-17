<?php
$categoryIcons = [
    'flower' => 'flower-2',
    'vapes' => 'battery-charging',
    'edibles' => 'cookie',
    'concentrates' => 'gem',
    'pre-rolls' => 'cigarette',
];
$categoryCounts = [];
$posCatalog = [];

foreach ($catalog as $product) {
    $categoryCounts[$product['category_slug']] = ($categoryCounts[$product['category_slug']] ?? 0) + 1;
    $prices = [];
    $trackedStock = [];
    foreach ($product['variants'] as &$variant) {
        $variant['effective_price_cents'] = (int) ($variant['sale_price_cents'] ?: $variant['price_cents']);
        $prices[] = $variant['effective_price_cents'];
        if ($variant['stock_quantity'] !== null) {
            $trackedStock[] = (int) $variant['stock_quantity'];
        }
    }
    unset($variant);
    $product['image_url'] = $product['image_path'] ? url($product['image_path']) : '';
    $product['from_price_cents'] = $prices ? min($prices) : 0;
    $product['tracked_stock_total'] = $trackedStock ? array_sum($trackedStock) : null;
    $product['all_stock_tracked'] = count($trackedStock) === count($product['variants']);
    $posCatalog[] = $product;
}
?>

<section class="admin-page-head pos-page-head">
  <div><span class="eyebrow">In-store checkout</span><h1>Point of sale</h1><p>Choose a category, tap a product, then select the option.</p></div>
  <div class="pos-page-actions"><?php if(can('reports.view')): ?><a class="button button-secondary" href="<?= url('admin/reports') ?>"><i data-lucide="chart-no-axes-combined"></i>Sales</a><?php endif; ?><?php if(can('orders.view')): ?><a class="button button-secondary" href="<?= url('admin/orders') ?>"><i data-lucide="history"></i>Orders</a><?php endif; ?><span class="pos-live-status"><i data-lucide="<?= setting('pos_enabled', true) ? 'wifi' : 'pause-circle' ?>"></i><?= setting('pos_enabled', true) ? 'Register ready' : 'POS disabled' ?></span></div>
</section>

<?php if (!setting('pos_enabled', true)): ?>
  <div class="admin-panel pos-disabled"><i data-lucide="pause-circle"></i><div><strong>The POS is disabled</strong><p>An owner can enable it under Store settings.</p></div><?php if (can('settings.manage')): ?><a class="button button-secondary" href="<?= url('admin/settings') ?>">Open settings</a><?php endif; ?></div>
<?php endif; ?>

<section class="pos-workspace" data-pos-app data-pos-enabled="<?= setting('pos_enabled', true) ? '1' : '0' ?>" data-can-complete="<?= $canComplete ? '1' : '0' ?>" data-tax-enabled="<?= setting('pos_tax_enabled', false) ? '1' : '0' ?>" data-tax-rate="<?= e((string) setting('pos_tax_rate', '0')) ?>" data-discount-enabled="<?= setting('pos_manual_discount_enabled', false) && $canDiscount ? '1' : '0' ?>">
  <textarea hidden data-pos-catalog-json><?= json_encode($posCatalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></textarea>

  <div class="pos-catalog-panel">
    <div class="pos-catalog-tools">
      <label class="pos-search"><i data-lucide="search"></i><input type="search" placeholder="Search products, brands or options" data-pos-search autocomplete="off"></label>
      <span class="pos-product-count"><b data-pos-visible-count><?= count($catalog) ?></b><small>products</small></span>
    </div>

    <div class="pos-category-rail" data-pos-categories aria-label="Product categories">
      <button type="button" class="active" data-pos-category=""><span><i data-lucide="layout-grid"></i></span><strong>All</strong><small><?= count($catalog) ?></small></button>
      <?php foreach ($categories as $category): ?>
        <button type="button" data-pos-category="<?= e($category['slug']) ?>"><span><i data-lucide="<?= e($categoryIcons[$category['slug']] ?? 'leaf') ?>"></i></span><strong><?= e($category['name']) ?></strong><small><?= (int) ($categoryCounts[$category['slug']] ?? 0) ?></small></button>
      <?php endforeach; ?>
    </div>

    <div class="pos-product-grid" data-pos-products>
      <?php foreach ($posCatalog as $product):
        $searchText = strtolower(trim(($product['name'] ?? '') . ' ' . ($product['brand'] ?? '') . ' ' . implode(' ', array_map(static fn($v) => ($v['label'] ?? '') . ' ' . ($v['flavors'] ?? '') . ' ' . ($v['sku'] ?? ''), $product['variants']))));
        $stockTotal = $product['tracked_stock_total'];
        $stockClass = $stockTotal !== null && $stockTotal <= 5 ? ' low' : '';
      ?>
        <button class="pos-product-card" type="button" data-pos-product data-pos-open-product="<?= (int) $product['id'] ?>" data-category="<?= e($product['category_slug']) ?>" data-search="<?= e($searchText) ?>">
          <span class="pos-product-visual">
            <?php if ($product['image_url']): ?><img src="<?= e($product['image_url']) ?>" alt=""><?php else: ?><span class="pos-image-fallback"><i data-lucide="package-open"></i></span><?php endif; ?>
            <span class="pos-option-count"><?= count($product['variants']) ?> option<?= count($product['variants']) === 1 ? '' : 's' ?></span>
          </span>
          <span class="pos-product-copy"><small><?= e($product['brand'] ?: $product['category_name']) ?></small><strong><?= e($product['name']) ?></strong><span><?= e($product['category_name']) ?><?= $product['strain_type'] ? ' &middot; ' . e($product['strain_type']) : '' ?></span></span>
          <span class="pos-card-meta"><b>From <?= money((int) $product['from_price_cents']) ?></b><span class="pos-stock-badge<?= $stockClass ?>"><i data-lucide="package-check"></i><?= $stockTotal === null ? 'Available' : $stockTotal . ' left' ?></span></span>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="empty-state small pos-no-results" data-pos-no-results hidden><i data-lucide="search-x"></i><h3>No matching products</h3><p>Try a different search or category.</p></div>
  </div>

  <aside class="admin-panel pos-cart-panel" id="pos-cart-panel" data-pos-cart-panel aria-label="Current sale">
    <form method="post" data-pos-form>
      <?= csrf_field() ?><input type="hidden" name="cart_json" data-pos-cart-json>
      <div class="pos-cart-head"><div><span class="step-label">Current sale</span><h2>Cart <small data-pos-cart-count>0</small></h2></div><div class="pos-cart-actions"><button type="button" class="button button-quiet button-small" data-pos-clear>Clear</button><button type="button" class="pos-cart-close" data-pos-cart-close aria-label="Close cart"><i data-lucide="x"></i></button></div></div>
      <div class="pos-cart-items" data-pos-cart-items><div class="empty-cart"><i data-lucide="shopping-basket"></i><strong>Cart is empty</strong><p>Tap a product to choose an option.</p></div></div>
      <div class="pos-totals"><div><span>Subtotal</span><strong data-pos-subtotal>$0.00</strong></div><div data-pos-discount-row hidden><span>Discount</span><strong data-pos-discount>&minus;$0.00</strong></div><div data-pos-tax-row <?= setting('pos_tax_enabled', false) ? '' : 'hidden' ?>><span>Tax <small><?= e((string) setting('pos_tax_rate', '0')) ?>%</small></span><strong data-pos-tax>$0.00</strong></div><div class="pos-total"><span>Total</span><strong data-pos-total>$0.00</strong></div></div>
      <?php if (setting('pos_manual_discount_enabled', false) && $canDiscount): ?><label>Manual discount (%)<input type="number" name="discount_percent" min="0" max="<?= (int) setting('pos_max_discount_percent', 20) ?>" step="0.01" value="0" data-pos-discount-input></label><?php elseif (setting('pos_manual_discount_enabled', false)): ?><p class="form-hint"><i data-lucide="lock-keyhole"></i>Your account cannot apply manual discounts.</p><?php endif; ?>
      <?php if (setting('customer_capture_enabled', true)): ?>
        <section class="pos-customer-capture" data-pos-customer-capture>
          <div class="pos-customer-head"><div><span class="step-label">Customer first</span><h3>Build the client list</h3><p>Add a phone or email when possible. Checkout can still continue anonymously.</p></div><span class="status-pill status-active">Recommended</span></div>
          <?php if (can('customers.view')): ?><label class="pos-customer-search"><span>Find existing customer</span><div><i data-lucide="search"></i><input type="search" placeholder="Name, phone or email" data-pos-customer-search autocomplete="off"></div><div class="pos-customer-results" data-pos-customer-results hidden></div></label><?php endif; ?>
          <input type="hidden" name="customer_id" value="" data-pos-customer-id>
          <div class="form-grid" data-pos-customer-fields><label>Name<input name="customer_name" autocomplete="name" placeholder="Customer name"></label><label>Phone<input type="tel" name="customer_phone" autocomplete="tel" placeholder="Best for fast lookup"></label><label class="full">Email receipt to<input type="email" name="customer_email" autocomplete="email" placeholder="Optional if phone is provided"></label><?php if(setting('marketing_opt_in_enabled',true)): ?><label class="check-row full pos-marketing-opt"><input type="checkbox" name="marketing_opt_in" value="1"><span>Customer agrees to receive occasional store updates and promotions.</span></label><?php endif; ?><label class="full">Sale notes<textarea name="customer_notes" rows="2"></textarea></label></div>
          <label class="pos-anonymous-toggle"><input type="checkbox" name="skip_customer" value="1" data-pos-skip-customer><span><i data-lucide="user-round-x"></i><strong>Skip customer details</strong><small>Record this as an anonymous walk-in. The sale still counts in every report.</small></span></label>
        </section>
      <?php else: ?>
        <input type="hidden" name="skip_customer" value="1"><label>Sale notes<textarea name="customer_notes" rows="2"></textarea></label>
      <?php endif; ?>
      <fieldset class="pos-payment-options"><legend>Payment</legend><?php if (setting('pos_cash_enabled', true)): ?><label><input type="radio" name="payment_method" value="cash" checked><span><i data-lucide="banknote"></i><strong>Cash</strong></span></label><?php endif; ?><?php if (setting('pos_external_card_enabled', true)): ?><label><input type="radio" name="payment_method" value="external_card" <?= setting('pos_cash_enabled', true) ? '' : 'checked' ?>><span><i data-lucide="credit-card"></i><strong>External terminal</strong><small>Card data is not stored</small></span></label><?php endif; ?></fieldset>
      <label class="pos-age-check"><input type="checkbox" name="age_verified" value="1" required><span><i data-lucide="badge-check"></i><strong>ID checked — customer is 21+</strong><small>Required before completing the sale.</small></span></label>
      <button class="button button-primary button-large button-wide" type="submit" data-pos-submit disabled><?= $canComplete ? 'Complete sale' : 'Sale approval required' ?><i data-lucide="check-circle-2"></i></button>
      <?php if (!$canComplete): ?><p class="form-hint"><i data-lucide="lock-keyhole"></i>Your account can prepare carts but cannot approve purchases.</p><?php endif; ?>
    </form>
  </aside>

  <button type="button" class="pos-cart-dock" data-pos-cart-open aria-controls="pos-cart-panel" aria-expanded="false"><span><i data-lucide="shopping-basket"></i><b data-pos-dock-count>0</b></span><strong>View cart</strong><b data-pos-dock-total>$0.00</b></button>
  <button type="button" class="pos-cart-scrim" data-pos-cart-close aria-label="Close cart"></button>

  <div class="pos-product-modal" data-pos-modal hidden role="dialog" aria-modal="true" aria-labelledby="pos-modal-title">
    <div class="pos-product-modal-card">
      <button type="button" class="pos-modal-close" data-pos-modal-close aria-label="Close product options"><i data-lucide="x"></i></button>
      <div class="pos-modal-product">
        <div class="pos-modal-image" data-pos-modal-image></div>
        <div><small data-pos-modal-brand></small><h2 id="pos-modal-title" data-pos-modal-title></h2><p data-pos-modal-meta></p><p class="pos-modal-potency" data-pos-modal-potency hidden></p></div>
      </div>
      <div class="pos-modal-section-head"><div><span class="step-label">Choose an option</span><strong>Price and inventory</strong></div><small>Tap one</small></div>
      <div class="pos-modal-variants" data-pos-modal-variants></div>
      <div class="pos-modal-footer">
        <div class="pos-modal-quantity"><span>Quantity</span><div><button type="button" data-pos-modal-minus aria-label="Reduce quantity"><i data-lucide="minus"></i></button><strong data-pos-modal-quantity>1</strong><button type="button" data-pos-modal-plus aria-label="Increase quantity"><i data-lucide="plus"></i></button></div></div>
        <button type="button" class="button button-primary button-large" data-pos-modal-add><span>Add to cart</span><strong data-pos-modal-add-total>$0.00</strong></button>
      </div>
    </div>
  </div>
</section>
