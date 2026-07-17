# Security operations

## Production requirements

- Keep `.env`, `storage/`, database files, backups, imports, scripts, migrations, tests, and `.git` blocked from HTTP. The included root `.htaccess` enforces this for the direct cPanel checkout layout.
- Set `APP_ENV=production`, `APP_URL=https://thc-li.com`, a unique random `APP_KEY` of at least 32 characters, and a separate one-time `APP_SETUP_TOKEN` before first setup.
- Remove `APP_SETUP_TOKEN` after the owner exists. `/setup` closes after the first owner is created.
- Use HTTPS only. Confirm the live response includes HSTS, CSP, `nosniff`, frame protection, referrer policy, and a `Secure; HttpOnly; SameSite=Lax` session cookie.
- In cPanel File Manager, use `700` for `storage`, `storage/backups`, and `storage/imports`; use `600` for `.env`, SQLite files, imports, and backup files. The application also applies restrictive permissions when it creates these files.
- Keep the five-minute email worker and nightly backup commands CLI-only.

## Owner activation checklist

1. Sign in and open **Security → My security**.
2. Replace any temporary password.
3. Enroll an authenticator app and store the one-time recovery codes offline.
4. Confirm you can sign out and sign back in with MFA.
5. Open **Settings → Staff security** and enable **Require MFA for all staff**.
6. Open **Security center** and resolve every item marked **Action needed**.
7. Give staff only the permissions required for their job. Staff must change temporary passwords on first use.

## Recovery and response

- If an account may be compromised, disable it under **Staff**. Role, status, permission, and password-reset changes invalidate its existing sessions.
- The owner can set a new temporary staff password from **Staff**. The staff member must replace it at next sign-in.
- Preserve `storage/shop.sqlite`, `.env`, and the latest backup before incident investigation. Do not email the database or customer export.
- Review **Security center** for recent sign-ins, lockouts, MFA changes, staff changes, setup attempts, and customer exports.
- Rotate `APP_KEY` only with a planned MFA reset: existing encrypted MFA secrets and import previews depend on it.

## Automated checks

The GitHub workflow runs PHP linting, an isolated SQLite smoke suite, full-history Gitleaks scanning, Semgrep SAST, and Trivy filesystem scanning. Third-party actions are pinned to immutable commit SHAs and Dependabot monitors action updates.

Automated scans supplement but do not replace periodic manual authorization and business-logic testing.
