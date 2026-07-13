<section class="admin-page-head">
  <div>
    <a class="back-link" href="<?= url('admin/orders') ?>"><i data-lucide="arrow-left"></i>Orders</a>
    <span class="eyebrow">Placed <?= e(date('M j, Y · g:i A', strtotime($order['created_at']))) ?></span>
    <h1><?= e($order['order_number']) ?></h1>
    <p><?= e($order['customer_name']) ?> · <?= ($order['order_source'] ?? 'online') === 'pos' ? 'Point of sale' : e(ucfirst($order['fulfillment'])) ?></p>
  </div>
  <span class="status-pill status-<?= e($order['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $order['status']))) ?></span>
</section>

<section class="admin-order-grid">
  <div class="admin-panel">
    <h2>Order items</h2>
    <div class="receipt-items">
      <?php foreach ($items as $item): ?>
        <div><span><strong><?= e($item['product_name']) ?></strong><small><?= e($item['variant_label']) ?> × <?= (int) $item['quantity'] ?></small></span><b><?= money((int) $item['line_total_cents']) ?></b></div>
      <?php endforeach; ?>
    </div>
    <div class="receipt-breakdown"><div><span>Subtotal</span><strong><?= money((int) $order['subtotal_cents']) ?></strong></div><?php if ((int) $order['discount_cents'] > 0): ?><div><span><?= e($order['discount_label'] ?: 'Discount') ?></span><strong>−<?= money((int) $order['discount_cents']) ?></strong></div><?php endif; ?><?php if ((int) $order['fee_cents'] > 0): ?><div><span>Fee</span><strong><?= money((int) $order['fee_cents']) ?></strong></div><?php endif; ?><?php if ((int) $order['tax_cents'] > 0): ?><div><span>Tax</span><strong><?= money((int) $order['tax_cents']) ?></strong></div><?php endif; ?><div class="receipt-total"><span>Total</span><strong><?= money((int) $order['total_cents']) ?></strong></div></div>
    <?php if ($order['status'] === 'cancelled' && !empty($order['inventory_released'])): ?>
      <p class="inventory-restored"><i data-lucide="rotate-ccw"></i>Reserved stock was returned to inventory.</p>
    <?php endif; ?>
  </div>

  <div class="admin-panel">
    <h2>Customer and fulfillment</h2>
    <dl class="detail-list">
      <div><dt>Name</dt><dd><?= e($order['customer_name']) ?></dd></div>
      <div><dt>Phone</dt><dd><a href="tel:<?= e($order['customer_phone']) ?>"><?= e($order['customer_phone']) ?></a></dd></div>
      <div><dt>Email</dt><dd><a href="mailto:<?= e($order['customer_email']) ?>"><?= e($order['customer_email']) ?></a></dd></div>
      <div><dt>Source</dt><dd><?= ($order['order_source'] ?? 'online') === 'pos' ? 'Point of sale' : 'Online store' ?></dd></div>
      <div><dt>Method</dt><dd><?= ($order['order_source'] ?? 'online') === 'pos' ? ($order['payment_method'] === 'cash' ? 'Cash' : 'External card terminal') : e(ucfirst($order['fulfillment'])) ?></dd></div>
      <?php if ($order['fulfillment'] === 'delivery'): ?><div><dt>Address</dt><dd><?= e($order['address1'].' '.$order['address2'].', '.$order['city'].', '.$order['state'].' '.$order['postal_code']) ?></dd></div><?php endif; ?>
      <div><dt>Requested time</dt><dd><?= e($order['requested_time'] ?: 'ASAP / confirm with customer') ?></dd></div>
    </dl>
  </div>

  <?php if (can('orders.manage')): ?><form method="post" class="admin-panel order-update">
    <?= csrf_field() ?>
    <h2>Update order</h2>
    <?php if ($order['status'] === 'cancelled'): ?>
      <p class="form-hint"><i data-lucide="lock-keyhole"></i>Cancelled orders are final because stock has been restored.</p>
      <input type="hidden" name="status" value="cancelled">
    <?php else: ?>
      <label>Status<select name="status"><?php foreach (['awaiting_confirmation','confirmed','preparing','ready','out_for_delivery','completed','cancelled'] as $status): ?><option value="<?= $status ?>" <?= $order['status'] === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <label>Payment status<select name="payment_status"><?php foreach (['pending','due','authorized','paid','refunded','failed'] as $status): ?><option value="<?= $status ?>" <?= $order['payment_status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
    <label>Internal notes<textarea name="staff_notes" rows="5"><?= e($order['staff_notes']) ?></textarea></label>
    <button class="button button-primary button-wide" type="submit">Save changes<i data-lucide="save"></i></button>
  </form><?php endif; ?>
</section>
