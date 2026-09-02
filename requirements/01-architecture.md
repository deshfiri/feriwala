# 01 — Architecture & Technical Decisions

Decisions here are my proposals. Anything marked **[DECIDE]** is in
[99-open-questions.md](99-open-questions.md) and I will not lock it in without you.

## 1. Stack (fixed by §2 / §45)

Laravel 13 · React 19 · Inertia 3 · PostgreSQL · Redis. Non-negotiable.

Kept from the starter kit: Fortify (auth), Wayfinder (typed routes), Pest 5, Larastan, Pint,
Tailwind 4 + Radix. These are aligned with the spec and dropping them would cost time for nothing.

## 2. Modular layout

§2 requires "modular architecture so that core business modules, payment gateways, SMS providers,
courier providers, and website integrations can be extended without rewriting the application."

I propose **domain folders inside a single Laravel app** rather than a package/module system
(`nwidart/laravel-modules`). Reason: one deployable, one migration set, one test suite, no
autoload indirection — while still giving hard module boundaries. A package system buys isolation
we do not need and costs friction on every cross-module query, of which this system has many
(wallet ↔ order ↔ commission ↔ referral ↔ withdrawal).

```
app/
  Domain/
    Account/        # user, statuses, activation, profile
    Kyc/
    Package/
    Billing/        # payments, invoices, fees, coupons, tax
    Wallet/         # wallet, ledger, balances, deposits, adjustments
    Catalog/        # products, categories, brands, attributes, variations
    Inventory/      # stock, reservations, movements, warehouses
    Wholesale/      # ERP cart + checkout + wholesale orders
    Dropshipping/   # product selection, publication, website products
    Website/        # dedicated site provisioning, domains, hosting, status
    Order/          # unified OMS: order, items, statuses, history, notes
    Fulfillment/
    Courier/
    Commission/
    Referral/
    Withdrawal/
    Settlement/     # COD, reconciliation
    Notification/   # dashboard notifications
    Sms/
    Reporting/
    Cms/            # landing page sections, public pages, revisions
    Audit/
    Backup/
    Access/         # roles, permissions, policies
  Integrations/
    Payment/        # contract + Eps, SslCommerz, SurjoPay, AmarPay, Bkash, Nagad, Stripe, PayPal
    Sms/            # contract + BulkSmsBd, NovaSms, SslCommerzSms, Twilio
    Courier/        # contract + drivers
  Support/          # money, idempotency, locks, state machines, shared casts/rules
  Http/
    Controllers/{Public,Erp,Admin,Api,Webhook}
    Middleware/
    Requests/
```

Each `Domain/X` holds: `Models/`, `Actions/`, `Data/` (DTOs), `Enums/`, `Events/`,
`Listeners/`, `Jobs/`, `Policies/`, `Rules/`, `Services/`, `Queries/`.

**Business logic lives in Actions.** Controllers validate + authorize + delegate + respond.
Models hold relationships, casts, and scopes — not workflow.

Frontend mirrors it:

```
resources/js/
  pages/{public,onboarding,erp,admin}/<domain>/...
  layouts/{public,onboarding,erp,admin}
  components/{ui,erp,data-table,forms,money,status}
```

## 3. Money

**Non-negotiable rules:**

- Stored as **`BIGINT` minor units** (poisha), never `float`, never `double`.
- A single `Money` value object in `app/Support/Money` with an Eloquent cast; all arithmetic goes
  through it. No raw arithmetic on money columns anywhere.
- Every currency-bearing row carries a `currency` column (BDT default). **[DECIDE]** whether
  multi-currency is in scope for v1 or BDT-only with the column reserved.
- All calculation is **server-side**. The client displays; it never computes a payable amount.
- Every mutation: `DB::transaction()` + `lockForUpdate()` on the wallet/stock row.
- Every externally-triggered financial write (gateway callback, webhook, retry) is **idempotent**,
  keyed on a stored idempotency key with a unique constraint plus a Redis lock.

**Ledger invariants:**

- Append-only. No `update`, no `delete` — enforced in the model (`saving`/`deleting` guards),
  by policy, and by a DB trigger.
- Every entry stores `balance_before` and `balance_after` for the affected bucket.
- Corrections are new rows of type `adjustment` / `reversal` / `correction`, always carrying
  `reverses_entry_id` or `adjusts_entry_id`.
