# 99 — Open Questions

Decisions I need from you. Each has my recommendation, so if you agree with all of them you can
just say "go with your recommendations" and I will proceed.

None of these change _what_ gets built — the spec is the spec. They change _how_, and picking
wrong now is expensive to undo later.

---

## Q1 — What do we do with the starter kit's Teams? 🔴 blocks P1-1

The repo ships `Team`, `Membership`, `TeamInvitation`, `TeamPolicy`, and a `{current_team}` URL
prefix on every authenticated route. But §5 is emphatic: one account, one panel, no account types.

Meanwhile §8.1 gives packages a **"Staff limit"**, which implies an account owner can have staff
users under them.

| Option                                   | Effect                                                                                                                                                                                 |
| ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A — Repurpose as staff (recommended)** | Rename Team → Account/Organization. The owner is the Feriwala account; staff are members with roles. Package "staff limit" caps membership. Keeps the invitation flow we already have. |
| **B — Remove entirely**                  | Simplest model, cleanest URLs. But then package staff limits need building from scratch later anyway.                                                                                  |
| **C — Leave as-is**                      | Not viable. `{current_team}` in every ERP URL contradicts the single-panel requirement and leaks internal structure into URLs.                                                         |

**My recommendation: A.** It satisfies the staff-limit requirement, reuses working code, and — done
right — is invisible to a solo user. I would drop the `{current_team}` URL prefix regardless and
resolve the account context from the session instead.

---

## Q2 — RBAC: Spatie or hand-rolled? 🔴 blocks P0-20

§32 wants 20 roles × 20 permission actions across ~30 modules.

**My recommendation: `spatie/laravel-permission`.** It is the standard, it handles caching and
role/permission hierarchies, and hand-rolling this is a week of work to arrive somewhere worse.
The only argument against is one more dependency, which does not outweigh it here.

---

## Q3 — Queue monitoring: Horizon? 🟡 affects P11-34

§42 requires queue monitoring and failed-job management. Horizon is the natural fit, but it is
Redis-only (fine — we are on Redis) and adds a dashboard that needs its own access control.

**My recommendation: yes, Horizon**, gated behind a `system.monitoring` permission. Building
equivalent queue metrics by hand is wasted effort.

---

## Q4 — Currency scope 🟡 affects P0-13 and every money column

§26 requires Stripe and PayPal (international) alongside six Bangladeshi gateways, and §26.4 lists
"currency handling". But every other signal in the spec reads BDT-domestic.

| Option                                                   | Effect                                                                                                                                                    |
| -------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A — BDT only, currency column reserved (recommended)** | Single currency in v1. Column exists so multi-currency is a later feature, not a migration nightmare.                                                     |
| **B — Full multi-currency now**                          | Exchange rates, per-currency ledgers, rate-at-time-of-transaction, reporting in base currency. Significant added complexity across every financial table. |

**My recommendation: A**, unless you are actually selling to non-BD customers at launch.

---

## Q5 — Local environment: Docker or your existing WSL services? 🟡 affects P0-2

You are on Windows with the project in WSL. I can either bring up PostgreSQL 16 + Redis 7 via
Laravel Sail/Docker, or use services already installed in your Ubuntu WSL instance.

**My recommendation: whichever you already run.** If you have Postgres and Redis in WSL, I will
point `.env` at them — fewer moving parts. If not, Sail. Tell me which and I will confirm the
versions before writing any migration.

---

## Q6 — Brand and visual direction 🟡 affects P0-32, and everything visual after it

§33 is unusually specific about what the UI must _not_ look like: no generic admin template, no
random gradients, no glow, no unnecessary glassmorphism, no AI-generated feel, no vanity stats.
That tells me what to avoid but not what Feriwala _is_.

To make the design decisions once instead of drifting screen by screen, I need:

- **Brand colours** — do you have a palette/logo, or should I propose one?
- **Reference points** — any SaaS product whose density and tone you want (e.g. Linear, Stripe
  Dashboard, Shopify Admin, something local)?
- **Language** — English-only ERP, or Bangla/English toggle? (SMS templates are explicitly
  bilingual per §30.2, so the question is whether the _interface_ is too.)
- **Density** — compact data-heavy tables, or roomier?

**My recommendation if you have no preference:** a restrained, dense, neutral-grey ERP with one
strong brand accent, Stripe-Dashboard-like in tone. I will produce a small visual direction
sample in Phase 0 for you to approve before I build 50 screens on top of it.

---

## Q7 — Which payment gateway first? 🟡 affects P1-52

All eight are required (§45), but activation payments need exactly one working gateway before
Phase 1 can complete. The first one I build also shapes the driver contract.

