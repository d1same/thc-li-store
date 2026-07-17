<section class="auth-shell container">
  <div class="auth-card">
    <span class="eyebrow">Protected sign-in</span>
    <h1>Security verification</h1>
    <p>Enter the six-digit code from your authenticator app. A saved recovery code also works.</p>
    <form method="post" class="form-stack">
      <?= csrf_field() ?>
      <label>Authenticator or recovery code<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="20" autofocus required></label>
      <button class="button button-primary button-wide" type="submit">Verify and continue<i data-lucide="shield-check"></i></button>
    </form>
    <p class="form-foot"><a href="<?= url('login') ?>">Restart sign-in</a></p>
  </div>
</section>
