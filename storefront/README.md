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
3. `cp .env.example .env` and fill it in (database, `APP_URL`, `ERP_BASE_URL`, `ERP_KEY_ID`,
   `ERP_SECRET`, `WEBHOOK_SECRET`). The credential comes from the ERP: *Websites → API credentials*,
   with scopes `catalog:read`, `inventory:read`, `orders:read`, `orders:write`, `customers:write`,
   `returns:write`.
4. `php bin/migrate.php` — creates the tables; safe to run again.
5. `php bin/doctor.php` — checks the database, the ERP credentials, the website's status and the
   webhook secret. Exit code 1 if something needs attention.
6. In the ERP, register the webhook endpoint `https://<your-host>/webhooks/feriwala` and copy its
   signing secret into `WEBHOOK_SECRET`. During a rotation put the old one in
   `WEBHOOK_SECRET_PREVIOUS`.
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
