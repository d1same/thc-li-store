<?php
$editing = !empty($product['id']);
$variantRows = $variants ?: [['label' => 'Single', 'price' => '', 'sale_price' => '', 'flavors' => '', 'stock_status' => 'in_stock', 'stock_quantity' => '']];
?>
<section class="admin-page-head">
  <div>
    <a class="back-link" href="<?= url('admin/products') ?>"><i data-lucide="arrow-left"></i>Products</a>
    <span class="eyebrow"><?= $editing ? 'Catalog update' : 'New catalog item' ?></span>
    <h1><?= e($title) ?></h1>
    <p>Manage the product once, then add every size, flavor or package option below.</p>
  </div>
</section>

<form method="post" enctype="multipart/form-data" class="admin-form-layout">
  <?= csrf_field() ?>
  <div class="admin-panel form-panel">
    <div class="form-section">
      <span class="step-label">Product basics</span>
      <div class="form-grid">
        <label>Product name<input name="name" value="<?= e($product['name'] ?? '') ?>" required></label>
        <label>Brand<input name="brand" value="<?= e($product['brand'] ?? '') ?>"></label>
        <label>Category
          <select name="category_id"><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= ($product['category_id'] ?? null) == $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select>
        </label>
        <label>Type
          <select name="strain_type"><option value="">Not specified</option><?php foreach (['Sativa','Hybrid','Indica'] as $type): ?><option <?= ($product['strain_type'] ?? '') === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select>
        </label>
        <label>Potency / package note<input name="potency" value="<?= e($product['potency'] ?? '') ?>" placeholder="24% THC or 500mg"></label>
        <label class="full">Description<textarea name="description" rows="4"><?= e($product['description'] ?? '') ?></textarea></label>
      </div>
    </div>

    <div class="form-section">
      <div class="variant-section-head">
        <div><span class="step-label">Sizes, flavors, prices and stock</span><p>Enter a quantity to track inventory automatically. Leave it blank for an unlimited or manually managed option.</p></div>
        <span class="variant-count" data-variant-count><?= count($variantRows) ?> <?= count($variantRows) === 1 ? 'option' : 'options' ?></span>
      </div>
      <div class="variant-editors" data-variant-editors>
        <?php foreach ($variantRows as $index => $variant):
          $priceValue = array_key_exists('price', $variant) ? (string) $variant['price'] : (isset($variant['price_cents']) ? number_format((int) $variant['price_cents'] / 100, 2, '.', '') : '');
          $saleValue = array_key_exists('sale_price', $variant) ? (string) $variant['sale_price'] : (!empty($variant['sale_price_cents']) ? number_format((int) $variant['sale_price_cents'] / 100, 2, '.', '') : '');
        ?>
          <article class="variant-editor" data-variant-row>
            <div class="variant-editor-head"><strong data-variant-number>Option <?= $index + 1 ?></strong><button class="variant-remove" type="button" data-remove-variant><i data-lucide="trash-2"></i>Remove</button></div>
            <input type="hidden" name="variants[<?= $index ?>][id]" value="<?= (int) ($variant['id'] ?? 0) ?>">
            <div class="variant-editor-grid">
              <label>Option label<input name="variants[<?= $index ?>][label]" value="<?= e($variant['label'] ?? '') ?>" placeholder="3.5g, 1 oz or 5 pack" required></label>
              <label>Regular price<input type="number" name="variants[<?= $index ?>][price]" step="0.01" min="0.01" value="<?= e($priceValue) ?>" required></label>
              <label>Sale price <small>Optional</small><input type="number" name="variants[<?= $index ?>][sale_price]" step="0.01" min="0.01" value="<?= e($saleValue) ?>"></label>
              <label>Stock status <small data-stock-status-help><?= isset($variant['stock_quantity']) && $variant['stock_quantity'] !== null && $variant['stock_quantity'] !== '' ? 'Automatic from quantity' : 'Manual when quantity is blank' ?></small>
                <select name="variants[<?= $index ?>][stock_status]" data-stock-status><?php foreach (['in_stock','low_stock','sold_out'] as $status): ?><option value="<?= $status ?>" <?= ($variant['stock_status'] ?? 'in_stock') === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select>
              </label>
              <label>Quantity left <small>Optional</small><input type="number" name="variants[<?= $index ?>][stock_quantity]" min="0" step="1" value="<?= e(isset($variant['stock_quantity']) ? (string) $variant['stock_quantity'] : '') ?>" placeholder="Leave blank if not tracked" data-stock-quantity></label>
              <label class="full">Flavor or option details <small>Optional</small><input name="variants[<?= $index ?>][flavors]" value="<?= e($variant['flavors'] ?? '') ?>" placeholder="Blue Dream, mango, 10 pieces"></label>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <button class="button button-secondary add-variant-button" type="button" data-add-variant><i data-lucide="plus"></i>Add another option</button>
      <template data-variant-template>
        <article class="variant-editor" data-variant-row>
          <div class="variant-editor-head"><strong data-variant-number>New option</strong><button class="variant-remove" type="button" data-remove-variant><i data-lucide="trash-2"></i>Remove</button></div>
          <input type="hidden" name="variants[__INDEX__][id]" value="0">
          <div class="variant-editor-grid">
            <label>Option label<input name="variants[__INDEX__][label]" placeholder="3.5g, 1 oz or 5 pack" required></label>
            <label>Regular price<input type="number" name="variants[__INDEX__][price]" step="0.01" min="0.01" required></label>
            <label>Sale price <small>Optional</small><input type="number" name="variants[__INDEX__][sale_price]" step="0.01" min="0.01"></label>
            <label>Stock status <small data-stock-status-help>Manual when quantity is blank</small><select name="variants[__INDEX__][stock_status]" data-stock-status><option value="in_stock">In Stock</option><option value="low_stock">Low Stock</option><option value="sold_out">Sold Out</option></select></label>
            <label>Quantity left <small>Optional</small><input type="number" name="variants[__INDEX__][stock_quantity]" min="0" step="1" placeholder="Leave blank if not tracked" data-stock-quantity></label>
            <label class="full">Flavor or option details <small>Optional</small><input name="variants[__INDEX__][flavors]" placeholder="Blue Dream, mango, 10 pieces"></label>
          </div>
        </article>
      </template>
    </div>

    <div class="form-section">
      <span class="step-label">Photo and visibility</span>
      <div class="form-grid">
        <label class="full file-label"><span>Product photo</span><input type="file" name="image" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG or WebP, up to 8 MB.</small></label>
        <label>Status
          <select name="status"><?php foreach (['draft','active','sold_out','archived'] as $status): ?><option value="<?= $status ?>" <?= ($product['status'] ?? 'active') === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select>
        </label>
        <label class="check-row full"><input type="checkbox" name="featured" value="1" <?= !empty($product['featured']) ? 'checked' : '' ?>><span>Feature this product on the homepage</span></label>
      </div>
    </div>
  </div>

  <aside class="admin-panel form-actions">
    <h2>Publish</h2>
    <p>All option changes appear in the menu as soon as they are saved.</p>
    <?php if (!empty($product['image_path'])): ?><img class="form-preview" src="<?= url($product['image_path']) ?>" alt="Current product image"><?php endif; ?>
    <button class="button button-primary button-wide" type="submit">Save product<i data-lucide="save"></i></button>
    <a class="button button-secondary button-wide" href="<?= url('admin/products') ?>">Cancel</a>
    <?php if ($editing && can('products.archive')): ?><button class="button button-danger button-wide" type="submit" formaction="<?= url('admin/products/' . $product['id'] . '/archive') ?>" formmethod="post" data-confirm="Archive this product? It will leave the menu, while old receipts remain intact."><i data-lucide="archive"></i>Archive product</button><?php endif; ?>
  </aside>
</form>
