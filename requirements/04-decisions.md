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

## D4 — Currency (was Q4) — **AMENDED 2026-09-23 by D26**

**BDT base and operational currency for v1.**

- Every financial table carries a **`currency_code`** column, default `BDT`.
- Fixed-precision decimal. **Never floating point.** 🔒 The "or integer minor units" alternative
  this decision originally permitted is **withdrawn** — see D26, which settles the representation
  as flat Taka in `NUMERIC(19,2)` throughout.
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

## D10 — Deployment (was Q10) — **AMENDED 2026-10-12 by D30**

Initial target is a **single well-configured VPS**, built horizontally scalable and
load-balancer-ready from day one: stateless app servers · Redis sessions, cache, queues ·
shared or **S3-compatible object storage** · environment-based configuration · separate queue
workers · health-check endpoints · reverse-proxy and load-balancer readiness · no local-only
persistent files.

**D30 adds** the shared storefront to the same deployment model: one Next.js container serving
every partner domain, behind **Caddy** with on-demand TLS that asks the ERP before issuing any
certificate. Docker and Caddy are production concerns only; D5 still holds for local development.

## D11 — Storefront architecture (was Q11) 🔒 — **AMENDED 2026-10-12 by D30**

> **D30 settles how the last bullet is built:** one shared Next.js application and one deployment
> serve every partner website. Each website keeps **its own** credentials (§3.1 of the contract) —
> there is no shared platform credential — and the storefront picks a website's credential only
> from a domain the ERP has verified and activated. Everything below still holds as written.

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

## D12 — Dropshipping money flow (was A1) 🔒 **the most load-bearing decision in the project** — **AMENDED 2026-10-12 by D30**

> **D30 amends step 4 only.** Feriwala stays merchant of record, and payment verification, COD
> reconciliation, refunds' approval and execution, the ledger, and supplier payables stay with
> Feriwala. What changes is who runs the **operational** steps of a website order: the owner of the
> website may now operate every order that came from their own websites (confirmation, processing,
> fulfilment, courier, delivery, returns, and asking for a refund), subject to supplier fulfilment
> ownership. Platform staff keep every capability across all websites. See D30 §7. Not yet built.

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

