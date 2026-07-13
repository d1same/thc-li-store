# Local Shop

A mobile-first PHP 8.3+ and SQLite storefront for a single local shop. It includes a structured menu, customer accounts, guest checkout, pickup/delivery controls, order history, and a nontechnical owner dashboard.

## Requirements

- PHP 8.3 or newer
- PDO SQLite, SQLite3, fileinfo, mbstring, session, JSON, and OpenSSL extensions
- Apache with `mod_rewrite` and `.htaccess` overrides enabled
- HTTPS in production

## First run

1. For the simplest cPanel preview, clone the repository directly into the domain or subdomain document root. The root front controller securely serves the application from `public/` automatically. Pointing the document root directly to `public/` remains supported for production.
2. Confirm `storage/` and `public/uploads/` are writable by PHP.
3. Visit `/setup` and create the first owner account.
4. Open **Owner desk → Settings** and configure the license, service areas, pickup location, delivery minimums, hours, and approved payment methods.
5. Verify `/health` returns `"ok": true`.

The SQLite database, runtime configuration, uploaded photos, and backups must not be committed to Git or overwritten during deployment.

## Inventory

- Inventory is tracked per product option, so each size, flavor, or package can have its own quantity.
- In **Owner desk → Products**, leave **Quantity left** blank for an untracked option or enter a whole number to track it.
- Tracked status is automatic: 6 or more is in stock, 1–5 is low stock, and 0 is sold out.
- Placing an order reserves stock immediately. Cancelling the order restores those units exactly once.
- The Products table and owner dashboard show low-stock and sold-out options. Checkout rechecks inventory to prevent overselling.

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

## Staff permissions

- The owner always retains full access.
- In **Owner desk → Staff**, create staff or manager logins and choose their permissions with checkboxes.
- POS access, sale approval, manual discounts, reports, customer viewing/editing/export, order management, product creation/editing/archiving, promotions, and settings are independently controlled.
- Product removal is implemented as archiving so historical orders and receipts remain intact.

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
3. Copy `.env.example` to `.env`, add a unique `APP_KEY`, and set the production URL.
4. Make `storage/` and `public/uploads/` writable, then visit `/setup`.

No file moving or `/public` document-root change is required for this preview setup.

### Separate production release

The included `.cpanel.yml` is a starting point. Replace `CPANEL_USER` with the actual cPanel username and confirm the target paths before enabling deployment. Keep the repository checkout separate from the live application and persistent data.

Recommended release process:

1. Push a tested commit to GitHub.
2. In cPanel Git Version Control, select **Update from Remote**.
3. Review the commit, then select **Deploy HEAD Commit**.
4. Visit `/health` and verify the owner dashboard.
5. Keep the previous release until the verification passes.

Create a nightly cPanel Cron Job using the account's PHP 8.3 binary and the private application path, for example:

```text
15 3 * * * /usr/local/bin/php /home/CPANEL_USER/dispensary-app/scripts/backup.php
```

Confirm the actual PHP binary path in cPanel before enabling the job.

## Operational safety

- Delivery online payments remain disabled until an approved provider is configured.
- Pay-at-pickup appears only for pickup orders.
- Delivery orders use the prepaid-arrangement method until a provider adapter is added.
- Purchase limits and current product availability are revalidated on the server.
- Final legal, payment, tax, marketing, and license configuration must be reviewed for the store's jurisdiction before launch.