**My recommendation: SSLCommerz.** It has the best sandbox and documentation of the BD set, and it
covers multiple payment instruments in one integration — so it exercises more of the contract than
a single-wallet gateway like bKash would.

I will also need **sandbox credentials** for each gateway when we reach Phase 2. Worth starting
that request process early — merchant onboarding is usually the long pole.

---

## Q8 — Courier providers 🟡 affects P6.C

§21 requires courier management and demands the architecture accept new providers, but names no
specific provider. Which do you actually use? (Pathao, Steadfast, RedX, eCourier, Sundarban,
Paperfly are the usual BD candidates.)

**My recommendation:** build the driver contract plus a manual/no-API courier path in Phase 6, and
implement specific providers once you name them. Nothing blocks until then.

---

## Q9 — Domain and hosting provisioning: manual or automated? 🟡 affects P5.B

§16.2 tracks domain and hosting charges, expiry, and renewal, and §41 schedules renewal reminders.
It does not say whether Feriwala _automatically registers_ domains through a registrar API.

**My recommendation: manual in v1** — the ERP tracks, bills, and reminds; an admin performs the
actual registration/renewal and records it. Registrar API automation can be added later behind the
same tables. Say the word if you want it automated from the start.

---

## Q10 — What is the actual deployment target? 🟢 affects P11.D

§40 requires load-balancer-ready, horizontally scalable, stateless architecture. Knowing the real
target (single VPS now with room to grow? multiple app servers? a managed platform?) changes
storage and session configuration choices in Phase 0, not just Phase 11.

**My recommendation:** build to the §40 requirements regardless — stateless, Redis sessions, object
storage — so that a single server today can become three tomorrow without a rewrite. But tell me
the target so I configure the production files correctly.

---

## Q11 — Storefront: separate application or same app? 🔴 blocks P5-16

_Added during the verification pass — I had under-scoped this._

§16.1 requires each dedicated website to have homepage, listing, categories, search, filtering,
product details, cart, checkout, customer accounts, guest checkout, coupons, discounts, shipping
methods, payment methods, order tracking, returns, refunds, contact, legal pages and SEO. That is
a **second complete e-commerce application**, not a feature of the ERP.

§16 calls them "separate public Storefronts" and §17 has them talking to the ERP over APIs and
webhooks — which strongly implies separate deployables rather than extra routes in the ERP.

| Option                                                              | Effect                                                                                                                                                                                                                              |
| ------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A — Separate storefront app consuming the ERP API (recommended)** | Matches the spec's language and the API/webhook requirement literally. True isolation: a storefront compromise cannot reach ERP data. Scales independently. Costs: second codebase, second deploy pipeline.                         |
| **B — Same Laravel app, multi-tenant routing by domain**            | One codebase, faster to build, no API round-trips. But it makes the entire §17 integration layer ceremonial — you would be calling an API to talk to your own database — and it puts public traffic in the same process as the ERP. |
| **C — Hybrid: same app now, extract later**                         | Tempting, rarely happens. If we choose it, we choose it deliberately with the seams designed in, not by drift.                                                                                                                      |

**My recommendation: A.** The spec's API/webhook requirements only make sense if the storefront
is genuinely separate, and the security isolation is worth real money here — these are public
sites handling customer payment flows, sitting next to a system holding KYC documents and a
financial ledger.

This is the single largest sizing item in the project and I would rather surface it now than
discover it in Phase 5.

---

---

# Part 2 — Spec ambiguities

Q1–Q11 above are _"how should I build this"_. The ones below are different in kind: they are
_"what did you mean"_. The spec does not answer them, and I cannot resolve them by reading harder.

## A1 — Which way does the money flow on a dropshipping sale? 🔴🔴 blocks P2 and P7

**This is the most consequential unclear point in the whole document.** A customer buys from a
partner's dedicated website. Two completely different models are consistent with the text:

**Model 1 — Feriwala is merchant of record.** Customer pays into Feriwala's gateway. Feriwala
fulfils, then credits the partner a **commission**. Supports: all of §22 (commission rules,
eligibility, reversal), §23.1's `Commission credit`, §28's COD flowing to Feriwala for settlement.

**Model 2 — Partner is merchant of record.** Customer pays the partner's own gateway. The partner
keeps the **margin** between their retail price and Feriwala's wholesale price. Supports: §16.2's
per-website "Payment configuration", §15.1's user-set selling price and "allowed profit margin",
§10.1's "commissions **or margins**".

The spec contains evidence for both, and §23.1 lists `Sales credit` _and_ `Commission credit` as
separate transaction types — which hints it might be **both, configurable per package or per
website**.

