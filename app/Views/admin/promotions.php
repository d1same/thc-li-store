<section class="admin-page-head">
  <div><span class="eyebrow">Marketing</span><h1>Promotions</h1><p>Create website offers with clear start and end dates.</p></div>
</section>

<section class="admin-grid-two promotions-layout">
  <div class="admin-panel">
    <div class="panel-head"><div><h2>Current promotions</h2></div></div>
    <?php if ($promotions): ?>
      <div class="promotion-admin-list">
        <?php foreach ($promotions as $promo): ?>
          <article class="<?= $promo['active'] ? '' : 'inactive' ?>">
            <div>
              <strong><?= e($promo['title']) ?></strong>
              <p><?= e($promo['description']) ?></p>
              <small><?= $promo['starts_at'] ? e($promo['starts_at']) : 'Starts immediately' ?> → <?= $promo['ends_at'] ? e($promo['ends_at']) : 'No end date' ?></small>
            </div>
            <div class="promotion-actions">
              <form method="post" action="<?= url('admin/promotions/'.$promo['id'].'/toggle') ?>"><?= csrf_field() ?><button class="button button-small button-secondary" type="submit"><?= $promo['active'] ? 'Pause' : 'Enable' ?></button></form>
              <form method="post" action="<?= url('admin/promotions/'.$promo['id'].'/delete') ?>" data-confirm="Delete ‘<?= e($promo['title']) ?>’? This cannot be undone."><?= csrf_field() ?><button class="button button-small button-danger" type="submit"><i data-lucide="trash-2"></i>Delete</button></form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state small"><i data-lucide="badge-percent"></i><h3>No promotions yet</h3><p>Create an offer using the form.</p></div>
    <?php endif; ?>
  </div>

  <form method="post" class="admin-panel promotion-create-panel">
    <?= csrf_field() ?>
    <div class="promotion-create-head"><span class="step-label">New offer</span><h2>Add promotion</h2><p>Create and schedule an offer from one simple form.</p></div>
    <div class="promotion-create-fields">
      <label class="promotion-title">Title<input name="title" required></label>
      <label>Starts<input type="datetime-local" name="starts_at"></label>
      <label>Ends<input type="datetime-local" name="ends_at"></label>
      <label class="promotion-description">Description<textarea name="description" rows="4" required></textarea></label>
    </div>
    <div class="promotion-create-footer">
      <p class="form-hint">Cannabis giveaways and “free” product offers must not be published.</p>
      <button class="button button-primary" type="submit">Create promotion<i data-lucide="plus"></i></button>
    </div>
  </form>
</section>
