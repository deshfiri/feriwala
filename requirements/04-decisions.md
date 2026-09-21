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

## D5 — Local environment (was Q5) — **AMENDED 2026-09-01, confirmed 2026-09-03**

**Native services in WSL Ubuntu. No Docker.**

Confirmed on 2026-09-03 as settled rather than provisional: PHP, PostgreSQL, Redis and Node all run
natively in WSL, and the application is served by `php artisan serve`. **Docker and Sail must not
become a requirement for local development** — not for a service, not for a test run, not for a
one-off tool. Production architecture stays container-ready and load-balancer-ready (D10); that is a
deployment concern and does not reach back into how the machine in front of you runs.

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

`compose.yaml` is kept and updated to match (PostgreSQL 18, Redis 8). It is **not** a development
path and nothing may come to depend on it; it remains the reference for production parity and for
any machine that does use containers. CI runs the same pinned versions.

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

## D22 — Activation review outcomes and readiness (2026-09-03)

Refinements to the activation gate, approved after the first implementation of the approval queue.

### No generic Reject

Three outcomes, and no fourth. §5.3 gives `ApprovalPending` exactly these moves that a reviewer may
take, and each means something different to the applicant:

| Outcome                      | Target status             | Requires                                              | Permission        |
| ---------------------------- | ------------------------- | ----------------------------------------------------- | ----------------- |
| **Activate**                 | `Active`                  | every §5.1 condition, re-checked under a row lock     | `account.approve` |
| **Request KYC resubmission** | `KycResubmissionRequired` | an internal reason **and** applicant-visible feedback | `account.approve` |
| **Suspend**                  | `Suspended`               | an internal reason                                    | `account.reject`  |

A generic `Reject` status must not be introduced at this stage. Each outcome is its own domain
action — `ActivateAccount`, `RequestKycResubmission`, `SuspendAccount` — with its own validation,
notification, audit entry and permission check. A single `DeclineActivation` taking an outcome
parameter was built first and **removed**: it let two decisions with different consequences share one
permission, and a route named `decline` invites a fourth outcome nobody designed.

**Suspension is not permanent denial.** It stays reversible. Permanent closure and final denial
belong to the closure and retention workflow (D18), which carries its own retention rules — and
suspension must never become a quiet substitute for it.

### ApprovalPending is canonical

`EvaluateActivationReadiness` is the orchestration that keeps the status honest. It runs whenever any
activation requirement moves — KYC approved or withdrawn, activation payment settled, refunded or
reversed, package selected or changed — and, under a per-account lock:

- moves a fully-qualified account to `ApprovalPending` and stamps `approval_pending_at`;
- clears that stamp when a requirement is reversed, so the account leaves the queue;
- does nothing when the account is already in the right place, so a repeated webhook does not
  produce repeated status changes.

`approval_pending_at` exists because "oldest first" has to mean _waiting longest for us_. Ordering by
registration would put someone who signed up in March above someone who completed everything last
week — inverting the queue exactly where it matters.

The queue reads that state. Its condition-based branch is a **compatibility net** for accounts that
qualified before the orchestration existed, or whose readiness event was lost; it is expected to
match nothing in steady state. `ActivationRequirements` remains the authority, and `ActivateAccount`
re-checks every requirement inside its transaction before activating — the queue is a view, the
action is the decision.

### Concurrency

Every decision runs inside one transaction with `lockForUpdate` on the account, and the status
machine is the arbiter. Two reviewers deciding at once resolve to one winner; the loser writes
nothing — no status change, no history row, no audit entry, no subscription change.

## D23 — User identity vs Business Account (2026-09-03) 🔒

**Approved, not yet implemented.** Amends D1. Supersedes the single-status model on `users`.

The problem it fixes: `users.status` was answering two different questions at once — "may this
person sign in" and "may this business trade". So a Feriwala staff member had to complete commercial
KYC, choose a package and pay an activation fee before they could open the admin panel, and an
invited staff member would have had to do the same to join someone else's workspace.