- A scheduled reconciliation job re-walks each wallet's ledger and asserts the derived balance
  equals the stored balance; mismatch raises a system alert (§28.1).

## 4. Statuses and state machines

The spec defines large status sets: account (22), order (28, and admin can add custom ones),
website (13), withdrawal (9), commission (8), wallet transaction (12), product (11).

Approach:

- PHP **backed enums** for fixed sets, with `label()`, `color()`, and `isTerminal()`.
- A small shared `StateMachine` trait declaring allowed transitions per model. Illegal transitions
  throw. This is what stops a "Refunded" order from being moved back to "Processing".
- Order statuses need **admin-defined custom statuses** (§18.2), so orders use a
  `order_statuses` table seeded with the system set (flagged `is_system`, undeletable), plus a
  configurable transition map. The enum becomes the seed, not the storage.
- Every status change writes a history row: previous, new, changed_by, at, reason, internal note,
  user-visible note, notification status (§18.3, §7.3).

## 5. Authorization

- **Roles/permissions:** `spatie/laravel-permission` **[DECIDE]** vs. a hand-rolled table.
  I recommend Spatie — 20 roles × 23 action verbs across ~30 modules is exactly what it is for,
  and it is battle-tested. Permissions named `<module>.<action>` (e.g. `withdrawal.approve`,
  `kyc.view_documents`).
- **Policies for every model**, no exceptions. Register in `AppServiceProvider`.
- **Self-scoping is a query concern, not a UI concern.** A global scope / dedicated query class
  restricts non-admin reads to `user_id = auth()->id()`, applied to models, reports, exports, and
  API responses alike. §31.3 explicitly forbids cross-user access "through URL changes, API
  requests, exports or modified parameters" — so the test suite gets a dedicated group that
  attempts each of those four.
- **Sensitive actions** (§32.2) require an escalation pipeline: password confirmation, optional
  2FA, optional second approver (maker-checker), mandatory reason, audit entry. I'll build this as
  one reusable middleware + action wrapper rather than re-implementing per feature.

## 6. Public identifiers

§34.2/§34.3 forbid database IDs in public URLs.

- Internal PKs: `bigint` auto-increment (fast joins).
- Every publicly-addressable model also carries a `public_id` (ULID) and/or a unique `slug`.
- Route model binding uses `slug`/`public_id`, never `id`.
- Human references get typed prefixes: `ORD-`, `PAY-`, `TXN-`, `WDR-`, `INV-`, `STL-`.
  Generated server-side, unique-indexed.

## 7. Dynamic configuration

A large share of this spec is "the admin can configure X": KYC document types, package features,
fee rules, deposit rules, commission rules, referral plans, withdrawal rules, SMS templates,
gateway credentials, order statuses.

Two mechanisms, deliberately kept apart:

1. **Settings registry** — a typed `settings` table (key, group, type, value, is_encrypted) with a
   cached accessor, for scalar/global switches. Redis-cached, invalidated on write.
