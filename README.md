# Local Shop

A mobile-first PHP 8.3+ and SQLite storefront for a single local shop. It includes a structured menu, customer accounts, guest checkout, pickup/delivery controls, order history, and a nontechnical owner dashboard.

## Requirements

- PHP 8.3 or newer
- PDO SQLite, SQLite3, fileinfo, mbstring, session, JSON, and OpenSSL extensions
- Apache with `mod_rewrite` and `.htaccess` overrides enabled
- HTTPS in production

## First run

1. For the simplest cPanel preview, clone the repository directly into the domain or subdomain document root. The root front controller securely serves the application from `public/` automatically. Pointing the document root directly to `public/` remains supported for production.
2. Copy `.env.example` to `.env`, set a unique `APP_KEY`, and set a separate random `APP_SETUP_TOKEN` of at least 32 characters.
3. Confirm `storage/` and `public/uploads/` are writable by PHP.
4. Visit `/setup`, enter the one-time setup token, and create the first owner account. Remove `APP_SETUP_TOKEN` from `.env` after setup.
5. Open **Owner desk → Settings** and configure the license, service areas, pickup location, delivery minimums, hours, and approved payment methods.
6. Verify `/health` returns `"ok": true`.

The SQLite database, runtime configuration, uploaded photos, and backups must not be committed to Git or overwritten during deployment.

## Inventory

- Inventory is tracked per product option, so each size, flavor, or package can have its own quantity.
- In **Owner desk → Products**, leave **Quantity left** blank for an untracked option or enter a whole number to track it.
- Tracked status is automatic: 6 or more is in stock, 1–5 is low stock, and 0 is sold out.
- Placing an order reserves stock immediately. Cancelling the order restores those units exactly once.
- The Products table and owner dashboard show low-stock and sold-out options. Checkout rechecks inventory to prevent overselling.
- The homepage has eight featured-product slots. Checking **Feature this product on the homepage** moves a newly featured active product to the first slot and automatically releases the oldest slot; the same eight are available in the mobile product rail.

## Point of sale

- Open **Owner desk → Point of sale** on a tablet or browser for walk-in checkout.
- POS sales use the same products, variants, inventory, customer accounts, orders, and audit history as the storefront.
- Cash and external card-terminal payments are supported. The application records the payment result but never collects or stores card numbers.
- POS tax, printed receipts, emailed receipts, and manual discounts can each be enabled or disabled under **Owner desk → Settings**.
- Customer capture is prioritized at checkout: staff can find an existing client or add a name plus phone/email, while an explicit anonymous walk-in option remains available.
- Anonymous sales still count in revenue and sales reports but do not create customer records. ID/age verification is required before completing every in-store sale.
- Cancelling a completed POS order restores tracked inventory exactly once.

## Customers and sales reports

- **Owner desk → Customers** combines registered accounts, online guests, and identified POS clients in one searchable directory.
- Customer profiles include contact details, address, status, marketing consent, private staff notes, lifetime sales, favorite products, and complete order history.
- Owners can export all customers or only marketing-approved contacts as a CSV download. Exports are streamed securely and recorded in the audit log.
- **Owner desk → Sales & Reports** includes revenue, paid orders, units sold, average sale, new customers, tax, discounts, top products/options/categories, payment and source breakdowns, staff sales, and busiest times.
- Revenue includes only paid, completed orders. Cancelled orders remain permanently visible in order history but are excluded from revenue.
- Report date ranges use the business city and timezone configured in **Owner desk → Settings**. Existing timestamps are never rewritten when the timezone changes.

## Receipts, customer imports, and campaigns

- Receipt and order-confirmation emails enter a private queue. The order page shows queued, sent, or failed status, and authorized staff can resend a receipt.
- New online-order alerts are sent to `orders@thc-li.com` with a secure Admin link; full delivery details remain in the authenticated order screen.
- Run the queue every five minutes from cPanel Cron: `*/5 * * * * /usr/bin/php /home/thclidave/public_html/scripts/email-worker.php` (confirm the PHP path first).
- Use `receipts@thc-li.com` for receipts/order confirmations and `updates@thc-li.com` for approved product campaigns. Configure their sender names and reply-to addresses together with the physical business address, license, and SPF/DKIM/DMARC verification under **Owner desk → Settings** before enabling email.
- Under **Settings → Mail transport**, choose **Authenticated SMTP**, enter the host, port, encryption, full mailbox username, and mailbox password, then save. The SMTP password is encrypted with `APP_KEY` and is never displayed again.
- For Stablepoint/cPanel, copy the exact values from **Email Accounts → Connect Devices**. Typical settings are `mail.thc-li.com` with SSL port `465`, or STARTTLS port `587`, and the full `receipts@thc-li.com` address as the username.
- After saving, use **Test saved SMTP connection**. This verifies the network connection, certificate/encryption, and mailbox login without sending a customer message. Connection tests are rate-limited and audited.
- Customer imports accept CSV files exported from Excel. Download the template, upload for an encrypted preview, and confirm after reviewing matches. The importer never replaces order history or existing private notes.
- Marketing imports require an explicit yes plus a consent date and source. Unsubscribed contacts remain suppressed unless they later give new explicit consent.
- Product campaigns are always drafts first. An authorized user must approve each campaign; only eligible opted-in customers are queued, and every message contains an unsubscribe link and compliance footer.
- To create (but never send) a monthly draft automatically, run `0 9 * * * /usr/bin/php /home/thclidave/public_html/scripts/monthly-campaign-draft.php`. The configured day controls when the draft is created.

