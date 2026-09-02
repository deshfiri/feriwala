# 04 — Approved Decisions

**Status: APPROVED — 2026-09-01.** This is the authoritative implementation direction. It
supersedes my recommendations in [99-open-questions.md](99-open-questions.md), which is now
historical. Nothing here changes without explicit written instruction.

Items marked 🔒 are **architecture-locked**: per your final direction, any future change to them
must be raised for approval _before_ implementation, because they affect the whole system.

---

## D1 — Account & staff structure (was Q1)

Repurpose the starter kit's Teams as **staff management under one Account**.

- Business layer renames Team → **Account / Organization**.
- The registered, activated Feriwala user is the **Account Owner**. 🔒
- Staff are sub-users under the same Account, with **their own login credentials**.
- Staff are **not** separate Feriwala business accounts. 🔒
- Package **Staff Limit** caps how many staff an owner may add.
- Staff access is governed by roles and permissions.
- **Remove `{current_team}` from authenticated ERP URLs**; resolve account context from session.
- A solo user with no staff must never see any trace of the team concept.

## D2 — RBAC (was Q2)

**`spatie/laravel-permission`.** No hand-rolled RBAC.

- Two scopes kept strictly apart: **platform roles** (admin side) and **account staff roles**.
- A staff role in one account must never affect another account.
- Permission caching on; cache cleared whenever roles or permissions change.
- Sensitive actions still require the §32.2 confirmation/approval escalation on top of permissions.

## D3 — Queue monitoring (was Q3)

**Laravel Horizon**, gated behind the `system.monitoring` permission, never publicly reachable.
Monitor failures, retries, throughput, worker status. In production, add network-level restriction
on top of auth/authorization.

## D4 — Currency (was Q4)

**BDT base and operational currency for v1.**

- Every financial table carries a **`currency_code`** column, default `BDT`.
- Fixed-precision decimal or integer minor units. **Never floating point.** 🔒
- Schema and ledger stay multi-currency-ready; no exchange-rate accounting in v1.
- Stripe and PayPal implement the same driver contract but may stay **disabled** until valid
  merchant accounts and supported currency flows exist.
- If an international gateway ever settles in another currency, record **original currency,
  original amount, and settlement information** — never silently rewrite the BDT base ledger. 🔒

## D5 — Local environment (was Q5) — **AMENDED 2026-09-01**

**Native services in WSL Ubuntu. No Docker.**

| Component  | Version       | Source                                        |
| ---------- | ------------- | --------------------------------------------- |
| PostgreSQL | **18.6**      | Ubuntu 26.04 apt                              |
| Redis      | **8.0.5**     | Ubuntu 26.04 apt                              |
| PHP        | 8.5.4         | already installed                             |
| pgAdmin 4  | not installed | optional; a Windows client works equally well |

Setup is scripted at [scripts/setup-dev-environment.sh](../scripts/setup-dev-environment.sh).

### Why this supersedes the original decision

The original D5 specified Docker/Sail with PostgreSQL 16 and Redis 7. Two findings changed it:

1. **Redis 7 does not exist for Ubuntu 26.04 "resolute".** Neither Ubuntu's apt (8.0.5) nor
   Redis's own repository (8.8+) packages it. The Redis 7 pin was unsatisfiable natively.
2. **You chose native over Docker** on 2026-09-01: "no docker need, build everything in this wsl".

**Redis is therefore pinned to 8, not 7**, across development, CI, and `compose.yaml`. Redis 8 is
backward compatible with everything Feriwala uses it for — cache, sessions, queues, rate limiting,
temporary tokens, and locks. A pin the development machine cannot satisfy is not a pin; it is a
divergence waiting to cause a bug that only appears in one environment.

**PostgreSQL moved from 16 to 18** (2026-09-01, second amendment). The environment was installed
from Ubuntu's apt rather than the PGDG repository, giving **18.6**. Rather than leave development
on 18 while `compose.yaml` and CI claimed 16 — the exact divergence that makes a bug appear in one
environment only — the pin moved to 18 everywhere. Nothing in the schema depends on a 16-only
behaviour; the audit-log triggers, `jsonb` columns, and plpgsql functions were all verified working
on 18.6.

### Tests use a schema, not a database

Tests run against a **`testing` schema inside the `feriwala` database**, selected by
`DB_SEARCH_PATH` in `phpunit.xml`, rather than a separate `testing` database.

The application role owns its database and can create schemas, but has no `CREATEDB` privilege.
Requiring one would mean every developer and every CI runner needing elevated database rights just
to run the suite. Schema isolation gives the same separation — `RefreshDatabase` cannot reach
development data — with none of that. Verified: 81 feature tests pass against it.

