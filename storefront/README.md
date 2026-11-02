# Feriwala storefront

A customer-facing shop for one Feriwala partner website, built on **Laravel 13** (PHP 8.3+, MySQL).
It speaks to the ERP only through the frozen storefront API
([contract v1](../requirements/05-storefront-api-contract.md)).

It reads the catalogue, quotes carts, submits orders, and shows order status. It never decides a
price: every figure a customer sees was worked out by the ERP, and an order is submitted with exactly
those figures. If the ERP's price differs by `0.01`, the customer sees the new total and must confirm
it again.

## What it does

- Home, catalogue, category and search pages with the ERP's filters and sorts; product pages with
  variants, gallery and availability.
- A cart that remembers SKUs and quantities (never prices) and is priced by `POST /checkout/quote`.
- Guest checkout with cash on delivery or online payment, a review step, price-change
  reconfirmation, and idempotent order submission.
- Order page: pay, cancel, confirm a cash-on-delivery code, request a return.
- Order lookup by reference **and** mobile number (rate-limited). Knowing a number grants nothing.
- English and Bangla, light and dark, mobile-first.
- Webhook receiver: HMAC-verified, replay-protected, deduplicated.

## Requirements

PHP 8.3+ with `pdo_mysql`, `curl`, `mbstring`; Composer; MySQL 5.7+/8; HTTPS in production (the ERP
refuses plain HTTP, and its payment return URL must be `https://`). No Node build: the CSS and
JavaScript are plain files in `public/assets`.

## Install

1. `composer install --no-dev --optimize-autoloader`
2. Point the web server's document root at **`public/`** — nothing else is served.
3. Create a database and a user for it.
4. **Connect the store in the ERP.** In the admin dashboard open the partner's website and use
   _Connect the store_: give this storefront's address and it shows a **Store ID** and a **token**
   (once). Platform staff authorised to manage integrations can do this on the partner's behalf.
5. `cp .env.example .env`, fill it in (the database, `APP_URL`, and the three connection lines the
   dashboard showed — `ERP_BASE_URL`, `STORE_ID`, `STORE_TOKEN`), then `php artisan key:generate`.
   (`WEBHOOK_SECRET` is optional; the dashboard shows it when it registered a webhook.)
6. `php artisan migrate --force` — creates the tables; safe to run again, and an installation that
   was running the earlier plain-PHP storefront upgrades in place with the same command (its tables
   are kept; only the session table is rebuilt). On cPanel or any host with no shell, open
   `https://your-domain/migrate` instead (add `?token=…` when `MIGRATE_TOKEN` is set in `.env`, which
   is recommended).
7. `php artisan storefront:doctor` — checks the database, the connection to the ERP, the website's
   status. Exit code 1 if something needs attention.
8. Cron, every minute: `php /path/to/storefront/artisan schedule:run` (or, hourly,
   `php artisan storefront:prune` — expired cache, abandoned baskets, unplaced checkouts).
9. In production: `php artisan config:cache route:cache view:cache`.

Behind a load balancer or proxy you control, set `TRUSTED_PROXY=1` so the client address and scheme
are read from `X-Forwarded-*`. Leave it `0` otherwise.

Local development: `php artisan serve`.

### Differences from the plain-PHP storefront that affect an existing `.env`

- `SESSION_LIFETIME` is in **minutes** (it was seconds). `.env.example` shows the default.
- `DB_NAME`, `DB_USER`, `DB_PASS` and `ERP_KEY_ID` / `ERP_SECRET` are still read.
- `APP_KEY` is new and required (`php artisan key:generate`).
- Customers' open sessions end once at the upgrade; baskets (the `sf_cart` cookie) and orders survive.

## Tests

```bash
vendor/bin/pest
```

The tests use an in-memory SQLite database and fake the ERP with `Http::fake()`, so they need no
database server and never reach a real ERP.

## Layout

```
app/Erp/          signed ERP client, signature, exception
app/Services/     Catalog, Site, Basket, Checkouts, Orders, Webhooks, ApiCache
app/Http/         controllers, middleware (security headers, shop status), checkout form request
app/Models/       Cart, CartItem, Checkout, Order
app/Console/      storefront:doctor, storefront:prune
resources/views/  Blade templates     lang/  en.json, bn.json
routes/           web.php (the shop), system.php (health, migrate, webhook — no session)
public/assets/    CSS, JS, images
```

## Money and security notes

- Money is a decimal string end to end. Nothing here uses a float for an amount, and nothing sends
  `minor_units`.
- Every state-changing form carries a CSRF token; every ERP value is escaped on output; product HTML
  is reduced to text; the CSP allows scripts from this origin only.
- An order is reached through its own unguessable token or the session that placed it — never by
  its id, and never by a mobile number alone.