### The two boundaries

|                                                 | Governs                                            | Gates                                                                |
| ----------------------------------------------- | -------------------------------------------------- | -------------------------------------------------------------------- |
| `User.identity_status`                          | login-level suspension, security blocking, closure | **everything**, administration included                              |
| `BusinessAccount.status` (the 22 §5.3 statuses) | the commercial lifecycle                           | dropshipping, wholesale, wallet, orders, websites — the business ERP |
| Platform permissions                            | what a staff member may administer                 | admin routes                                                         |

### Rules

- `User` is a human login identity. `BusinessAccount` is the commercial workspace.
- A user may **own at most one** business account; the database enforces it.
- An invited staff user is a **member** of the owner's account and completes no KYC, no package
  selection and no activation payment of their own.
- A platform staff user may reach permitted admin routes **without owning a business account**.
- A commercially suspended account loses business ERP access, **and so do its members**.
- A globally suspended user loses everything, admin included.
- One person who is both an owner and platform staff keeps admin access when their business is
  suspended — unless their identity is suspended too.

A broad `admin.*` bypass is explicitly **not** an acceptable substitute: it would leave a suspended
staff member holding the panel.

### Naming

`BusinessAccount` internally, however the UI labels it. `Account` alone reads as "the thing I log
into", which is the confusion the split exists to end.

## D24 — Multi-level referral commission (2026-09-19) 🔒

**Approved in writing by the Project Owner on 2026-09-19** as the change-control approval that §45
and the rule below require. **Supersedes** every statement that the referral system is single-level
or not MLM: §25's opening, §25.1's "no second-level, third-level or downline commission", the
"multi-level commission" item of §25.5, §44's "will remain Single-Level and will not be MLM", and
§45's "Direct Single-Level Referral System". Everything else in §25 stands, and **D14 stands**: the
joining reward is the new user's referral reward, never an independent bonus.

### The hierarchy

- Referral is a relation between **business accounts** (D23). Each account has **at most one direct
  referrer**, captured at registration from the owner's referral code. No separate MLM account and
  no second account system: the existing account is the node.
- No self-referral, no cycle, no account as its own ancestor — refused in the application **and**
  by the database, which serialises every hierarchy write and walks the chain before accepting it.
- A referrer is never changed silently. Before any qualifying event or commission it may be
  attached or changed by an authorised person, with a reason, audited; after one, never.

### Configuration

- A global switch, **off by default**, and **plan versions** that are opened and closed, never
  edited: a plan version names an optional package (package-specific beats the global default),
  its effective dates, the triggering event, the commission base, the joining reward, a holding
  period, the account states that qualify, a **maximum payable depth**, and a rule for **every
  level up to it**. Depth and rewards are data, not code; changing either is a new version.
- Each level is **fixed** (minor units) or **percentage** (basis points of the commission base),
  may carry a cap, may be switched off, and may require the beneficiary to hold one of a set of
  packages or a minimum number of active direct referrals.

### Calculation

- **Trigger (initial):** successful **account activation after the verified combined activation
  payment** — the §25.2 qualification path ends there. The event is recorded once per account ever,
  so a retry, a duplicate callback or a second activation cannot pay again. The design keeps the
  trigger an enum so order-based commissions can be added later.
- **Commission base:** declared on the plan version — the package fee, the registration fee, or both
  — taken from the activation payment's own allocations, net of discount and excluding tax and
  deposits.
- Ancestors are resolved from level 1 to the plan's depth. **Each level is decided on its own**: an
  ancestor who is missing, suspended, unqualified or on a switched-off level is recorded as skipped
  with the reason, and the ancestors above it **keep their own level** — nothing shifts.
- Integer arithmetic only. A percentage reward is `floor(base × basis points ÷ 10 000)`: rounding is
  always down to the poisha, so the platform never pays a fraction it did not have. A level never
  exceeds its cap or the base, and the whole chain, joining reward included, never exceeds the base;
  a level that would is reduced to what remains and marked capped.

