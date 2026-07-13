<?php
$catalogUrl = static function (array $overrides = []) use ($filters): string {
    $query = array_merge($filters, ['page' => 1], $overrides);
    $query = array_filter($query, static fn(mixed $value): bool => $value !== '' && $value !== null);
    return url('admin/products?' . http_build_query($query));
};
?>
<section class="admin-page-head">
  <div><span class="eyebrow">Catalog</span><h1>Products</h1><p>Search the catalog, manage options and control availability.</p></div>
  <a class="button button-primary" href="<?= url('admin/products/new') ?>"><i data-lucide="plus"></i>Add product</a>
</section>

<section class="admin-panel">
  <div class="admin-toolbar">
    <form class="admin-filter-form" method="get" action="<?= url('admin/products') ?>">
      <div class="admin-search">
        <i data-lucide="search"></i>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Search products, brands, sizes or flavors" aria-label="Search products">
        <?php if ($filters['q'] !== ''): ?><a href="<?= $catalogUrl(['q' => '']) ?>" aria-label="Clear product search"><i data-lucide="x"></i></a><?php endif; ?>
      </div>
      <label class="admin-filter-field"><span>Category</span><select name="category" data-admin-filter-auto><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= e($category['slug']) ?>" <?= $filters['category'] === $category['slug'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
      <label class="admin-filter-field admin-page-size"><span>Show</span><select name="per_page" data-admin-filter-auto><?php foreach ([20,50,100] as $size): ?><option value="<?= $size ?>" <?= (int) $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></label>
      <button class="button button-primary admin-filter-submit" type="submit"><i data-lucide="search"></i>Search</button>
    </form>
    <span class="admin-result-count">Showing <?= (int) $pagination['from'] ?>–<?= (int) $pagination['to'] ?> of <?= (int) $pagination['total'] ?></span>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table product-admin-table">
      <thead><tr><th>Product</th><th>Category</th><th>Options</th><th>Inventory</th><th>Starting price</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($products as $product):
          $searchText = strtolower(trim(implode(' ', [$product['name'], $product['brand'], $product['category_name'], $product['variant_search'] ?? ''])));
        ?>
          <tr data-admin-product data-search="<?= e($searchText) ?>">
            <td><div class="product-cell"><?php if ($product['image_path']): ?><img src="<?= url($product['image_path']) ?>" alt=""><?php else: ?><span><i data-lucide="image"></i></span><?php endif; ?><div><strong><?= e($product['name']) ?></strong><small><?= e($product['brand']) ?></small></div></div></td>
            <td><?= e($product['category_name']) ?></td>
            <td><?= (int) $product['variant_count'] ?> <?= (int) $product['variant_count'] === 1 ? 'option' : 'options' ?></td>
            <td><div class="inventory-summary">
              <?php if ((int) $product['tracked_variants'] > 0): ?><strong><?= (int) $product['tracked_quantity'] ?> left</strong><?php else: ?><strong>Not tracked</strong><?php endif; ?>
              <?php if ((int) $product['low_stock_variants'] > 0): ?><small><?= (int) $product['low_stock_variants'] ?> low</small><?php endif; ?>
              <?php if ((int) $product['sold_out_variants'] > 0): ?><small><?= (int) $product['sold_out_variants'] ?> sold out</small><?php endif; ?>
              <?php if ((int) $product['untracked_variants'] > 0 && (int) $product['tracked_variants'] > 0): ?><small><?= (int) $product['untracked_variants'] ?> untracked</small><?php endif; ?>
            </div></td>
            <td><?= money((int) $product['from_price']) ?></td>
            <td><span class="status-pill status-<?= e($product['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $product['status']))) ?></span></td>
            <td><a class="button button-small button-secondary" href="<?= url('admin/products/' . $product['id'] . '/edit') ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr class="admin-search-empty"><td colspan="7"><div><i data-lucide="search-x"></i><strong>No products found</strong><span>Try another search or category.</span><a class="button button-secondary button-small" href="<?= url('admin/products') ?>">Clear filters</a></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ((int) $pagination['total_pages'] > 1): ?>
    <nav class="admin-pagination" aria-label="Product pages">
      <?php if ((int) $pagination['page'] > 1): ?><a class="button button-secondary button-small" href="<?= $catalogUrl(['page' => (int) $pagination['page'] - 1]) ?>"><i data-lucide="chevron-left"></i>Previous</a><?php else: ?><span></span><?php endif; ?>
      <span>Page <strong><?= (int) $pagination['page'] ?></strong> of <?= (int) $pagination['total_pages'] ?></span>
      <?php if ((int) $pagination['page'] < (int) $pagination['total_pages']): ?><a class="button button-secondary button-small" href="<?= $catalogUrl(['page' => (int) $pagination['page'] + 1]) ?>">Next<i data-lucide="chevron-right"></i></a><?php else: ?><span></span><?php endif; ?>
    </nav>
  <?php endif; ?>
</section>
