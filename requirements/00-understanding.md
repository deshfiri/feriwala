# 00 — Understanding

## 1. What Feriwala is

Feriwala is a **centralized ERP for wholesale + dropshipping**, not a shop. Three surfaces:

```
┌────────────────────────┐   ┌──────────────────────────────┐   ┌─────────────────────────┐
│  PUBLIC FERIWALA SITE  │   │   AUTHENTICATED ERP PANEL    │   │  DEDICATED PARTNER SITES │
│  (marketing only)      │   │   (all real work happens)    │   │  (public storefronts)    │
│                        │   │                              │   │                          │
│ • CMS landing page     │   │ • Central product catalog    │   │ • Product listing        │
│ • Packages / FAQ       │──▶│ • Wholesale browse+cart      │◀─▶│ • Cart / checkout        │
│ • Register / Login     │   │ • Dropship product selection │API│ • Customer accounts      │
│                        │   │ • Orders, wallet, referrals  │+WH│ • Order tracking         │
│ NO product catalog     │   │ • KYC, packages, withdrawals │   │ • Own SEO + domain       │
│ NO cart / checkout     │   │ • Reports, admin, settings   │   │                          │
└────────────────────────┘   └──────────────────────────────┘   └─────────────────────────┘
```

**The single hardest constraint to remember:** the public Feriwala website must _never_ show
products, prices, a cart, or a checkout. Wholesale browsing and buying happen only behind login.
Products only appear publicly on a _partner's own_ dedicated website — which is a separate
storefront fed by the ERP over API/webhooks.

## 2. The one thing that makes this project unusual

**One account. One panel. No account types.**

There is no "customer account" vs "partner account", no upgrade path between them, no conversion
flow. A single `users` row moves through a status lifecycle, and once it reaches `Active` it can
do dropshipping, bulk wholesale purchasing, or both — from the same panel, with the same login,
the same KYC, and the same wallet.

The statuses in §5.3 (Registered → … → Active → … → Closed) are **states of one account**, not
different kinds of account. Everything that looks like a "type" is really either a _status_, a
_package entitlement_, or a _role/permission_ — and I need to keep those three concepts cleanly
separated in the code, or this system will rot fast.

## 3. The activation funnel (§5.1) — the spine of the product

```
Register → Verify mobile/email → Submit KYC → Select package
   → Pay (Registration Fee + Package Fee, ONE transaction, TWO ledger lines)
   → Payment verified → KYC approved → Admin approves → ACTIVE
```

Critical detail: the registration fee and the package fee are **paid together but stored,
invoiced, reported, and refunded separately**. That is an explicit requirement (§5.1, §9) and it
dictates the payment data model — one payment, many allocated components.

Until activation the user sees only: profile, KYC, package selection, payment, activation status,
support, and activation notifications. Everything else is locked. (§5.4)

## 4. The money core

This is the highest-risk area in the project and I am treating it as such.

- Every active account has a **wallet** and an **immutable financial ledger** (§23.2).
  Ledger rows are append-only. Corrections happen through adjustment / reversal / corrective
  entries, never edits or deletes.
- The wallet is not one number. It is a set of **balance buckets**: total deposited, required
  deposit, reserved, usable-for-services, available-for-withdrawal, pending, hold (§23, §24.2).
  Reserved minimum balance is protected from withdrawal.
- The admin can require a deposit and a minimum balance, configurable globally / per package /
  per user / per website / per domain / per hosting (§24.1). Falling below thresholds triggers a
  graded response: notify → grace period → restrict services → disable website → disable account
  → auto-restore on top-up.
- **COD is tracked separately from online payments** and has its own settlement + reconciliation
  cycle with couriers (§28).
- Commission is earned on dropshipping sales, is rule-driven with priority resolution (global <
  package < user < product < category < campaign), and **reverses on cancel/return/refund** (§22).
- Referral is **single-level only, unlimited width, explicitly not MLM** (§25). Both referrer and
  new user can be rewarded. Fraud prevention is a listed requirement, not an afterthought.
- Withdrawals have **default rules and per-user overrides**, where a valid user-specific rule
  overrides the corresponding default (§27.2, §27.3). Every override change is itself audited with
  before/after values.

Implication: money is never a float, never calculated client-side, and never written outside a
transaction with row locking. See [01-architecture.md](01-architecture.md).

## 5. Catalog and the hard product restriction

Only admins/authorized users create products (§11, §12). A regular user can **only select**
products from the central catalog and publish them to their own website. They cannot create
products, categories, brands, variations, or touch central SKU / stock / wholesale price.

§12 explicitly requires this be enforced at six layers: UI permissions, backend authorization,
API authorization, request validation, database rules where appropriate, and automated tests.
I will build a dedicated test group for exactly this.

Users _may_ be allowed website-specific selling prices, but only inside admin-configured
min/max/margin bounds with locked/editable field control (§15.1).

## 6. Inventory and overselling

One central stock pool feeds every partner website (§19.1). Orders reserve stock centrally;
completion decrements; cancellation releases; returns update after inspection. Transaction-safe
reservation preventing overselling is called out explicitly and is on the test list (§43).

## 7. Integrations that must be pluggable