### What did not change

`compose.yaml` is kept and updated to match (PostgreSQL 18, Redis 8). It is no longer the primary
development path, but it remains the reference for production parity and for any machine that does
use containers. CI runs the same pinned versions.

**Everything in D10 still holds.** Running services natively is a local convenience, not a licence
to change the architecture: sessions, cache, and queues remain on Redis, the application stays
stateless, and no local-only persistent files are introduced.

## D6 — Brand & visual direction (was Q6)

Existing Feriwala logo and brand identity as the foundation.

- Neutral grey / off-white SaaS surface; **Feriwala orange** as the single primary accent;
  near-black for primary text and navigation; restrained colour overall.
- Clean, professional, data-focused. Quality references: Stripe Dashboard, Shopify Admin.
- **Custom Feriwala design system** — must not read as a generic admin template or AI-generated
  dashboard.
- Compact and data-dense on desktop; comfortable and simplified on mobile.
- **English default, with a Bangla/English toggle.** SMS templates in both languages.

**Gate:** before building the full screen set, deliver a visual direction sample containing
sidebar · header · dashboard cards · data table · form · modal or drawer · wallet summary ·
order details · mobile view. The approved sample becomes the design system foundation.

## D7 — Payment gateways (was Q7)

**SSLCommerz first**, behind a driver/adapter contract that admits EPS, SurjoPay, AmarPay, bKash,
Nagad, Stripe, and PayPal without touching core payment logic.

SSLCommerz scope: combined registration + package payment · callback · IPN/webhook verification ·
payment verification · refund where supported · transaction logging · reconciliation · duplicate
payment prevention.

**No hardcoded credentials, ever.** Sandbox credentials supplied when available.

## D8 — Couriers (was Q8)

Driver contract + **manual courier workflow first**, then **Steadfast**, then **Pathao**. RedX,
eCourier, Paperfly, Sundarban later through the same interface.

Manual workflow must capture: courier name · tracking number · delivery charge · COD amount ·
order status · delivery status · return charge · notes · manual reconciliation.

Provider API work starts when valid merchant credentials and documentation exist.

## D9 — Domain & hosting (was Q9)

**Manual provisioning in v1.** The ERP tracks domain/hosting information, calculates and deducts
charges, stores purchase and renewal dates, sends expiry reminders, records renewal status, and
suspends or restores websites per configured rules. An admin performs the actual registration and
records the result. Registrar/host APIs must be addable later without redesigning the schema.

## D10 — Deployment (was Q10)

Initial target is a **single well-configured VPS**, built horizontally scalable and
load-balancer-ready from day one: stateless app servers · Redis sessions, cache, queues ·
shared or **S3-compatible object storage** · environment-based configuration · separate queue
workers · health-check endpoints · reverse-proxy and load-balancer readiness · no local-only
persistent files.

## D11 — Storefront architecture (was Q11) 🔒

**Separate deployable storefront application consuming the ERP API.**

- ERP stays private and authenticated; storefront is customer-facing.
- Communication only over secure APIs and webhooks.
- Product, stock, price, availability flow outward from the ERP; orders, customers, payments,
  returns, refunds sync back.
- Storefronts get **no unrestricted database or ERP access**, only scoped API credentials.
- Storefronts scale independently.
- A storefront compromise must not reach KYC documents, wallets, or the financial ledger. 🔒
- One storefront codebase serving many partner websites via multi-tenant / configuration-based
  architecture.

---

# Specification ambiguities — resolved

## D12 — Dropshipping money flow (was A1) 🔒 **the most load-bearing decision in the project**

**Feriwala is the merchant of record for dropshipping orders.**

Approved flow:

1. Customer orders on a partner's dedicated website.
2. Customer pays through a **Feriwala-controlled gateway**, or selects COD.
3. Order syncs to the ERP.
4. Feriwala controls payment verification, inventory, fulfillment, courier, COD reconciliation.
5. Once eligible, the user's **earning** is calculated.
6. Earning is credited to the user's wallet.
7. User withdraws from the ERP.

**Partner websites must not use the partner's own gateway credentials in v1.** 🔒

Earning may be configured as: fixed commission · percentage commission · product-specific ·
package-specific · **retail margin derived from the permitted selling price** · campaign-specific.

Even when the earning is a retail margin, the **customer payment is still collected and settled
through Feriwala**, and the calculated earning is then credited to the user's wallet. COD likewise
flows to Feriwala for courier reconciliation before eligible earnings release.

