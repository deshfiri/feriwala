# 03 — Roadmap

13 phases in dependency order. Each phase ends with working, tested, reviewable software — not a
half-wired layer. The detailed task list lives in [TODO.md](TODO.md); this document explains
_why_ the order is what it is and what "done" means per phase.

## Sequencing logic

```
P0 Foundation ──┬─▶ P1 Identity & Onboarding ──┬─▶ P4 Wholesale ───┐
                │            │                  │                   │
                ├─▶ P2 Money Core ─────────────┤                   ├─▶ P6 OMS ──▶ P7 Earnings
                │            │                  │                   │        │
                ├─▶ P3 Catalog & Inventory ────┴─▶ P5 Dropship ────┘        │
                │                                    & Websites              │
                ├─▶ P8 Notifications (needed from P1 onward, built early)    │
                │                                                            │
                └─▶ P10 CMS & SEO (independent, can run in parallel) ────────┤
                                                                             │
                                          P9 Reports ◀───────────────────────┘
                                             │
                                          P11 Hardening ──▶ P12 Final QA
```

Two things break strict linearity and I am handling them deliberately:

1. **SMS is needed at registration** (mobile verification, §5.1) but is a full module (§30). I
   build a minimal one-provider slice in P1 and complete the module in P8.
2. **Payments are needed for activation** (§5.1) but the gateway module is large (§26). I build
   the payment abstraction plus one gateway in P1/P2, and add the remaining seven in P2.

## Phase 0 — Foundation & Infrastructure

_Why first:_ PostgreSQL, Redis, money handling, RBAC, audit logging, and the design system are
load-bearing. Retrofitting any of them later means rewriting everything built on top.

**Done when:** app runs on PostgreSQL with Redis cache/session/queue; `Money` value object and
cast in use; roles/permissions installed and seeded; audit log records a test action; settings
registry works; queue workers and a multi-server-safe scheduler run; CI is green; the ERP shell
(sidebar, header, notification slot, breadcrumbs, account menu) and the `DataTable` component
exist with all five UI states; design tokens are decided and documented.

## Phase 1 — Identity, KYC, Packages, Activation

_Why here:_ this is the funnel every other feature sits behind. No account can be `Active` without
it, and nothing downstream is testable until accounts can activate.

**Done when:** a user can register (with referral code capture), verify mobile + email, submit
dynamic KYC with privately-stored documents, select from N admin-created packages, see a checkout
that itemises registration fee and package fee separately, pay through one gateway, and be
activated after payment verification + KYC approval + admin approval — with the onboarding stepper
showing the correct step at every point, and pre-activation access correctly restricted to the
seven allowed areas (§5.4).

## Phase 2 — Money Core

_Why here:_ wallet, ledger, and deposit rules must exist before commissions, withdrawals, COD, or
service charges can be recorded correctly. Building any of those first would mean fabricating a
temporary money model and migrating it later.

**Done when:** every active account has a wallet with all balance buckets; the ledger is provably
immutable (test asserts update/delete fail); deposit and minimum-balance rules resolve by scope
with precedence; low-balance grading (notify → grace → restrict → disable → auto-restore) works;
all eight payment gateways are implemented behind the driver contract with sandbox config; and
concurrency tests prove no double-spend and no duplicate payment.

## Phase 3 — Central Catalog & Inventory

_Why here:_ products are the subject of everything in P4–P7, and the §12 restrictions must be in
place before any user-facing product surface exists.

**Done when:** admins can manage categories, brands, attributes, variations, media, tiered pricing,
and eligibility; every product status works; central stock supports available/reserved/processing/
sold/returned/damaged with movement history; reservations are transaction-safe under concurrent
load; and the `product-restrictions` test group proves a regular user is rejected at UI, controller,
policy, API, and validation layers.

## Phase 4 — ERP Wholesale Browsing, Cart & Checkout

_Why here:_ the simpler of the two business methods and the first real revenue path. It exercises
catalog + inventory + payments + orders end to end on a smaller surface than dropshipping.

**Done when:** an active user can search/filter the catalog inside the ERP, see tiered wholesale
pricing, add to cart with min/max/stock validation, check out with addresses/delivery/tax/coupon,
pay, and receive an invoice — with a wholesale order created in the unified OMS.

## Phase 5 — Dropshipping Selection & Dedicated Websites

_Why here:_ the largest and most integration-heavy area. Needs catalog, inventory, packages,
wallet (setup charges + deposits), and orders already working.

⚠ **This is the biggest phase by a wide margin — 50 tasks**, because §16.1 requires a full
customer-facing storefront application on top of the selection, provisioning, and API work.
Depending on the answer to Q11 it may be worth splitting into two phases. I would rather flag
that now than let it quietly become the phase that never ends.

