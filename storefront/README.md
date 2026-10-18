# Feriwala storefront

A customer-facing shop for one Feriwala partner website, in plain **PHP 8.1+ and MySQL** — no
framework and no Composer, so it runs on ordinary shared hosting. It speaks to the ERP only through
the frozen storefront API ([contract v1](../requirements/05-storefront-api-contract.md)).

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

PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`; MySQL 5.7+/8; HTTPS in production (the ERP refuses
plain HTTP, and its payment return URL must be `https://`).

## Install

1. Point the web server's document root at **`storefront/public/`** — nothing else is served.
2. Create a database and a user for it.
3. **Connect the store in the ERP.** In the admin dashboard open the partner's website and use
   _Connect the store_: give this storefront's address and it shows a **Store ID** and a **token**
   (once). Platform staff authorised to manage integrations can do this on the partner's behalf.
4. `cp .env.example .env` and fill it in: the database, `APP_URL`, and the three connection lines
   the dashboard showed — `ERP_BASE_URL`, `STORE_ID`, `STORE_TOKEN`. That is the whole connection.
   (`WEBHOOK_SECRET` is optional; the dashboard shows it when it registered a webhook.)
5. `php bin/migrate.php` — creates the tables; safe to run again. On cPanel or any host with no
   shell, open `https://your-domain/migrate` instead (add `?token=…` when `MIGRATE_TOKEN` is set in
   `.env`, which is recommended). It applies the same migrations and is equally safe to repeat.
6. `php bin/doctor.php` — checks the database, the connection to the ERP, the website's status.
   Exit code 1 if something needs attention.
7. Cron, hourly: `php /path/to/storefront/bin/prune.php` (expired sessions and cache, abandoned
   baskets).

Behind a load balancer or proxy you control, set `TRUSTED_PROXY=1` so the client address and scheme
are read from `X-Forwarded-*`. Leave it `0` otherwise.

## Tests

```bash
TEST_DB_NAME=storefront_test TEST_DB_USER=… TEST_DB_PASS=… php tests/run.php
```

`TEST_DB_NAME` must contain `test` — the suite empties that database's tables. The tests use a fake
ERP; the signature test additionally checks parity with the ERP's own `RequestSignature` class when
run inside this repository.

## Layout

```
public/      the web root: index.php, assets
src/         Kernel, Http, Erp (signed client), Domain, Controllers, Support
views/       templates      lang/   en.php, bn.php
database/    migrations     bin/    migrate, doctor, prune
tests/       dependency-free runner
```

## Money and security notes

- Money is a decimal string end to end. Nothing here uses a float for an amount, and nothing sends
  `minor_units`.
- Every state-changing form carries a CSRF token; every ERP value is escaped on output; product HTML
  is reduced to text; the CSP allows scripts from this origin only.
- An order is reached through its own unguessable token or the session that placed it — never by
  its id, and never by a mobile number alone.