2. **Rule tables** — for anything with scope precedence, effective dates, or history. Commission,
   deposit, withdrawal, and referral rules all share the same shape:

    ```
    scope_type (global|package|user|product|category|campaign|website)
    scope_id · priority · effective_from · effective_until · is_active
    ```

    plus a shared **rule resolver** that returns the winning rule for a given context, and a
    `*_rule_changes` audit trail storing previous value, new value, changed_by, reason, effective
    date (§27.3 requires this explicitly for withdrawal rules; I'll apply the same to all four).

## 8. Integration driver pattern

One shape reused for payments, SMS, and couriers:

```php
interface PaymentGateway {
    public function initiate(PaymentIntent $intent): GatewayRedirect;
    public function handleCallback(Request $r): GatewayResult;
    public function verifyWebhook(Request $r): bool;
    public function verify(string $reference): GatewayResult;
    public function refund(Payment $p, Money $amount): GatewayResult;
    public function supports(PaymentFeature $f): bool;
}
```

- Registered in a `PaymentGatewayManager` keyed by slug; adding a gateway = one class + one config
  row + one seeder line. No core changes. Same for `SmsProviderManager`, `CourierProviderManager`.
- Credentials live in a table, **encrypted at rest**, with sandbox/live sets and an enable toggle.
- Every driver call is logged (request/response, secrets redacted per §42).
- Webhooks: signature verification → idempotency key → queue → respond fast. Never process inline.

## 9. Partner website integration

- Per-website API credentials (key + encrypted secret) with **permission scopes**, rotation, and
  revocation (§17.3).
- Auth via signed requests; rate limited per credential via Redis.
- Outbound (ERP → site): product/stock/price/category sync jobs, queued, retried with backoff,
  landing in a **failed sync queue** that an authorized user can retry manually (§17.2).
- Inbound (site → ERP): orders, customers, payments, returns — signature-verified, idempotent,
  duplicate-order-protected.
- **Hard isolation test:** one website's credentials must never read another's data. This gets an
  explicit test group.
- Connection health + last-sync timestamps surfaced in the ERP (§16.2).

## 10. Queues, scheduling, Redis

- Redis for cache, sessions, queues, rate limiting, temp tokens, distributed locks (§38).
- **Named queues by priority:** `payments` (critical) · `sync` · `notifications` · `sms` ·
  `reports` · `maintenance`. Separate workers so a slow report never delays a payment callback.
- Heavy work is queued, always (§39): SMS, email, reports, exports, backups, product sync, order
  sync, commission calc, referral processing, wallet processing, balance checks, reconciliation.
- **Scheduler must be multi-server safe** (§41): `onOneServer()` + `withoutOverlapping()` on every
  scheduled task, backed by the Redis lock store.
- **[DECIDE]** Laravel Horizon for queue monitoring — it is the obvious fit for §42's queue
  monitoring requirement.

## 11. Security

- KYC/private documents on a **non-public disk**, served only through an authorized controller
  issuing short-lived signed URLs; every view/download writes an access log row (§7.5).
- Gateway + API secrets encrypted with `encrypted` casts.
- Rate limiting on auth, payment init, webhooks, exports, backups.
- Logs scrub passwords, tokens, secrets, full payment credentials, unmasked PII (§42).
- ERP responses carry `X-Robots-Tag: noindex` via middleware (§34.2).
- Audit log is append-only and not editable by ordinary admins (§36.2) — same enforcement pattern
  as the ledger.

## 12. Frontend approach

- Inertia pages, server-driven. **Server-side pagination, filtering, sorting** everywhere (§39).
- One `DataTable` component covering §33.6 in full: search, filters, sort, pagination, column
  visibility, bulk actions, export, and empty/loading/error states. Built once, used ~50 times.
  On small screens it degrades to prioritized columns or cards, not a broken horizontal scroll.
- A **design system pass before feature UI**: tokens (color/spacing/type/radius), status color
  map, money formatting, page shell, form patterns. §33 explicitly bans a generic-template look,
  and the only way to avoid that is to decide the visual language up front rather than
  accumulating it screen by screen.
- Status is never conveyed by color alone (§33.9) — icon or text always accompanies it.
- Every route gets its five states (§33.10). This is in the definition of done, not a later pass.

## 13. Testing

Pest, with named groups so I can run a slice fast:

`onboarding` · `kyc` · `package` · `payment` · `wallet` · `ledger` · `commission` · `referral` ·
`withdrawal` · `catalog` · `product-restrictions` · `inventory` · `wholesale` · `dropshipping` ·
`website-api` · `orders` · `fulfillment` · `courier` · `settlement` · `reports` · `permissions` ·
`self-scope` · `security` · `concurrency`

Non-obvious ones I am committing to up front, because §43 names them:

- concurrent financial transactions (parallel wallet debits must not oversell the balance)
- overselling prevention under concurrent orders
- duplicate payment / duplicate order / duplicate reward prevention
- self-referral prevention and referral reversal
- unauthorized product creation rejection, at every layer
- cross-user data access via URL, API, export, and modified parameters

## 14. Environments

- Local: Docker (PostgreSQL 16 + Redis 7) via Sail, or your existing WSL services **[DECIDE]**.
- `.env.example` updated for pgsql + Redis cache/session/queue.
- CI: Pint → PHPStan → Pest → `npm run check` → `tsc --noEmit`, on Postgres + Redis services.
  The repo already has `composer ci:check`; I'll wire it to `.github/workflows`.