### Money

- Every calculated reward is a `referral_commissions` row written **in the activation's own
  transaction** — the outbox — holding the beneficiary, source account, event, level, the plan
  version and a **snapshot of the level rule**, the base, the rate or fixed amount, currency and
  amount. It is paid to the wallet through `WalletService` with an idempotency key of its own, when
  its holding period has passed and the beneficiary still qualifies to be paid.
- Reversal is only ever a compensating `referral_reward_reversal` entry: on a settled refund of the
  qualifying payment, or by an authorised person with a reason. A reward not yet paid is cancelled
  with no ledger entry. A reversal the wallet cannot cover is recorded as owed and retried, never
  taken into a negative balance.
- Level rewards post as `referral_reward_credit`; the new user's joining reward as
  `joining_reward_credit`.

### Visibility

- Staff: separate permissions to **view** the configuration (`referral.view_settings`), **edit** it
  (`referral.manage_settings`), view platform-wide referral and commission records
  (`referral.view`), and reverse (`referral.reverse_transaction`).
- An account sees **only its own**: its code and link, its **direct** referrals, and its own
  earnings with level, status and date. No downline beyond level 1, no other account's KYC, wallet
  or private data.

### Compliance note

Multi-level commission paid from joining or package fees is regulated in many jurisdictions and
licensed in Bangladesh. The system ships with MLM commission **switched off**; switching it on, and
the plan it runs, are the operator's decision to take with legal advice.

---

# Final direction

- Proceed **phase by phase** using the decisions above.
- The landing page **may ship early** for marketing.
- These must be **finalised before dependent modules are built**: ERP architecture · financial
  ledger · product catalog · account activation flow · **storefront API contracts**.

> _Roadmap consequence:_ storefront API contract design moves **from Phase 5 up into Phase 0**, so
> the contract is frozen before anything depends on it. Recorded as task P0-53.

## D25 — Separate Supplier Account domain (2026-09-21) 🔒