The spec demands modular architecture so these can be extended without rewriting (§2):

| Category              | Required now                                     | Must accept more later        |
| --------------------- | ------------------------------------------------ | ----------------------------- |
| BD payment gateways   | EPS, SSLCommerz, SurjoPay, AmarPay, bKash, Nagad | yes                           |
| Intl payment gateways | Stripe, PayPal                                   | yes                           |
| SMS providers         | BulkSMSBD, Nova SMS, SSLCommerz SMS, Twilio      | yes                           |
| Couriers              | (none named)                                     | yes — architecture must allow |
| Websites              | Dedicated partner storefronts                    | yes                           |

Each of these gets a driver contract + a manager/registry + per-driver config with encrypted
credentials + sandbox/live toggles + enable/disable switches.

## 8. Non-functional requirements I must not treat as optional

- **PostgreSQL + Redis are mandatory** (§2, §37, §38). Redis carries cache, sessions, queues,
  rate limiting, temporary tokens, distributed locks, and duplicate-payment prevention.
- **Horizontally scalable, load-balancer ready, stateless app servers** (§40). No server-local
  sessions, no local-only persistent files.
- **Weekly automatic DB backups** + permission-gated manual backups (§35).
- **Audit logs** on every sensitive action, not editable by ordinary admins (§36.2).
- **KYC documents stored privately**, never on a public URL, every view/download recorded (§7.5).
- **ERP pages no-indexed**, no database IDs in public URLs, public URLs use human-readable
  slugs (§34.2, §34.3).
- **SaaS-style custom UI** that explicitly must _not_ look like a generic admin template or an
  AI-generated dashboard (§33). There is a written list of things to avoid: random gradients,
  glow, unnecessary glassmorphism, decorative clutter, overcrowded cards, vanity statistics.
  I read this as: restrained, dense, functional, opinionated. Real ERP, not a template.
- **Accessibility and responsive design are requirements**, not polish (§33.8, §33.9).
- Every important screen needs skeleton / empty / error / success / permission-denied states
  (§33.10).

## 9. Current state of this repository

Fresh **Laravel React starter kit**, essentially untouched beyond the app name. What exists:

| Area                | Present                                                                                | Notes                                        |
| ------------------- | -------------------------------------------------------------------------------------- | -------------------------------------------- |
| Laravel             | ✅ `^13.17`, PHP `^8.3`                                                                | good baseline                                |
| Inertia             | ✅ `inertiajs/inertia-laravel ^3.0` + `@inertiajs/react ^3.0`                          | matches spec                                 |
| React               | ✅ 19.2 + React Compiler babel plugin                                                  | matches spec                                 |
| Auth                | ✅ Fortify — login, register, reset, 2FA, passkeys, password confirm                   | strong head start on §6                      |
| Teams               | ⚠️ `Team`, `Membership`, `TeamInvitation`, `TeamPolicy`, `{current_team}` route prefix | **needs a decision — see open questions**    |
| UI kit              | ✅ Tailwind 4, Radix primitives, shadcn-style `components/ui`, sonner, lucide          | usable foundation                            |
| Tooling             | ✅ Pest 5, PHPStan/Larastan, Pint, Wayfinder, vite-plus                                | keep all of it                               |
| Database            | ❌ **SQLite** (`database/database.sqlite`)                                             | spec requires PostgreSQL                     |
| Cache/queue/session | ❌ all `database` driver                                                               | spec requires Redis                          |
| Domain code         | ❌ nothing                                                                             | `app/` has only starter-kit teams + settings |
| Pages               | ❌ welcome, dashboard, teams, auth, settings only                                      | 13 files total                               |

So: the auth layer, the tooling, and the design-system primitives are real assets. **Everything
that makes Feriwala _Feriwala_ is unwritten.** That is roughly 30 modules across 46 spec sections.

## 10. Gap summary — what has to be built

Nothing in the following list exists yet, in any form:

CMS/landing page · account status lifecycle · KYC (dynamic types, secure storage, review) ·
packages · combined fee checkout · payment gateways (8) · wallet · ledger · deposit &
minimum-balance engine · commission engine · referral engine · withdrawal engine (+ per-user
overrides) · COD settlement · reconciliation · central catalog · categories/brands/variations ·
product restriction enforcement · ERP wholesale browse/cart/checkout · dropship selection &
publishing · dedicated website provisioning · API + webhook integration layer · order management
(28 statuses + admin-defined, 6 sources) · inventory & reservation · fulfillment · courier ·
dashboard notifications · SMS (4 providers) · 56 admin reports + 21 self-scoped reports ·
RBAC (20 roles, 20 permission actions) · SEO · backups · audit logs · monitoring · the entire ERP
UI shell · **and a full customer-facing storefront application** (§16.1, 20 features).

## 11. How I intend to work

Bottom-up on infrastructure, then vertical slices per domain, in dependency order. Concretely:
foundation → identity/onboarding → money core → catalog/inventory → wholesale → dropship/websites
→ OMS/fulfillment/courier → earnings/payouts → notifications → reports → CMS/SEO → hardening →
final test sweep. Full sequencing and exit criteria in [03-roadmap.md](03-roadmap.md).