Authenticated SMTP is recommended. PHP `mail()` remains available as a rollback option. Confirm delivery with the host and set SPF, DKIM, and DMARC for the exact sending domain. A “sent” status records transport acceptance, not guaranteed inbox placement.

## Staff permissions

- The owner always retains full access.
- In **Owner desk → Staff**, create staff or manager logins and choose their permissions with checkboxes.
- POS access, sale approval, manual discounts, reports, customer viewing/editing/export, order management, product creation/editing/archiving, promotions, and settings are independently controlled.
- Product removal is implemented as archiving so historical orders and receipts remain intact.

## Security

- Admin sessions expire after 30 minutes of inactivity or eight hours total, rotate periodically, and are invalidated when staff access or passwords change.
- Login protection applies separate account and IP throttles. Registration, checkout, setup, customer lookup, imports, exports, and campaign approvals also have abuse limits with HTTP `429` responses.
- Staff temporary passwords must be changed before Admin can be used. Owners can reset a staff password without learning the old one.
- TOTP authenticator MFA and one-time recovery codes are available under **Account security**. Enroll the owner first, verify a fresh sign-in, then enable **Require MFA for all staff** in Settings.
- POS-only staff can view only receipts for sales completed using their own account unless they also have `orders.view`.
- The public health endpoint returns only service status. Detailed checks appear only to a signed-in owner.
- Review account posture and recent authentication/security events under **Owner desk → Security**.
- See `SECURITY.md` for cPanel permissions, owner activation, recovery, and incident-response guidance.

## Local preview

```powershell
php -S 127.0.0.1:8090 -t public public/dev-router.php
```

Then open `http://127.0.0.1:8090`.

### Laragon / Apache

This workstation is configured to serve the project through Laragon at:

```text
http://localhost/dave/
```

The Apache alias configuration is stored at:

```text
C:\laragon\etc\apache2\sites-enabled\dave-local.conf
```

It points directly to this repository's `public/` directory and sets `APP_BASE=/dave`, so `.htaccess`, clean routes, SQLite, and uploads are tested using the same Apache behavior expected on cPanel.

## cPanel deployment

### Simple subdomain preview

1. Create a subdomain and leave its document root at the repository folder, such as `/home/USER/thcli`.
2. Clone this repository into that folder with cPanel Git Version Control.
3. Copy `.env.example` to `.env`, add unique `APP_KEY` and `APP_SETUP_TOKEN` values, and set the production URL.
4. Make `storage/` and `public/uploads/` writable, then visit `/setup`. Remove `APP_SETUP_TOKEN` after the owner is created.

No file moving or `/public` document-root change is required for this preview setup.

### THC LI direct-root production release

The live repository is checked out directly at `/home/thclidave/public_html`; its checkout is the application. A `.cpanel.yml` deployment recipe is intentionally not included because this layout updates in place.

Recommended release process:

1. Push a tested commit to GitHub.
2. In cPanel Git Version Control, select **Update from Remote**.
3. Do **not** select **Deploy HEAD Commit** for this direct-root layout.
4. Visit `/health`, verify the owner dashboard, and open **Owner desk → Security**.
5. Keep database and upload backups outside Git; Git updates must never overwrite `.env`, `storage`, or customer uploads.

Create a nightly cPanel Cron Job using the account's PHP 8.3 binary and the private application path, for example:

```text
15 3 * * * /usr/bin/php /home/thclidave/public_html/scripts/backup.php
```

Confirm the actual PHP binary path in cPanel before enabling the job.

## Operational safety

- Delivery online payments remain disabled until an approved provider is configured.
- Pay-at-pickup appears only for pickup orders.
- Delivery orders use the prepaid-arrangement method until a provider adapter is added.
- Purchase limits and current product availability are revalidated on the server.
- Final legal, payment, tax, marketing, and license configuration must be reviewed for the store's jurisdiction before launch.