**Done when:** a user can select eligible products within their package publish limit, publish them
to their website with permitted per-site pricing inside admin bounds, and the website provisioning
lifecycle (setup → deposit → development → API connection → active → grace → suspended) runs; the
API/webhook layer syncs products, stock, prices, categories outward and orders, customers, payments
inward — signature-verified, idempotent, retried, with a failed-sync queue and manual retry; and
the isolation test proves one website's credentials cannot reach another's data.

## Phase 6 — Order Management, Fulfillment, Courier

_Why here:_ orders now arrive from all sources (ERP wholesale, website, manual, API, admin), so
the unified OMS can be completed against real inputs rather than fixtures.

**Done when:** all order statuses and admin-created custom statuses work with a guarded transition
map and full history; user and admin order dashboards are complete and correctly scoped; invoices,
packing slips, shipping labels generate; fulfillment (warehouse, pick, pack, charges, staff) works;
courier providers are pluggable with booking, tracking, delivery status, COD amounts, failures, and
reconciliation.

## Phase 7 — Commission, Referral, Withdrawal, COD Settlement

_Why here:_ all of these compute from delivered/completed orders and write to the wallet. They are
only correct once P2 and P6 are correct.

**Done when:** commission rules resolve by priority across six scopes with effective dating, accrue
on eligible orders, and reverse on cancel/return/refund; the single-level referral program pays both
parties on qualification with holding periods, and blocks self-referral, duplicate rewards, and
rewards from failed/cancelled/refunded/reversed payments; withdrawals honour default rules with
per-user overrides (with change auditing) through the full approval → paid lifecycle; COD collections
settle and reconcile against courier statements, with mismatches raising alerts.

## Phase 8 — Notifications & SMS

_Why here:_ the events it reacts to now all exist. Building it earlier would mean stubbing 32
events that do not yet fire.

**Done when:** the dashboard notification centre covers every event in §29; SMS is queue-delivered
through all four providers behind one contract, with Bangla and English templates, variables,
delivery status, failure logs, retries, cost/balance tracking where supported, and enable/disable
control at global / provider / event / account-status / package / user levels.

## Phase 9 — Reports & Analytics

_Why here:_ reports read from every other domain. They are cheap to build once the data is right
and expensive to keep fixing if built early.

**Done when:** all 56 administrative reports and 21 self-scoped user reports exist with date,
status, product, category, package, user, website, and payment-method filters, search, sorting,
server-side pagination, summary totals, CSV/Excel/PDF export, printable views, and permission-based
visibility — plus the test group proving a user cannot reach another user's report through URL,
API, export, or modified parameters.

## Phase 10 — CMS, Landing Page, SEO

_Why here:_ fully independent of the ERP domains, so it can run in parallel with P4–P9 if I have
slack. Placed here so it lands before launch hardening.

**Done when:** admins can create/edit/reorder/enable/schedule landing sections with draft+published
states, revisions, preview, media, colors, and device visibility; all public pages exist; SEO covers
friendly URLs, meta, canonical, Open Graph, organization and FAQ schema, sitemap, robots, alt text,
301 redirects; ERP pages are no-indexed; partner websites have product/category slugs, product,
organization and breadcrumb schema, sitemaps, and custom 404s.

## Phase 11 — Security, Performance, Scalability, Backup, Monitoring

_Why here:_ hardening is measured against the finished system, not a partial one.

**Done when:** weekly automatic encrypted backups run with retention, failure alerts, and documented
restore procedures; permission-gated manual backups require password/2FA confirmation and are rate
limited; audit logging covers every item in §36.2 and is not editable by ordinary admins; the
security checklist in §36 is verified item by item; performance targets are met with query
optimization, eager loading, Redis caching, queued heavy work, image optimization, code splitting,
compression, and cache headers; the app is verified stateless and horizontally scalable with
health-check endpoints and worker monitoring; and all logs are scrubbed of secrets and PII.

## Phase 12 — Final QA, Accessibility, Documentation, Launch Readiness

**Done when:** every item in §43's testing list has passing coverage; accessibility is verified
(contrast, keyboard navigation, focus states, labels, form errors, semantic headings, alt text,
non-colour-only status, touch targets); responsive behaviour is verified on desktop/laptop/tablet/
mobile; every important screen has all five UI states; §44 and §45 are walked through item by item
and signed off; deployment, runbook, and recovery documentation is complete.

## Estimation posture

I am deliberately not putting day counts in this document. The scope is ~30 modules and the honest
answer is that estimates would be fiction until Phase 0 and Phase 1 are behind us and we have a
measured velocity. After Phase 1 I will come back and estimate P2–P12 with real data.

What I will commit to now: **phases ship in order, each one reviewable, and I report progress
against the [TODO.md](TODO.md) checklist rather than a percentage.**