**Approved and instructed directly by the Project Owner on 2026-09-21.** This is the change-control
approval D1/D23's "single account structure" lock requires, given in the same message as the
implementation instruction. It **supersedes** every statement that all platform identities share one
account domain: requirements.txt §5 ("Single Account structure... every user will register... the
same Account"), §5's "There will be no separate: Customer Account / Partner Account..." list, and
§45's listing of "Single Account System" as unchangeable **as applied to Suppliers** — that
protection continues to mean exactly what it always meant for the Client/Partner domain, and D1/D23
are otherwise untouched. requirements.txt carries an inline amendment marker at both spots rather
than being silently rewritten.

### The two domains

Feriwala now has **two account domains that never merge and never convert into one another**:

1. **Client/Partner**, exactly as D1 and D23 describe it: one `User` identity, at most one owned
   `BusinessAccount`, staff as members of it, platform roles for Feriwala staff.
2. **Supplier**, wholly new and wholly separate: its own identity model, its own authentication
   guard and session boundary, its own KYC and approval lifecycle, its own portal.

A Supplier is **not** a role, a permission, or a capability reachable from a Client/Partner account,
and a Client/Partner identity is not reachable from a Supplier one. There is no shared login, no
account-type flag that switches a `User` into a Supplier, and no conversion path. Someone who is both
a business owner and runs a supplying business holds two entirely separate logins, exactly as two
unrelated people would.

### Authentication boundary — what "separate" means in practice

A new Eloquent model, `App\Domain\Supplier\Models\Supplier`, is authenticated through a **second
Laravel guard** (`supplier`, provider `suppliers`) rather than by extending `User` or by adding a
guard column to the existing `users` table. This is the standard multi-guard mechanism Laravel ships
with — not a hand-rolled parallel session system — chosen because it gives every one of the separate
concerns the batch asks for (registration, login/logout, password reset, email/mobile verification,
session) without inventing new primitives for problems Laravel already solves:

- **Separate identity**: a distinct model, distinct table, distinct primary key space. A Supplier's
  `id` and a `User`'s `id` are never comparable and nothing casts between them.
- **Separate authentication**: `Auth::guard('supplier')`, a distinct Eloquent provider, a distinct
  password-reset broker and token table. `php artisan make:auth`-style controllers are **not**
  reused from Fortify, which is bound to the `users` provider; Supplier auth is hand-rolled against
  the guard directly, in `App\Http\Controllers\Supplier\Auth`.
- **Separate authorization boundary**: every Supplier route sits behind `auth:supplier` and every
  Client/Partner or staff route behind `auth` (unchanged). A request authenticated on one guard is
  simply not authenticated on the other — `Auth::guard('web')->check()` is false for a Supplier
  session and `Auth::guard('supplier')->check()` is false for a Client/Partner session — and this is
  asserted by tests that hit the other guard's routes with each kind of session and require a
  redirect or 403, never a 200.
- **Session**: both guards share Laravel's normal session mechanism (Redis-backed, D5), which
  namespaces each guard's login under its own key inside the session array — this is Laravel's
  documented multi-auth behaviour, not a gap. A browser *could* therefore hold both a Client/Partner
  login and a Supplier login in one session at once, exactly as it could hold two logins in two
  different browser profiles. What the requirement — "a Supplier session must never gain access to
  Client/Partner, Partner Website or staff resources" — actually rules out is **authorization**, and
  that is enforced at the route/middleware boundary regardless of what else the session holds. A
  fully separate cookie name was considered and rejected for this batch as unnecessary complexity
  that does not change what is actually being guaranteed; if the Project Owner wants literal
  cookie-level separation later, it is a small, isolated follow-up (a dedicated session driver
  namespace on the `supplier/*` route group), not an architecture change.
- **Separate KYC and approval lifecycle**: Supplier KYC is its own lean, purpose-built submission and
  document model (`SupplierKycSubmission`, `SupplierKycDocument`), not the generic KYC engine built
  for Client/Partner `BusinessAccount`s under §7. The generic engine is tightly bound to
  `BusinessAccount` — its `KycSubmission.business_account_id` foreign key cannot point at a Supplier
  without an incompatible schema change, and D1/D23's single-account structure is exactly what that
  binding protects. A parallel, smaller model keeps both untouched. The two are visually and
  procedurally similar on purpose (the same "submit, review, approve/reject/request-correction"
  shape the Client/Partner KYC engine already established) but share no table and no foreign key.

### Product ownership stays with Feriwala

D1 and D23 are unaffected: only Feriwala's central catalogue (`App\Domain\Catalog\Models\Product`)
is ever sold to Client/Partner accounts, and only Admin or an Authorized User creates or publishes a
product — §12 continues to hold exactly as it always has. A Supplier's **listing request** is a
proposal that may be connected to an existing central product or used as the basis for Admin to
create a new one through the existing product architecture; a Supplier never writes to `products`
directly, and submitting a listing request never publishes anything.

### Supplier Rate confidentiality

A Supplier's own rate for what they supply (`SupplierOffer.supplier_rate_minor`) is commercially
sensitive in the ordinary sense a wholesale cost price always is, and is treated with the same
severity §12 and D12 already give wholesale cost data: it is visible only to that Supplier and to
staff holding `supplier_pricing.view`, and it must never reach a Client/Partner Inertia prop, a
Partner Website API response, an export, a log, or a notification. The **Platform Rate** — what
Feriwala actually charges — is the only figure a Client/Partner ever sees, exactly as a partner
never sees `Product.base_cost_minor` today. This is not a new policy; it is the existing wholesale-
price confidentiality boundary extended to a second source of cost data.

## Change control 🔒

Any future change affecting **merchant of record · financial ledger · payment direction ·
storefront separation · single account structure · product ownership · wallet settlement ·
referral rewards** must be raised for approval **before** implementation. (Referral rewards were
changed under this rule on 2026-09-19 — see D24.)

I will treat a request touching any of these as a stop-and-confirm point rather than an
instruction to proceed.