> **Amended by [D27](#d27--activation-is-automatic-once-its-conditions-are-met-2026-09-27).** The
> three reviewer outcomes below, the permissions, the concurrency rules and `approval_pending_at`
> all stand. What changed is that reaching `ApprovalPending` no longer _waits_ for a reviewer on the
> normal path: the platform activates the account itself once every §5.1 condition is met. Manual
> activation remains, for accounts explicitly held for review.

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
  documented multi-auth behaviour, not a gap. A browser _could_ therefore hold both a Client/Partner
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

A Supplier's own rate for what they supply (`SupplierOffer.supplier_rate`) is commercially
sensitive in the ordinary sense a wholesale cost price always is, and is treated with the same
severity §12 and D12 already give wholesale cost data: it is visible only to that Supplier and to
staff holding `supplier_pricing.view`, and it must never reach a Client/Partner Inertia prop, a
Partner Website API response, an export, a log, or a notification. The **Platform Rate** — what
Feriwala actually charges — is the only figure a Client/Partner ever sees, exactly as a partner
never sees `Product.base_cost` today. This is not a new policy; it is the existing wholesale-
price confidentiality boundary extended to a second source of cost data.

## D26 — Flat Taka throughout the system (2026-09-23) 🔒

**Instructed directly by the Project Owner on 2026-09-23.** This is the change-control approval the
"financial ledger" lock requires. It **supersedes** D4's permission to store integer minor units,
and it **replaces** the minor-unit boundary architecture that had been implemented up to this point.

### The rule

Feriwala has **no poisha or minor-unit convention at any layer**. A monetary value means Taka
wherever it appears:

| Layer       | `100` means | `100.50` is stored/carried as |
| ----------- | ----------- | ----------------------------- |
| Form input  | BDT 100     | `100.50`                      |
| Database    | BDT 100     | `100.50`                      |
| API payload | BDT 100     | `100.50`                      |
| Calculation | BDT 100     | `100.50`                      |
| Display     | `৳100.00`   | `৳100.50`                     |

Nothing multiplies or divides by 100 to cross a boundary. The previously-approved position — that
authoritative storage is integer minor units and Taka appears only at human-facing edges — is
withdrawn in full.

### Why this replaces the minor-unit boundary

The minor-unit boundary was internally consistent but put a translation step between what a person
means and what the system stores, and that step is the only reason a 100-times error can exist at
all. Every money field, every payload key and every cast then had to be individually audited for
which side of the boundary it sat on — and the audit that preceded this decision found real gaps:
11 money columns bypassed `MoneyCast` entirely, 4 had no cast at all, and `Money::multipliedBy()`
and `Money::percentage()` were themselves routing every tax, gateway charge and percentage
commission through binary floating point. Removing the conversion removes the class of defect.

### Representation

- **Storage** is `NUMERIC(19,2)` with an explicit scale, beside the existing `currency_code`
  column, which is unchanged. Range is ±10¹⁷ Taka.
- **Scale** for BDT is 2 decimal places unless the specification proves a particular value must be
  whole Taka.
- **In PHP**, `Money` holds an exact decimal Taka string and computes through bcmath. `float`,
  `double`, `round()` and `number_format()` are prohibited for financial values. Percentages state
  their rounding explicitly; allocation across lines reconciles to the exact total.
- **On the wire**, decimal strings. `Money::jsonSerialize()` returns flat Taka.
- **Naming** drops minor-unit terminology: `amount_minor` → `amount`, `price_minor` → `price`,
  `supplier_rate_minor` → `supplier_rate`, `platform_rate_minor` → `platform_rate`,
  `*_minimum_minor` → `*_minimum_amount`, `*_maximum_minor` → `*_maximum_amount`.

### Stored data

125 columns across 47 tables were `BIGINT` poisha. They convert by `value::numeric / 100` —
`10050` → `100.50`, `1` → `0.01`, `0` → `0.00`, `-2550` → `-25.50` — in corrective migrations that
are transactional, guarded against running twice, and exactly reversible by `× 100`. Committed
historical migrations are not rewritten.

Two mechanical facts govern how those migrations are written, both verified against an isolated
PostgreSQL schema rather than assumed:

1. **`ALTER TABLE` does not fire row triggers.** The immutable ledgers convert with
   `ledger_entries_no_update`, `supplier_ledger_entries_no_update` and the append-only guards
   fully armed. **No immutability exception is needed and none may be introduced.**
2. **`ALTER COLUMN … TYPE` is refused on any column named in a trigger definition**
   (`cannot alter type of a column used in a trigger definition`), so the seven
   `BEFORE UPDATE OF …` guards naming money columns must be dropped and recreated around the
   conversion. They **must be recreated with the new column names**: the guard function resolves
   its arguments as strings (`to_jsonb(OLD) -> locked_column` from `TG_ARGV`), and PostgreSQL does
   not rewrite those string literals on rename, so a guard left pointing at `amount_minor` **fails
   open silently** — verified — leaving a financial identity column writable with no error.

CHECK constraint expressions and `BEFORE UPDATE OF` column lists do follow renames automatically;
only constraint names need correcting. No index, generated column or view touches a money column.

### Frozen Storefront API

The v1 contract carried `{minor_units, currency, decimal}`. It gains a flat-Taka `amount` field as
a **non-breaking addition**, which §8's compatibility rules permit into v1. The legacy
`minor_units` key survives inbound and outbound only through an explicit compatibility adapter for
a documented period; no new internal code reads or writes it. Tests prove a legacy-shaped payload
cannot produce a 100-times overcharge or undercharge. The migration strategy is written into
[05-storefront-api-contract.md](05-storefront-api-contract.md) §4.1 rather than the contract being
silently changed.

## D27 — Activation is automatic once its conditions are met (2026-09-27)

**Instructed directly by the Project Owner on 2026-09-27**, as a beta-critical correction. Amends
[D22](#d22--activation-review-outcomes-and-readiness-2026-09-03). Does not touch any
change-controlled area: who decides an activation is not the account structure, the ledger, or the
direction money moves.

### The rule

An account becomes **Active automatically** — with no second manual approval — the moment all of
the following hold:

1. KYC is approved.
2. The selected package is still valid and available.
3. A payment for the exact server-calculated amount and currency has settled.
4. That payment belongs to this account and this activation attempt.
5. The payment is not expired, duplicated, reversed, or under reconciliation.
6. Every other onboarding prerequisite is complete (owner email and mobile verified).
7. There is **no explicit hold, risk or manual-review flag** on the account.

Conditions 1–6 are the §5.1 requirements that `ActivationRequirements` has always checked; D22
already required them to be re-checked under a row lock, and they still are. Nothing has been
relaxed. Condition 7 is new, and is the whole of what replaces the old manual step.

### What changed, and why

D22 left `ApprovalPending` as a queue an account **waited in**. In practice, by the time an account
reached it there was nothing left for a reviewer to weigh: a person had already approved the KYC,
and the payment had already been verified server-side against the exact amount owed. The approval
click added no judgement — it added office hours. An applicant who did everything right on a Friday
evening could not trade until Sunday, and every such account sat in a queue whose only real function
had become latency.

So the decision moves to where the evidence is. `ActivateAccountAutomatically` re-evaluates
readiness, and on a ready, unheld account calls the existing `ActivateAccount` with **no approver**.
It is the same transaction, the same per-account lock, the same requirements re-check, the same
wallet, deposit obligation, subscription activation and referral commissions. There is no second
activation path; there is one path, now reachable without a human in front of it.

### Holds preserve authorized manual review

A hold is the exception, made explicit: `activation_hold_reason`, `activation_held_at` and
`activation_held_by` on `business_accounts`, all three present or all three null, enforced by a
CHECK. Setting one requires a named member of staff and a reason, and is audited as sensitive.

A hold turns off the **automatic** path only. A held account still reaches `ApprovalPending` and
still appears in the review queue — more prominently, since a person now has to look at it — and a
reviewer with `account.approve` can still activate it through the ordinary manual route. The hold
returns the decision to a person; it does not take it away from everyone. The first time the
platform would have activated a held account and did not, it says so once in the audit trail, so a
held account is distinguishable afterwards from one that was merely unlucky in a queue.

`ActivationRequirements` deliberately does **not** know about holds. Those are the conditions §5.1
requires of the account itself, and a reviewer must be able to satisfy themselves and activate
without first unpicking the requirement list.

### Idempotency and concurrency

The same settlement is announced by the gateway's IPN, by the browser returning, and by the
reconciliation sweep — three callers for one event, which may arrive at once. Whichever reaches the
per-account lock first activates; the rest find an account that is already active and do nothing.
Activation can therefore never run twice, and never produces a second wallet, deposit obligation,
subscription term or referral commission.

The automatic path never throws. It runs after the payment has committed, and a failure to
activate — a notification queue down, a lock contended past its wait — must never roll back money
that genuinely arrived. Such an account stays at the gate, where a reviewer can activate it in a
moment.

### Known gap

An activation quote reduced to zero by a 100% discount never creates a payment at all, so it can
never settle and can never trigger this path. Such an account still requires manual activation.
Recorded rather than silently worked around; see [TODO.md](TODO.md).

## D28 — `user_addresses` and `shared_addresses` stay separate; no name-based backfill (2026-09-28)

**Instructed directly by the Project Owner on 2026-09-28**, as a correction during the Location
Directory + Shared Address batch. Does not touch any change-controlled area.

### The rule

`user_addresses` and `shared_addresses` are **not** two competing implementations of the same
feature. They answer different questions, for different owners, and neither is migrated into the
other:

- **`user_addresses`** — a `User`'s own present/permanent address (§5.2, collected at registration,
  currently unwired — see the existing gap noted below) and the billing/shipping address a Wholesale
  order is placed against (`SaveCheckoutAddress`, `PriceCheckout`,
  `WholesaleCheckoutController`). Free-text `city`/`district`, no structured geography. An order's own
  `billing_address`/`shipping_address` snapshot (on `Order` itself) is downstream of this, not of
  `shared_addresses`.
- **`shared_addresses`** — a `BusinessAccount`'s business/operational address book, or a Supplier's
  registered/pickup/return address book (`app/Http/Controllers/{Erp,Supplier}/AddressController`,
  `resources/js/pages/{erp,supplier}/addresses`). Structured against `bd_locations`
  (Division/District/Upazila/optional Union), both at the database and the HTTP boundary.

No screen and no order flow reads from both for the same purpose today. Wholesale checkout has never
been touched by the Address Directory batch and continues to use `user_addresses` exactly as before.

### Why no backfill

Backfilling `user_addresses.city`/`district` into `shared_addresses`' structured
division/district/upazila/union columns would require matching free-text strings against
`bd_locations` names — "Dhaka", "dhaka city", "Dhk", or a misspelling, against one of 504 upazilas
and 4,964 unions with overlapping and repeated names across the country. **A name match is a guess**,
explicitly ruled out. A wrong automatic mapping would silently misfile a real address against the
wrong Upazila, and nothing downstream would notice until a shipment went to the wrong Thana.

An adapter is equally unsafe for the same reason: any translation from free text to a `bd_locations`
row id still has to resolve the mapping somehow, and every resolution path bottoms out in matching a
name.

The safe path is therefore **neither** of the two offered: not an automatic backfill, and not a
compatibility adapter that silently guesses on read. `user_addresses` rows are left exactly as
written — no deletion, no rewriting, no reinterpretation.

### If `user_addresses` needs structured geography later

That is a separate, future decision, not a consequence of this one: a human-supervised backfill tool
that shows a staff member or the account holder each free-text address next to its best-guess
`bd_locations` match and asks for confirmation before writing anything — never an unattended migration.
Out of scope here and not started.

## Change control 🔒

Any future change affecting **merchant of record · financial ledger · payment direction ·
storefront separation · single account structure · product ownership · wallet settlement ·
referral rewards** must be raised for approval **before** implementation. (Referral rewards were
changed under this rule on 2026-09-19 — see D24.)

I will treat a request touching any of these as a stop-and-confirm point rather than an
instruction to proceed.

**Implementation status: complete and verified, 2026-09-24.** A coding session applying this
decision was interrupted mid-work on 2026-09-23; the recovery that carried it to completion is
recorded in [TODO.md](TODO.md)'s revision log under that date. Every layer in the table above —
form input, database, API payload, calculation, display — was confirmed against the shipped code by
static analysis, the full Pest and Vitest suites, and a bounded live-browser pass, with no
regression to the D4/D24-frozen boundaries this decision does not touch.

## D29 — External store connections: partner-merchant orders, mirrored read-only (2026-10-11) 🔒 — **AMENDED 2026-10-12 by D30**

> **D30 retires the owner's self-service connection** (decision 4 below, and the `store:manage`
> permission's use in the Owner Panel). Partner websites are now served by Feriwala's own shared
> storefront and take orders through the native Storefront API, so they are never connected through
> this mirror. The tables, the records, the imported orders, the webhook endpoint and the code all
> stay; nothing is deleted, and existing orders remain in All orders and Customers. Whether the
> mirror is later reopened, and by whom, is a separate decision.

**Approved by the Project Owner on 2026-10-11.** Extends D11 and D12 for one new order source; it
changes nothing about any existing one.

### What was approved

Partners may connect stores they run on other platforms (first: DFCOMMERCE) and follow those
stores' orders in the Owner Panel beside their Feriwala orders. Four decisions settle it:

1. **A separate, read-only mirror.** External orders live in their own tables (`external_orders`,
   `external_order_items`, `external_order_events`, `external_customers`), not in `orders`.
   `order_items` is bound to Feriwala's catalogue and `orders` to its status machine, stock, payment
   and earnings workflows; none of that may see an order Feriwala did not sell.
2. **The store is the source of truth.** Feriwala records what the store reports — by sync and by
   signed webhook — and changes nothing at the store. No status, refund, payment or shipment is
   pushed back. Feriwala's own status vocabulary is never applied to these orders.
3. **Partner-merchant, with no Feriwala money path.** The partner collects payment and fulfils.
   Amounts are stored for display only: no payment, wallet, ledger, commission, settlement, refund
   or fulfilment row is ever written for an external order.
4. **A package feature with a per-package limit.** `external_store_access` and
   `external_store_limit`, off and zero by default. Only someone holding the account permission
   `store:manage` (the owner by default) connects a store; `order:view` reads orders and customers.

### What it does not change

- **D11 still holds for Feriwala-hosted storefronts.** They remain the only storefronts that consume
  the ERP API; an external store is never given ERP credentials — Feriwala calls it.
- **D12 still holds for every existing order source.** Website and wholesale orders keep Feriwala as
  merchant of record exactly as before.

### Invariants the code enforces

- One live connection per store address across all accounts (partial unique index); which account
  holds it is never disclosed.
- Credentials and the webhook secret are encrypted at rest, hidden from serialisation, never in a
  page prop, log or error; disconnecting forgets them and keeps the orders.
- Calls to a store go only to HTTPS addresses whose every resolved IP is public, pinned to the
  checked address, with redirects off and short timeouts.
- Webhooks are verified by HMAC over the raw body, require the store's event id, and are recorded
  once per store and event id.
- An external order is never deleted; its history is append-only; an older copy never overwrites a
  newer one; money is parsed from the store's text without passing through a float (D26).

## D30 — Shared multi-tenant storefront; per-website credentials; owner operations (2026-10-12) 🔒 — **SUPERSEDED 2026-10-13 by D32**

> **Superseded by D32.** The shared multi-tenant deployment (§1), automatic credential
> provisioning to a shared registry (§2, last bullet), the shared Caddy/VPS hosting model as the
> default (§4) and the P3–P8 rollout order (§10) no longer apply. What D32 keeps from D30 is listed
> there. The text below is kept as the record of what was decided on 2026-10-12.

**Approved by the Project Owner on 2026-10-12.** Amends D10, D11, D12 (step 4 only) and D29, and
adds to the frozen Storefront API contract without breaking it. The DFCOMMERCE Laravel project is a
read-only design and feature reference; none of its backend, database, payment engine or OMS is
reused.

### 1. One storefront, many websites

- **One** Next.js (App Router, TypeScript) codebase and **one** deployment serve every partner
  website. Each website has its own domain, branding, published products, customers, orders and
  configuration. The ERP is the only operational backend.
- The storefront repository is a separate project, built after the ERP foundations below (phase
  P3 onward). Nothing in it may become a requirement for running the ERP.

### 2. Credentials: per website, never shared

- **Each website keeps its own Storefront API credentials** (contract §3.1): independently
  issued, rotated and revoked. There is **no** shared platform credential and no host-bound
  platform signing scheme.
- Credentials live only on servers. No secret, gateway credential or internal key ever reaches a
  browser or a client bundle.
- The storefront chooses which credential to sign with **only** from a domain-to-website mapping
  the ERP has verified and activated (§3). It never takes a `website_id`, an `account_id` or a
  credential identifier from a request, a cookie or a query string.
- **The ERP checks the pairing independently.** A storefront request may state the host it is
  serving in `X-Feriwala-Storefront-Host`; when present, the ERP resolves that host itself and
  refuses the request unless it belongs to the website that owns the credential. Forged `Host` and
  `X-Forwarded-Host` headers therefore cannot move a request to another website: the credential is
  still the tenancy boundary (§3.5 of the contract) and the host can only narrow it.
- **Provisioning** (phase P3, not yet built): credentials are issued in the ERP by staff and
  delivered to the storefront's server-side registry over an authenticated, encrypted channel,
  keyed by website. Adding a website or rotating a secret updates the registry; it never needs a
  new deployment. Until that channel exists, credentials are not handed to the shared deployment.

### 3. Domains: verified before they serve

- A website always answers on its subdomain, `{subdomain}.{storefront domain}`, which the ERP
  allocates and guarantees unique.
- A custom domain serves only when it is **verified and active**. Verification is real evidence: a
  DNS TXT record at `_banij-verify.{domain}` holding the token the ERP generated for that
  domain, checked by the ERP. The ERP never marks a domain verified on anybody's say-so.
  **Clarified 2026-10-12 (D7):** the record name is `_banij-verify`, not the earlier draft's
  `_feriwala-verify`. No domain had been verified under the old name — the routing columns had
  never reached production — so nothing needed migrating.
- Routing state is separate from the registration record staff already keep (D9): **unverified →
  verified → active ⇄ suspended**, and back to unverified when the token is reissued. Activation
  needs a verified domain whose registration is running and a website that is not closed. Every
  transition is audited.
- A domain is held by at most one website while it is verified, active or suspended, enforced by a
  partial unique index, so nobody else can claim it — including after the first owner's
  registration lapses, until that row leaves those states.
- One domain is the website's primary address; any other active domain is an alias. Aliases are
  expected to redirect to the primary.

### 4. Hosting and TLS

- Production runs on a **self-hosted VPS with Docker and Caddy**. Caddy issues certificates on
  demand, and **asks the ERP first**. The ERP's answer is yes only for a website's subdomain or an
  active custom domain of a website that is not closed; every other name is refused, so an
  attacker cannot make Caddy fetch certificates for domains they point at the server.
- The ask endpoint is authenticated by a token in its address, may additionally be restricted to
  Caddy's network addresses, and is rate-limited. The first authorisation of each custom domain is
  audited.
- Dangling DNS: a domain whose registration lapses or whose website closes stops resolving to a
  website at once, so a certificate cannot be renewed for it and the storefront serves nothing.
- **Clarified 2026-10-12 (D6): a suspended website keeps HTTPS.** Its subdomain and its active
  custom domains still receive certificate authorisation, so the storefront can show its
  "unavailable" page over HTTPS. It accepts **no** orders and no checkout: order submission
  requires a live website, and a suspended website's credentials are refused by the Storefront API
  altogether. This is about a suspended _website_. A suspended, unverified or lapsed _domain_
  receives no new authorisation, and a closed website receives none at all. Nothing else about
  website or domain suspension changes.

### 5. Caching and sessions

- Every cache key and tag in the storefront carries the resolved website. Pages and API calls that
  depend on a customer — cart, checkout, account, order, payment — are never cached.
- Customer sessions use host-only cookies (no `Domain` attribute), so a session from one website's
  domain is never sent to another.
- Customers are **website-bound**: the same phone number on two websites is two customers, as the
  `(website_id, mobile)` uniqueness already makes it.

### 6. Orders, payments and publishing

- **One order path.** Every storefront order becomes exactly one native website order through the
  existing `POST /orders` (contract §6.1), with the website and owner taken from the credential,
  idempotency keys, and the `(website_id, storefront_order_reference)` uniqueness. The read-only
  external-store mirror (D29) is never used for these websites.
- **Payments go through Feriwala's gateway** from launch, using its payment sessions; the
  storefront can never declare an order paid (contract §6.1). Stock reservation, ledger and
  settlement rules are unchanged. Feriwala stays merchant of record (D12).
- **Publishing** reuses the existing website product selection: a product the owner is eligible for
  appears in My Products; _Connect to Website_ adds it to one or more of the owner's own websites,
  subject to package limits, website status, pricing rules and stock. The storefront shows only that
  website's published products.

### 7. Who operates a website order

- The website's owner may operate **every order that came from their own websites** —
  confirmation, processing, fulfilment, courier, delivery, returns — and may **request** a refund.
  Lines a supplier fulfils keep the supplier's own controls.
- Platform staff keep every capability across all websites.
- Owners may never verify payments, reconcile COD, approve or execute refunds, post ledger entries,
  or change supplier payables. Every operational action is authorised and audited.
- Built in a later phase through account-level permissions and a website-order policy. The
  platform `order.*` permissions stay platform-only.

### 8. Customer accounts and launch catalogue

- Guest checkout, and full customer accounts signed in by a **phone OTP the ERP issues**: profile,
  addresses, order history and tracking, all bound to one website.
- At launch: categories, search, sorting and filtering, best sellers, flash deals. Reviews, coupons
  and landing pages wait.

### 9. Website capacity

- The package's **Websites** limit is the number of websites an owner may have, read from the
  subscription's own terms: 0 means none, 1 means one, 2 means two, and so on; no limit is
  hard-coded. Each permitted website has its **own allocation slot**, with its own domain and
  hosting entitlement. A slot already written for a confirmed purchase is kept and still
  counts, so an older subscription never loses a website it was given.
- A closed website frees its slot. Renewals and package changes continue each slot's own period,
  so no term is shortened or restarted.

### 10. Rollout, migration and rollback

- All schema changes are additive. Existing allocations become slot 1; existing domain records
  start unverified, so nothing begins serving without verification. No record is rewritten.
- Order: ERP foundations (P1) → ERP commerce APIs: search, delivery areas and quote, customer OTP
  (P2) → storefront skeleton and credential registry (P3) → checkout (P4) → customer accounts (P5)
  → Connect to Website (P6) → owner order operations (P7) → go-live hardening (P8).
- Each phase is reversible on its own: the new columns and tables can be dropped by their
  migrations without touching what existed before them.
- Security review gates go-live: tenant isolation across hosts, certificate refusal for
  unverified names, credentials absent from client bundles, and DNS takeover cases.

## D31 — Controlled commerce API (P2) (2026-10-12) 🔒

**Approved by the Project Owner on 2026-10-12** as decisions H1–H9 of the P2 brief. Adds to the
frozen Storefront API contract (§5.1.1–§5.1.3, §5.5, §6.0, §6.1, §7.1, §7.2) and makes two v1
compatibility corrections. Merchant of record, the ledger, payment direction, supplier payables and
stock reservation are unchanged.

| #   | Decision                                                                                                                                                                                                                                |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| H1  | A checkout quote is **advisory**. `POST /orders` prices the cart again with zero tolerance and refuses any difference with `price_mismatch`.                                                                                            |
| H2  | A quote **never reserves or deducts stock** and writes nothing. Its `reservable` answer applies the placement rules read-only.                                                                                                          |
| H3  | Delivery stays the **flat per-package** website delivery fee. The weight and area engine is not switched on.                                                                                                                            |
| H4  | Delivery areas come from the **BdLocation** directory, under stable natural-key identifiers.                                                                                                                                            |
| H5  | Best sellers: a rolling **30-day** window of paid online orders and confirmed COD orders, excluding cancelled and refunded ones. Details approved later the same day — see below.                                                       |
| H6  | **Owners** set promotional windows on their own selections, within the administrator's limits: the window is locked wherever the promotional price is.                                                                                  |
| H7  | Search is **escaped `ILIKE`**, as a bound parameter. No `pg_trgm`.                                                                                                                                                                      |
| H8  | A payment return address must be HTTPS on port 443, with no credentials or fragment, within a length limit, to the website's subdomain or a **verified, active** domain or alias — checked when the order is taken and again on return. |
| H9  | A trashed or ineligible product is **never listed** on any catalogue endpoint.                                                                                                                                                          |

### v1 compatibility corrections

Both narrow behaviour that was a defect, not a documented promise; no field is removed or renamed.

- **Stricter return addresses (H8).** A `return_url` on a domain that is recorded on the website
  but not verified and active is now `422 return_url_not_allowed`. Orders already taken keep their
  stored address; the redirect revalidates it and sends the customer to the website's home page if
  it no longer passes. Payment settlement is server-side and unaffected.
- **Eligibility-based listing (H9).** The catalogue lists only what order pricing accepts, so a
  product that would have been refused at checkout is no longer listed.

### Also recorded

- `website.updated` tells a storefront to re-read `GET /site` after branding, contact, image,
  serving-host or payment-method changes. A gateway change that turns online payment on or off in
  a currency is announced, on the queue, to every served website in that currency with a webhook
  endpoint — one pending event per website; a change that flips nothing announces nothing.
  Cash-on-delivery terms have no writer yet: whatever comes to change them must announce through
  the same mechanism (`AnnouncePaymentMethodsChanged` for a platform-wide change,
  `AnnounceWebsiteUpdated` for one website). `website.suspended` is aligned with the code — low
  balance and grace are not suspension.
- Webhook endpoints are resolved at registration and before every attempt, every address must be
  public, each attempt is pinned to the checked address, and redirects are never followed.

### Best-seller semantics (H5) — approved 2026-10-13

- **Window:** orders placed in the last 30 days.
- **A sale:** a paid online order (its payment names a gateway and the order has `paid_at`), or a
  cash-on-delivery order the customer confirmed — its payment names no gateway and its status
  history holds the move to `confirmed` with source `storefront`, which only `ConfirmCodOrder`
  writes. The current status alone never counts as confirmation.
- **Excluded:** orders now `cancelled`, `refund_pending`, `partially_refunded` or `refunded`.
- **Net units:** line quantity less the quantities received on `received` or `refunded` returns,
  summed per line before joining so no return is deducted twice; never below zero.
- **Ranking:** net units, then distinct orders still carrying a unit, both descending, then the
  selection id. A selection with no sales follows all that sold, in selection order.
- **Freshness:** a derived summary (`website_product_sales`) rebuilt every fifteen minutes in one
  serialised transaction; the storefront reads it by key and never aggregates orders per request.

## D32 — One independently installed storefront per Owner (P3) (2026-10-13) 🔒

**Approved by the Project Owner on 2026-10-13.** Supersedes D30 §1, §2 (automatic provisioning),
§4 and §10; amends D10 and D11 again. The Storefront API contract (`05`) stays frozen at v1 and the
Central Banij ERP stays authoritative for catalogue, prices, stock, orders, payments, customers and
money. Architecture-locked: changes need approval before implementation.

### 1. One installation per Owner

- Each Owner's website is served by **its own installation** of the storefront: separate source
  code, hosting and domain, built from one reusable **Master Storefront Template**.
- A shared multi-tenant Next.js deployment is **not** the default. Nothing in the ERP may assume
  that two websites share a deployment, a host or a credential store.

### 2. Credentials: generated in the ERP, entered by hand

- **Only the Super Admin** generates, rotates and revokes a website's Storefront API credentials,
  each scoped to one website.
- The secret is shown **once**, as a ready-to-paste `.env` block, and an Admin enters it into the
  storefront's **server-side** environment by hand. There is **no automatic provisioning** — no
  registry, push or retrieval channel — and a secret never reaches a browser or client bundle.
- The website is still taken only from the credential (contract §3.5); the ERP still resolves a
  stated `X-Feriwala-Storefront-Host` itself and refuses a host that is not the website's.

### 3. Who manages what

| Super Admin (ERP)                                                           | Owner Panel (ERP)                                                        |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| API credentials, installation status, connection, activation and suspension | The website's business data: branding, contact, products, prices, orders |

### 4. Kept from D30

Per-website credentials and the ERP's independent host check (§2, except automatic provisioning);
domain verification by DNS TXT before a domain counts as the website's (§3) — now the evidence for
return-URL and host checks rather than for shared TLS; caching and session rules (§5), now the
template's obligation; one order path through `POST /orders` with Feriwala's gateway and Feriwala as
merchant of record (§6); owner order operations (§7); customer accounts (§8); website capacity (§9);
additive, reversible migrations (§10, first bullet).

### 5. ERP work in P3

ER-1 Super Admin-only integration permissions · ER-2 storefront installation records and the Admin
Storefront tab · ER-3 one-time credential display as an `.env` block · ER-4 safe rotation and
emergency revocation · ER-7 a checkout-quote scope and credential presets · ER-9 existing ERP
integration prerequisites · ER-11 order-specific authorisation tokens for order reads and per-order
actions. Their contract details (`.env` names, token transport, scope names) are agreed with the
storefront session before implementation.

## D33 — The activity log can be cleared, through one guarded path (2026-10-13) 🔒

> **Numbering note (2026-10-14).** This decision was recorded on `main` on 2026-10-13 as **D29**.
> That number already records _External store connections_ above (2026-10-11), so it was
> renumbered **D33** when `origin/main` was merged into `external-stores`. Its meaning and text are
> unchanged; they follow verbatim. The copy on `main` also ended with a truncated repeat of this
> heading and its first sentence, which added nothing and is not carried over. Code and comments
> that cite "D29" for the activity-log purge (8 files) still read D29 until a separately approved
> cleanup.

**Approved by the Project Owner on 2026-10-13**, deliberately and including financial and security
entries. This amends §36.2 ("ordinary administrative users must not edit or delete audit logs") and
the append-only rule for audit logs in CLAUDE.md. Ledger entries are untouched.

- **Who:** `audit.delete` clears the log and `audit.manage_settings` sets how long it is kept. Admin
  holds both; any other role gets them only if granted. A business identity is refused whatever it
  holds.
- **How:** one action, `PurgeAuditLogs`. A manual clear takes a reason of at least 10 characters and
  the person's own password, for everything or for one staff member (or the system), optionally only
  entries older than some days. A nightly sweep (`audit-log-retention`, 03:30) clears entries older
  than the retention setting (`audit.log_retention_days`, default 0 = keep forever).
- **What stays true:** UPDATE and TRUNCATE are still refused. The database refuses every DELETE
  except inside the transaction the action opens (`feriwala.purge_audit_logs`, the same technique as
  product force delete), and refuses to delete an `audit.logs_purged` record under any
  circumstance. Every clearing writes that record — who, when, scope, how many, why — and it is
  permanent. Changing the retention setting is itself audited.
- **Cost, accepted:** once cleared, history including wallet, withdrawal, payment and permission
  changes cannot be recovered or investigated. The Project Owner chose this over protecting those
  categories.