This is not a detail. It determines who holds customer money, who bears chargeback risk, which
gateway credentials matter, what the wallet actually accumulates, whether COD settlement runs
toward or away from the partner, and whether §22's commission engine is the primary earnings
path or a secondary one.

**I need your answer before Phase 2.** I can build for either, or for a configurable both — but
building for the wrong one and discovering it in Phase 7 would mean reworking the ledger.

## A2 — Are wholesale orders fulfilled and shipped by Feriwala? 🟡

§10.2 ends with "Receive Products", and §20/§21 (fulfillment, courier) read as though written for
dropshipping. It is not stated whether a bulk wholesale order goes through the same
warehouse → pick → pack → courier pipeline, or is handled separately.

**My assumption unless told otherwise:** same pipeline, with `source = erp_wholesale`
distinguishing it. Say so if wholesale ships differently.

## A3 — Is "Joining reward" the same as the new-user referral reward? 🟡

§23 and §23.1 list `Joining reward credit` separately from `Referral reward credit`. §25.3 says
the newly activated user may receive a reward. Either these are the same thing named twice, or
there is a signup bonus that exists independently of any referral.

**My assumption:** separate — a joining reward payable on activation regardless of referral,
with the referral new-user reward on top. Cheap to support both; expensive to discover later.

## A4 — Which warehouse serves an order? 🟡

§19 has warehouse-wise stock and §20 has warehouse assignment, but no selection rule. Nearest?
Highest stock? Manual? Priority order?

**My assumption:** admin-configurable priority list with manual override, defaulting to a single
warehouse. Trivial now, painful to retrofit.

## A5 — Downgrade with excess published products 🟡

§8.3 lists "Product publishing limit changes" for downgrades. If a user has 100 products published
and downgrades to a 50-product package, what happens? Auto-unpublish the newest 50? Block the
downgrade? Grace period for the user to choose?

**My assumption:** block the downgrade until the user is under the new limit, with a clear message
telling them how many to unpublish. Least destructive option.

## A6 — Is the registration fee refundable? 🟡

§9 mentions "Refund calculation" and stores fees separately partly for that reason, but no refund
policy is stated for the registration or package fee. §24.4 covers deposit refundability only.

**My assumption:** non-refundable by default, admin-configurable — mirroring §24.4's pattern.

## A7 — What happens to a partner website's data when an account closes? 🟡

§16.4 has `Closed`, but nothing says what becomes of that website's customers, orders, and order
history. Retention period? Export for the partner? Anonymisation?

**My assumption:** retain, make read-only, no deletion without an explicit admin action. This one
may have legal implications on your side, so it is worth a real answer rather than my default.

## A8 — Tax model 🟡

§9 and §14 apply tax, and §11/§7 reference TIN, but the actual model is unspecified: VAT rate,
inclusive or exclusive pricing, whether tax applies to fees as well as goods, invoice format
requirements.

**My assumption:** configurable exclusive tax rules, since §9 lists tax as an admin-configurable
item. If Bangladeshi VAT invoice compliance has a specific required format, I need to know.

## A9 — Can users opt out of notifications? 🟢

§30 allows SMS control "By user where permitted", and §29's dashboard notifications include
security alerts and payment failures. It is unclear whether a user can silence notifications that
are arguably mandatory.

**My assumption:** users control marketing/informational notifications; security, payment, KYC,
and account-status notifications are non-optional.

## A10 — "Permitted channels" for reselling wholesale stock 🟢

§10.2 says the user may "Sell the purchased Products independently through permitted channels".
I read this as legal/contractual language with no system enforcement behind it.

**My assumption:** no enforcement built. Tell me if you expected a mechanism here.

---

**How I will handle these if you do not answer them all:** I will proceed on the stated assumption
for A2–A10, note it in the code and in this file, and flag it in the phase summary so you can
correct it cheaply. **A1 is the exception** — I will not guess on the direction of money.

---

## One note on scope, not a question

This is a genuinely large system — roughly 30 modules, 391 tasks on my current count, spanning
everything from a CMS to a double-entry-style financial ledger to eight payment integrations to a
multi-tenant storefront API.

I am not raising that to renegotiate it. §45 is clear that these requirements do not get reduced
without your written approval, and I am not asking for one. I raise it only so we are aligned on
pacing: I will deliver it phase by phase, each phase reviewable on its own, rather than
disappearing for a long time and returning with something monolithic and unverified.

If at any point you want to reorder phases — say, to get the landing page live early for marketing
while the ERP is still being built — that is easy to accommodate. Phase 10 is independent and can
move up.