_Design consequence I am taking from this:_ "retail margin" becomes a **calculation base on the
commission rule**, not a separate money path. One earnings engine, several calculation bases.

## D13 — Wholesale fulfillment (was A2)

Wholesale uses the **same** central warehouse, fulfillment, and courier pipeline, distinguished by
order source `erp_wholesale`. It supports stock reservation, warehouse assignment, picking,
packing, courier assignment, tracking, delivery, return, refund, and financial reconciliation.

The admin may apply **different fulfillment rules, charges, or approval requirements by order
source**.

## D14 — Joining reward (was A3)

**The joining reward and the new-user referral reward are the same reward.** No independent
joining bonus for every activated user.

Paid to the new user only when: a valid referral exists · qualification rules are met ·
registration complete · KYC approved · package selected and paid · account activated.

Ledger transaction types: **`referee_reward_credit`** and **`referrer_reward_credit`**.

A standalone promotional signup bonus may arrive later as a separate campaign feature; it is not
in current core scope.

## D15 — Warehouse selection (was A4)

**Admin-configurable warehouse priority with manual override.** Default: assigned default
warehouse → verify available stock → fall through configured priority → authorized user may
override → **every assignment and reassignment recorded in the audit log**. Architecture stays
ready for nearest-warehouse or cost-based selection later.

## D16 — Downgrade with excess published products (was A5)

**Block the downgrade** until the user is within the lower package's publishing limit. Show
current published count, new limit, and how many must be unpublished; let **the user choose which**
to unpublish. Never auto-remove products without confirmation. An authorized user may override only
with a recorded reason and the right permission.

## D17 — Fee refundability (was A6)

- Registration fee: **non-refundable by default**.
- Package fee: **non-refundable after activation** by default; may be refundable **before**
  activation with admin approval.
- Wallet deposits follow their own configured refundability rules (§24.4).
- All refundability rules admin-configurable.
- Every refund decision recorded in the **financial ledger and audit log**.

Public refund policy to be finalised before production launch.

## D18 — Data after account or website closure (was A7)

No immediate deletion.

- Set account/website to **Closed or read-only**; stop new orders; disable public storefront
  access where required.
- **Preserve** orders, payments, wallet entries, audit logs, financial records.
- Let the user **export permitted self-scoped data before final closure**.
- Apply an **admin-configurable retention period**; after it, personal data may be **anonymised**
  per the approved privacy and legal policy.
- Financial and audit records remain for the legally required period.
- **Permanent deletion requires explicit administrative approval** and must never remove legally
  required financial or audit records. 🔒

## D19 — Tax model (was A8)

Build a **configurable tax/VAT engine**. v1 defaults: BDT base · **tax-exclusive by default** ·
admin-configurable rates · product-specific, category-specific, and fee-specific rules ·
tax-exempt configuration · effective dates · **tax breakdown on invoices** · separate tax ledger
and report fields. Support both inclusive and exclusive pricing; exclusive is the default.

**No statutory rate is hardcoded on assumption.** Final Bangladesh VAT rates, Mushak requirements,
and invoice format to be confirmed with your accountant/tax consultant before production launch.

## D20 — Notification preferences (was A9)

Users control **optional marketing and informational** notifications only.

Users **cannot disable** mandatory operational notifications: security alerts · login alerts ·
payment success/failure · KYC status · account status · package expiry · wallet balance warnings ·
withdrawal status · order-critical · domain and hosting expiry · website suspension · legal or
policy notices.

The admin may control SMS globally and per event, but **mandatory dashboard notifications remain
available regardless**.

## D21 — Permitted resale channels (was A10)

**No technical enforcement in v1.** Handled via terms and conditions, product restrictions, brand
restrictions, account agreements, and administrative action on reported misuse. The ERP may record
an **optional intended resale channel** for reporting only — it does not track or block where
independently purchased wholesale stock is sold.

---

# Final direction

- Proceed **phase by phase** using the decisions above.
- The landing page **may ship early** for marketing.
- These must be **finalised before dependent modules are built**: ERP architecture · financial
  ledger · product catalog · account activation flow · **storefront API contracts**.

> _Roadmap consequence:_ storefront API contract design moves **from Phase 5 up into Phase 0**, so
> the contract is frozen before anything depends on it. Recorded as task P0-53.

## Change control 🔒

Any future change affecting **merchant of record · financial ledger · payment direction ·
storefront separation · single account structure · product ownership · wallet settlement ·
referral rewards** must be raised for approval **before** implementation.

I will treat a request touching any of these as a stop-and-confirm point rather than an
instruction to proceed.
