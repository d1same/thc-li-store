<section class="container account-head">
  <div><span class="eyebrow">Account protection</span><h1>Security</h1><p>Use a unique password and protect staff access with an authenticator app.</p></div>
  <?php if(is_admin()): ?><a class="button button-secondary" href="<?= url('admin') ?>">Return to Admin</a><?php else: ?><a class="button button-secondary" href="<?= url('account') ?>">Return to account</a><?php endif; ?>
</section>

<?php if(!empty($securityUser['must_change_password'])): ?><div class="container flash flash-warning" role="status">Your temporary password must be replaced before you can continue.</div><?php endif; ?>
<?php if(is_admin() && setting('staff_mfa_required',false) && empty($securityUser['mfa_enabled_at'])): ?><div class="container flash flash-warning" role="status">Two-factor authentication is required for staff. Complete setup below.</div><?php endif; ?>

<section class="container security-account-grid">
  <form method="post" action="<?= url('account/security/password') ?>" class="account-panel form-stack">
    <?= csrf_field() ?>
    <span class="step-label">Password</span><h2>Change password</h2>
    <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
    <label>New password <small><?= is_admin()?'At least 12 characters':'At least 10 characters' ?></small><input type="password" name="new_password" minlength="<?= is_admin()?12:10 ?>" maxlength="200" autocomplete="new-password" required></label>
    <button class="button button-primary" type="submit">Update password<i data-lucide="key-round"></i></button>
  </form>

  <div class="account-panel form-stack">
    <span class="step-label">Two-factor authentication</span><h2>Authenticator app</h2>
    <?php if(!empty($securityUser['mfa_enabled_at'])): ?>
      <p><span class="status-pill status-active">Enabled</span> Codes are required after your password when signing in.</p>
      <form method="post" action="<?= url('account/security/mfa/disable') ?>" class="form-stack">
        <?= csrf_field() ?>
        <label>Current password<input type="password" name="password" autocomplete="current-password" required></label>
        <label>Authenticator or recovery code<input name="code" autocomplete="one-time-code" required></label>
        <button class="button button-danger" type="submit">Disable two-factor authentication</button>
      </form>
    <?php elseif($pendingSecret): ?>
      <ol class="security-steps"><li>Open Google Authenticator, Microsoft Authenticator, 1Password, or another TOTP app.</li><li>Add an account manually using the key below.</li><li>Enter the generated six-digit code to confirm.</li></ol>
      <code class="mfa-secret"><?= e($pendingSecret) ?></code>
      <a class="button button-secondary" href="<?= e($provisioningUri) ?>">Open authenticator app</a>
      <form method="post" action="<?= url('account/security/mfa/confirm') ?>" class="form-stack">
        <?= csrf_field() ?>
        <label>Six-digit code<input name="code" inputmode="numeric" autocomplete="one-time-code" minlength="6" maxlength="6" required></label>
        <button class="button button-primary" type="submit">Confirm two-factor authentication<i data-lucide="shield-check"></i></button>
      </form>
    <?php else: ?>
      <p>Add a second sign-in step so a stolen password cannot open Admin.</p>
      <form method="post" action="<?= url('account/security/mfa/start') ?>" class="form-stack"><?= csrf_field() ?><label>Current password<input type="password" name="password" autocomplete="current-password" required></label><button class="button button-primary" type="submit">Set up authenticator<i data-lucide="smartphone"></i></button></form>
    <?php endif; ?>
  </div>
</section>

<?php if($recoveryCodes): ?>
<section class="container account-panel recovery-code-panel">
  <span class="step-label">Shown once</span><h2>Save your recovery codes</h2><p>Store these somewhere private. Each code works once if your authenticator is unavailable.</p>
  <div class="recovery-code-grid"><?php foreach($recoveryCodes as $code): ?><code><?= e($code) ?></code><?php endforeach; ?></div>
</section>
<?php endif; ?>
