<section class="admin-page-head">
  <div><span class="eyebrow">Security</span><h1>Staff access</h1><p>Give each person only the tools they need. Every permission is enforced on the server.</p></div>
</section>

<section class="staff-access-layout">
  <div class="staff-access-list">
    <?php foreach ($staff as $member): $memberPermissions = $staffPermissions[(int) $member['id']] ?? []; ?>
      <?php if ($member['role'] === 'owner'): ?>
        <article class="admin-panel staff-access-card owner-card">
          <div class="staff-card-head"><span class="order-icon"><i data-lucide="shield-check"></i></span><div><strong><?= e($member['name']) ?></strong><small><?= e($member['email']) ?></small></div><span class="status-pill">Owner</span></div>
          <p>The owner always has full access and cannot be restricted by staff checkboxes.</p>
        </article>
      <?php else: ?>
        <form method="post" action="<?= url('admin/staff/' . $member['id']) ?>" class="admin-panel staff-access-card">
          <?= csrf_field() ?>
          <div class="staff-card-head"><span class="order-icon"><i data-lucide="user-round-cog"></i></span><div><strong><?= e($member['name']) ?></strong><small><?= e($member['email']) ?></small></div><span class="status-pill status-<?= e($member['status']) ?>"><?= e(ucfirst($member['status'])) ?></span></div>
          <div class="staff-meta-fields">
            <label>Role<select name="role"><option value="staff" <?= $member['role'] === 'staff' ? 'selected' : '' ?>>Staff</option><option value="manager" <?= $member['role'] === 'manager' ? 'selected' : '' ?>>Manager</option></select></label>
            <label>Status<select name="status"><option value="active" <?= $member['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="disabled" <?= $member['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option></select></label>
          </div>
          <fieldset class="permission-fieldset"><legend>Allowed actions</legend><div class="permission-grid">
            <?php foreach ($permissions as $key => $label): ?><label class="permission-check"><input type="checkbox" name="permissions[]" value="<?= e($key) ?>" <?= isset($memberPermissions[$key]) ? 'checked' : '' ?>><span><i data-lucide="check"></i><?= e($label) ?></span></label><?php endforeach; ?>
          </div></fieldset>
          <button class="button button-primary" type="submit"><i data-lucide="save"></i>Save access</button>
        </form>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <form method="post" class="admin-panel staff-create-card">
    <?= csrf_field() ?>
    <span class="step-label">New staff account</span><h2>Add team member</h2><p>Create the login, then choose exactly what this person can do.</p>
    <div class="form-grid">
      <label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label>
      <label>Phone<input type="tel" name="phone"></label><label>Role<select name="role"><option value="staff">Staff</option><option value="manager">Manager</option></select></label>
      <label class="full">Temporary password <small>At least 12 characters</small><input type="password" name="password" minlength="12" required></label>
    </div>
    <fieldset class="permission-fieldset"><legend>Initial permissions</legend><div class="permission-grid">
      <?php foreach ($permissions as $key => $label): $checked = in_array($key, ['pos.access','pos.complete','orders.view','products.view'], true); ?><label class="permission-check"><input type="checkbox" name="permissions[]" value="<?= e($key) ?>" <?= $checked ? 'checked' : '' ?>><span><i data-lucide="check"></i><?= e($label) ?></span></label><?php endforeach; ?>
    </div></fieldset>
    <button class="button button-primary button-wide" type="submit">Create staff account<i data-lucide="user-plus"></i></button>
  </form>
</section>
