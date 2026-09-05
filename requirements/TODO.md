# Feriwala ERP — Master TODO

Every task, in dependency order, with stable IDs. I work top to bottom and tick items here as they
land. Spec references in parentheses point at [requirements.txt](../requirements.txt) sections.

**Legend:** `[ ]` not started · `[~]` in progress · `[x]` done · **⚠** blocked on a decision in
[99-open-questions.md](99-open-questions.md)

**Definition of done** for any feature task: server-side authorization + validation, audit logging
where sensitive, tests, and the full UI state set (loading / empty / error / success / permission
denied). Financial tasks add: DB transaction, row locking, idempotency, ledger entry, concurrency
test.

---

# Phase 0 — Foundation & Infrastructure

## P0.A Environment & database

- [x] **P0-1** Switch `DB_CONNECTION` to `pgsql`; update `.env`, `.env.example`, `config/database.php` (§2, §37)
- [~] **P0-2** Provision local PostgreSQL 16 + Redis 7 via Sail (D5) — _compose written; blocked on Docker install (needs sudo)_
- [~] **P0-3** Move cache, session, and queue drivers to Redis (§38) — _drivers switched; DB cache/session table cleanup pending first successful migrate_
- [x] **P0-4** Configure Redis connections: default, cache, queue, locks — separate databases
- [ ] **P0-5** Delete `database/database.sqlite`; ensure no SQLite assumptions remain in tests
- [ ] **P0-6** Configure named queues: `payments`, `sync`, `notifications`, `sms`, `reports`, `maintenance` (§39)
- [ ] **P0-7** Multi-server-safe scheduler baseline: `onOneServer()` + `withoutOverlapping()` helpers (§41)
- [ ] **P0-8** Private filesystem disk for KYC/sensitive documents; public disk for CMS media (§7.5)
- [ ] **P0-9** Object/shared storage readiness — no local-only persistent files (§40)

## P0.B Application skeleton

- [x] **P0-10** Create `app/Domain/*`, `app/Integrations/*`, `app/Support/*` structure per [01-architecture.md](01-architecture.md) — 24 domains + `app/Domain/README.md` conventions
- [x] **P0-11** Split controllers into `Http/Controllers/{Public,Erp,Admin,Api,Webhook}`
- [x] **P0-12** Action/Data/FormRequest conventions with a worked example: `ChangeLocaleRequest` → `LocaleChange` (Data) → `ChangeLocale` (Action) → thin `LocaleController` — 4 tests, no HTTP layer needed
- [x] **P0-13** `Money` value object + `MoneyCast` + JSON shape for React (BIGINT minor units, `currency_code`) (D4) — 32 tests
- [x] **P0-14** `HasPublicId` (ULID) + `HasSlug` + `HasReference` + `Reference`/`ReferencePrefix` generator (§34.2)
- [x] **P0-15** `HasStateMachine` + `TransitionableState` + `IllegalStateTransition` — 8 tests
- [ ] **P0-16** Shared status-history recording trait (previous, new, actor, at, reason, internal note, public note)
- [~] **P0-17** `IdempotencyService` implementing the frozen contract's §4.7 semantics — 11 tests. _Durable DB-backed record lands with payments in P2_
- [x] **P0-18** `DistributedLock` over the isolated Redis `locks` database, asserting the store is lock-capable (§38) — 13 tests, including lock release on exception
- [x] **P0-19** Scope-based `RuleResolver` + `RuleScope` + `RuleContext` shared by commission / deposit / withdrawal / referral rules — 17 tests, deterministic tie-breaking

## P0.C Access control & audit

- [x] **P0-20** `spatie/laravel-permission ^8.3` installed; teams feature enabled with `team_foreign_key = account_id` so platform roles (null account) and account staff roles stay separate (D2)
- [x] **P0-21** 20 roles seeded with 368 grants, all unscoped (`account_id` null) as platform roles. Separation of duties verified in the database: `finance_manager` has no `withdrawal.release_payment`, `withdrawal_approver` does
- [x] **P0-22** `PermissionCatalogue`: 28 modules × the 20 §32.2 verbs, declared as a matrix rather than a 560-entry cross-product → **161 permissions**, seeded and verified in the database. Ledger has no create/edit/delete, audit no edit/delete, KYC no delete — enforced by tests
- [ ] **P0-23** Policy base + registration convention; policy for every model is mandatory
- [x] **P0-24** `DataScopeResolver` (All/Own/None) + `ScopedToViewer` trait; export and sensitive-column access are separate permissions from view; no entitlement returns an empty set rather than an error that confirms rows exist (§31.3, §44) — 12 tests
- [x] **P0-25** `audit_logs` migrated. **UPDATE, DELETE and TRUNCATE all verified refused against live PostgreSQL.** TRUNCATE needed a separate statement-level trigger — row-level triggers do not fire for it, and testing found it wiping a "protected" table. Plus Eloquent guards, `AuditEntry`, and `RecordAuditLog` with §42 redaction (27 tests)
- [~] **P0-26** `SensitiveActionGuard` + `SensitiveActionRequest`: graded controls — password confirmation for all sensitive actions, 2FA for money movement, second approver for releasing payment, mandatory reason (19 tests). _Middleware wiring and audit emission land with the first real sensitive action_ (§32.2)
- [ ] **P0-27** Maker-checker / `approval_requests` scaffold for second-approver actions (§32.2)
- [x] **P0-28** `PreventSearchIndexing` middleware, aliased `noindex`, applied per-route so the public site and storefronts stay indexable (§34.2) — 3 tests

## P0.D Settings & configuration

- [~] **P0-29** `settings` migration, `SettingType` casting (decimals stay strings, money casts to `Money`), `SettingsRepository` with Redis cache and full invalidation on write (19 tests) — _migration not yet run_
- [ ] **P0-30** Admin settings UI shell with grouped sections and permission gating
- [ ] **P0-31** Encrypted-credential storage pattern for gateway / SMS / courier / website secrets (§26.4, §36)

## P0.E Design system & ERP shell (§33)

- [x] **P0-32** Design tokens in `resources/css/app.css`: warm-biased neutrals, brand accent, status system, credit/debit, light + dark (D6). _Brand orange is a placeholder pending the official hex_
- [x] **P0-33** `StatusPill` + `lib/status.ts` — five tones, every one carrying an icon and a required label, never colour alone (§33.9)
- [~] **P0-34** ERP layout shell: responsive sidebar, top header, global search slot, notification centre slot, account menu, breadcrumbs (§33.2). _Still the starter kit's shell. Sidebar entries are now permission-gated off a shared `permissions` prop — a short, explicit list of the abilities the nav reads, not the whole 161-permission set. First staff entry: KYC review_
- [~] **P0-35** `PageHeader` (title, description, quick actions) done; _breadcrumb + content region land with the ERP layout_
- [x] **P0-36** `DataTable` + `useTableQuery` + `TablePagination` + `ColumnVisibilityMenu`: URL-persisted search/sort/filters, server-side pagination, column visibility, bulk actions, card degradation on mobile, all four states (§33.6, §39)
- [x] **P0-37** UI state components: `TableSkeleton`, `EmptyState`, `ErrorState`, `PermissionDeniedState`, `OfflineState` over a shared `StateShell` (§33.10)
- [x] **P0-38** Form patterns: `FormField` (render-prop a11y wiring), `SubmitButton` (duplicate-submit prevention), `ConfirmDialog` (restates the action, not just "are you sure"), `FileField` (preview + progress, object URLs revoked) (§33.5)
- [~] **P0-39** Financial UI: `MoneyAmount` + `lib/money.ts` done — tabular, direction passed not inferred. _Balance breakdown card, fee/net summary and confirm dialog land with the wallet screens_ (§33.7)
- [ ] **P0-40** Accessibility baseline: contrast, keyboard nav, focus states, labels, semantic headings, touch targets (§33.9)
- [ ] **P0-41** Admin layout variant + role-aware navigation

## P0.F Tooling & CI

- [x] **P0-42** 24 Pest groups declared in `tests/Pest.php`, including the §43-mandated `concurrency`, `self-scope`, and `product-restrictions`
- [ ] **P0-43** Factories + seeders for foundational entities; volume seeder for query benchmarking
- [x] **P0-44** GitHub Actions rewritten with pinned `postgres:16-alpine` + `redis:7-alpine` services, PHP 8.5, migrations, then `composer ci:check`
- [x] **P0-45** PHPStan raised **7 → 8** (null safety). All 32 findings were starter-kit Teams/Settings/Dashboard code, captured in `phpstan-baseline.neon`; new code is held to level 8. Baseline should shrink as Teams becomes Accounts (P1-64) and must never grow
- [x] **P0-46** `CLAUDE.md` — Feriwala section appended _outside_ the Boost block so `boost:update` cannot overwrite it: non-negotiables, architecture-locked list, layout, environment invariants, UI rules, checks
- [ ] **P0-47** Global search across permitted entities in the ERP header (§33.2)
- [ ] **P0-48** Indexing convention + per-migration checklist covering the 17 index targets in §37 (unique slugs, emails, mobiles, referral codes, SKUs, order/payment/transaction refs, all status columns, domain/hosting expiry, sales dates, filtered report columns)

## P0.G Decision-driven additions (see [04-decisions.md](04-decisions.md))

- [x] **P0-49** i18n infrastructure: `Locale` enum, `SetLocale` middleware, `lang/en` + `lang/bn`, Inertia sharing with English fallback, `useTranslation()`, `LanguageSwitcher`, `PUT /locale` (D6)
- [~] **P0-50** **Visual direction sample — DELIVERED, awaiting approval.** All nine required pieces at [claude.ai/code/artifact/c42e5919](https://claude.ai/code/artifact/c42e5919-714d-4b8a-b3ff-2a09ff64e815) (D6). Four questions open on it: brand hex, primary-button colour, row density, Bangla coverage. **Screen-set build is gated on this.**
- [ ] **P0-51** Spatie scoping: platform roles vs account-staff roles kept separate; a staff role in one account cannot affect another; cache cleared on every role/permission change (D2)
- [ ] **P0-52** Laravel Horizon behind the `system.monitoring` permission, not publicly reachable (D3)
- [x] **P0-53** **Storefront API contract `v1` — APPROVED AND FROZEN** 2026-09-01, at [05-storefront-api-contract.md](05-storefront-api-contract.md) (D11). Implementation obligations it creates are listed in its §11 and folded into P3–P6 below.
- [ ] **P0-54** S3-compatible object storage config; verify no local-only persistent files (D10)
- [~] **P0-55** Docker stack: PostgreSQL 16, Redis 7, PHP 8.5, queue worker, scheduler, Mailpit (D5) — _`compose.yaml` written and version-pinned; blocked on Docker install (needs sudo)_

---

# Phase 1 — Identity, KYC, Packages, Activation

## P1.A Account model & lifecycle (§5, §6)

- [ ] **P1-1** Resolve teams/staff decision and migrate or remove starter-kit team scaffolding **⚠ Q1**
- [x] **P1-2** `users` extended and migrated: `public_id`, `status`, mobile + verification, DOB, gender, country, nationality, referral code + referrer, `activated_at`, locale, T&C/privacy timestamps, with the §37 indexes
- [x] **P1-3** `AccountStatus` — all 22 statuses from §5.3 with a guarded transition map. `ApprovalPending` is the **only** route into `Active` (§5.1, §44); KYC rejection allows resubmission (§7.3); low balance and package expiry both restore to `Active` (§24.3, §8.4). Helpers: `isActivated()`, `isOnboarding()`, `canTransact()`, `tone()`. 26 tests
- [x] **P1-4** `user_status_history` + `ChangeAccountStatus` — transition and history written in **one transaction**; internal and user-visible notes kept in separate columns (§7.3); append-only — 10 tests
- [x] **P1-5** `user_addresses` + `AddressType`, with `toSnapshot()` so an order keeps its own immutable copy
- [~] **P1-6** `CreateNewUser` extended for §5.2: mobile (required, unique), DOB, gender, country, nationality, and **validated** T&C + privacy acceptance recorded with timestamps. _Registration UI still to build_
- [x] **P1-7** `ReferralCode` + `ResolveReferrer`: unambiguous 8-char alphabet, random not sequential (so the user base cannot be enumerated), normalises lower-case/spaced input, and **only an Active account can refer** (§25.1). A wrong code never blocks registration — 8 tests
- [ ] **P1-8** Email verification flow (Fortify) wired to account status
- [x] **P1-9** Mobile OTP: `VerificationCodes` (hashed in Redis, constant-time compare, 5-attempt budget, 5-min TTL, 60s resend cooldown, identifier hashed into the key) + `SendMobileVerificationCode` / `VerifyMobile` with bilingual SMS. Verification never drags a further-along account backwards — 26 tests
- [x] **P1-10** Pre-activation access gate (§5.4). `EnsureAccountIsActivated` existed but was registered nowhere; now aliased as `activated` and applied to the authenticated ERP and settings groups. An **allow-list**, so a new route is shut by default — a test asserts no listed prefix matches nothing (`payment.` was dead while the real routes are `checkout.`), and unbuilt §5.4 areas are declared in a separate `PENDING_ROUTE_PREFIXES` so a placeholder is distinguishable from a typo. Administration is deliberately not exempt: a suspended staff member loses the panel with everything else — 8 tests
- [ ] **P1-11** Post-activation feature gate driven by package entitlements

## P1.B Authentication & account security (§6)

- [ ] **P1-12** Strong password policy + hashing config review
- [ ] **P1-13** Configurable session lifetime; Redis-backed sessions; secure cookies (§36)
- [ ] **P1-14** Login attempt limits + brute-force protection (Redis rate limiter)
- [ ] **P1-15** Suspicious login detection (new device/IP/geo) + security notification
- [ ] **P1-16** Device & session history UI; "log out other devices"
- [ ] **P1-17** Account lock/unlock with reason + audit
- [ ] **P1-18** Verify Fortify 2FA + passkeys meet §6; require 2FA for sensitive roles (§36)
- [ ] **P1-19** Password reset security review (token expiry, single use, notification)

## P1.C KYC (§7)

- [ ] **P1-20** `kyc_document_types` CRUD: required/optional, accepted formats, max size, instructions, active
- [ ] **P1-21** Package-specific and country-specific KYC requirement scoping (§7.2)
- [x] **P1-22** KYC form rendered from the configured types that apply to _this_ applicant (country + package). Each requirement **saves on its own**, so a rejected upload never costs the ones that were fine; rejection messages name the accepted formats and limit (§7.2) — 12 tests
- [x] **P1-23** `KycDocumentStore`: validates against the admin's per-type rules **before** touching disk, stores under a random name on a private `kyc` disk with `serve` off, encrypts at rest, records a SHA-256 checksum, and exposes **no** URL method at all (§7.5, §36) — 15 tests
- [x] **P1-24** `kyc_submissions` + `kyc_submission_fields` (encrypted, masked for display) + `kyc_documents`, with resubmission as a **new round** rather than an edit — so a review keeps describing the documents it was actually made on
- [x] **P1-25** `SubmitKyc` / `ReviewKyc` / `StartKycResubmission` + `KycDecision`. Rejection requires both a reason and applicant-facing feedback; requesting corrections requires feedback — enforced in the constructor, not by convention (§7.3). Reviewer UI: queue at `admin/kyc/index` (oldest first, waiting-days column, whitelisted sort) and `admin/kyc/show` with the decision form — 13 tests
- [x] **P1-26** `KycReview` append-only with the full §7.3 field set. Submission status, review row, account status and timestamp all move in **one transaction**
- [~] **P1-27** Submission history + review history views. _Admin side done — the review history renders on `admin/kyc/show`. The applicant's own history view is still to build_
- [x] **P1-28** Protected document delivery: `Erp\KycDocumentController` authorises, records the access, then streams from the private disk. **No signed URL** — a signed URL is a bearer token that outlives the check and can be forwarded, so the controller is the whole surface and re-authorises on every request (§7.5)
- [x] **P1-29** `kyc_document_accesses` — every read recorded before the file is returned, view distinguished from download, append-only (§7.5)
- [x] **P1-30** `disk`, `path` and `checksum` hidden from every array/JSON representation; field values encrypted and hidden, with `masked()` for list display (§7.5)
- [x] **P1-31** KYC deadline (§7.4). `KycDeadlines` holds the policy: `kyc.deadline_days`, `kyc.deadline_warning_days`, `kyc.overdue_restricts_active_account` — all opt-in, because inventing a window would start restricting real accounts on a number nobody agreed. The clock starts when the applicant can first act, not at registration. `SweepKycDeadlines` runs daily under `onOneServer` + `withoutOverlapping`: warns once inside the window, then applies the four §7.4 consequences once — activation stays blocked, an active account is restricted **only if configured**, mail and SMS go to the owner, and the audit entry records a `system` actor rather than naming a person. A round awaiting review is our delay, not the applicant's, and is skipped — 22 tests
- [ ] **P1-63** Admin action: **request a future KYC update** from an already-active user, with its own deadline (§7.2)

## P1.H Staff management & tax engine (decision-driven)

- [x] **P1-64** Team → `BusinessAccount`, including the identity/commercial split (D23). `business_accounts` + `business_account_members`; `status`, `activated_at`, `approval_pending_at`, KYC, payments, subscriptions, the current-package pointer and the status history all moved off `users`, which keeps `identity_status`. One owner, one account, one membership per person — unique indexes, not convention
- [x] **P1-78** Application layer converted to D23. Two gates: `EnsureIdentityHasPlatformAccess` global (a suspended login loses every panel, and it signs out rather than redirecting so the public site still renders), `EnsureBusinessAccountIsActivated` on business ERP routes only. Administration is identity plus permission — **no `admin.*` bypass**. Found three real bugs on the way: admin routes were nested inside the commercial gate, §25.1's referrer check read the wrong subject, and the KYC policies matched `user_id` instead of account membership — 642 tests
- [ ] **P1-65** Remove `{current_team}` from ERP URLs; resolve account context from session (D1)
- [ ] **P1-66** Staff invitation + own-credential login as sub-users of one Account (D1)
- [ ] **P1-67** Package **staff limit** enforcement on invitation and activation (D1, §8.1)
- [ ] **P1-68** Team concept fully invisible to a solo user with no staff (D1)
- [ ] **P1-69** Tax engine: admin-configurable rates, exclusive default, inclusive supported (D19)
- [ ] **P1-70** Product-specific, category-specific, and fee-specific tax rules with effective dates (D19)
- [ ] **P1-71** Tax-exempt configuration (D19)
- [ ] **P1-72** Tax breakdown on invoices + separate tax ledger and report fields (D19)
- [ ] **P1-73** Fee refundability config: registration non-refundable by default; package fee refundable pre-activation with admin approval; decisions to ledger + audit (D17)
- [ ] **P1-74** Downgrade guard: show published count vs new limit, let the user pick what to unpublish, never auto-remove; permissioned override with recorded reason (D16)

## P1.D Packages (§8)

- [ ] **P1-32** `packages` CRUD — all §8.1 fields — create/edit/activate/deactivate/archive, N packages
- [x] **P1-33** `PackageFeature` enum (15 typed entitlements) + `package_features`. Facilities default **off** and limits default to **0**, so a misconfigured package under-delivers visibly; `null` means unlimited and is kept distinct from `0`
- [x] **P1-34** `package_charges` (setup, maintenance, domain, hosting) with recurrence
- [x] **P1-35** Package selection and comparison. Each card leads with the **total payable today**, not the package fee alone — the registration fee is charged alongside (§5.1), and showing only the package price would surprise the user at checkout. Choosing again supersedes the earlier choice rather than stacking unpaid subscriptions
- [ ] **P1-36** `user_packages` subscription record with validity, expiry, renewal state, source
- [x] **P1-37** `Entitlements` — the single place package limits are read. No package grants nothing (limits read `0`, never `null`); `RenewalDue` and `GracePeriod` keep granting so a late invoice does not strand live orders (§8.4) — 20 tests
- [ ] **P1-38** Package renewal flow + renewal fee + frequency + grace period (§8.2)
- [ ] **P1-39** Package upgrade/downgrade: eligibility, proration, extra deposit, minimum-balance change, feature transition, effective date (§8.3)
- [ ] **P1-40** Manual + promotional package assignment by admin (§8.3)
- [ ] **P1-41** Package cancellation + expiry handling: notifications, feature restriction, publishing pause, website grace → suspension, limited payment/renewal access, restoration after renewal (§8.4)
- [ ] **P1-42** Package invoice + package payment history (§8.2)

## P1.E Combined registration + package payment (§9)

- [ ] **P1-43** `fee_rules`: global registration fee + package-specific override, effective dates (§9)
- [x] **P1-44** `CalculateActivationQuote` — server-side, with a fixed order of operations: fees → discount → tax on the discounted taxable amount → untaxed deposit → gateway charge on what is actually transacted. Discount capped at the fees so a total can never go negative (§9, §36.1) — 18 tests
- [x] **P1-45** `payments` + `payment_allocations` + `RecordPaymentFromQuote`. Payment and allocations written in one transaction; duplicate prevention by **unique index**, not check-then-insert, with the loser returning the winner's row; `PaymentStatus` refuses to let settled money un-arrive or a failed payment revive (§5.1, §9, §26.4) — 17 tests
- [ ] **P1-46** Coupon + promotional discount application with effective dates (§9)
- [ ] **P1-47** Tax rules application (§9)
- [ ] **P1-48** Payment deadline configuration + expiry handling (§9)
- [x] **P1-49** Checkout with the full itemised breakdown, gateway choice, and the amount restated on the button. Recalculated server-side on view **and** on pay — a submitted total is ignored entirely (§9, §36.1). Gateway return + IPN endpoint wired, CSRF-exempt on signature — 15 tests
- [ ] **P1-50** Activation invoice with registration fee and package fee as separate lines (§5.1)

## P1.F First payment gateway + minimal SMS

- [~] **P1-51** `PaymentGateway` contract + `PaymentGatewayManager` + `config/payment.php` listing all eight gateways. A gateway is offered only when enabled **and** credentialled. A decline is a result; only genuine faults throw `GatewayUnavailable` (§26.4). _Admin credential UI still to build_
- [~] **P1-52** SSLCommerz: initiate, callback, **IPN signature verification**, server-side validation. Credentials read from encrypted settings with separate sandbox/live keys — never from code (D7) — 24 tests. _Wiring into the payment flow next_
- [x] **P1-53** `SettlePayment` — the single path a payment becomes settled. Server-side verification always, distributed lock + `lockForUpdate` re-check, idempotent replay without re-asking the gateway, amount mismatch refused and logged critical, gateway outage throws and leaves the payment retryable (§26.4, §36.1) — 14 tests
- [ ] **P1-54** `payment_logs` with secret redaction (§42)
- [~] **P1-55** `SmsProvider` contract + `SmsMessage`/`SmsResult` + `LogSmsProvider` + `SmsServiceProvider` resolver. Segment counting knows Bangla is UCS-2 (70 chars, not 160), so a "one message" template cannot quietly cost three. _Real providers land in P8_
- [ ] **P1-56** Queue-based SMS dispatch from the start (§30.2)

## P1.G Activation & onboarding UX

- [x] **P1-57** `ActivationRequirements` — all three §5.1 conditions checked in one place, reporting **every** unmet reason rather than the first. Queue at `admin/activations/index`, keyed on the conditions themselves rather than on `ApprovalPending`, so an account that is genuinely ready cannot hide behind a status nobody moved. `admin/activations/show` shows each condition with its evidence — 22 tests
- [x] **P1-75** Activation review outcomes as three explicit actions (D22). `ActivateAccount`, `RequestKycResubmission`, `SuspendAccount` — each with its own validation, notification, audit entry and permission. **No generic Reject**; suspension uses `account.reject`, is reversible, and is never a substitute for closure (D18)
- [x] **P1-76** `EvaluateActivationReadiness` + `approval_pending_at` (D22). Runs on every requirement change — KYC decision, payment settlement — moving an account to `ApprovalPending` and stamping when it became ready, or clearing the stamp when a requirement reverses. Idempotent under a per-account lock. `ApprovalPending` is the queue's canonical state; the condition query survives only as a compatibility net — 19 tests
- [x] **P1-77** Concurrency: every decision runs in one transaction with `lockForUpdate`, requirements re-checked **inside** it. Two reviewers deciding at once resolve to one winner and the loser writes nothing
- [~] **P1-58** `ActivateAccount` — the only route into `Active`, re-checking preconditions rather than trusting the queue. Account status and subscription go live in one transaction; the term starts at **activation, not purchase**, so a slow approval does not eat paid days — 15 tests. _Wallet creation and referral qualification hook land with P2/P7_
- [x] **P1-59** `OnboardingStep` + `ActivationStepper` — six steps collapsing the 22 statuses into what a person needs to know. State carried by shape and label, never colour alone (§33.4, §33.9)
- [x] **P1-60** `OnboardingProgress` — current step, done/upcoming/blocked states, **one** required action, blocked reason, and reviewer feedback. Assembled in one place so stepper, dashboard and notifications cannot disagree (§33.4) — 14 tests
- [x] **P1-61** `/onboarding` status screen, reachable throughout and after activation, no-indexed. A response-level test asserts the reviewer's internal note never reaches the browser (§7.3, §33.3)
- [ ] **P1-62** Tests: registration, verification, KYC submission + review, package selection, combined fee calculation, payment verification, activation (§43)

---

# Phase 2 — Money Core

## P2.A Wallet & ledger (§23)

- [ ] **P2-1** `wallets` with all balance buckets: total, required deposit, reserved, usable, available-withdrawal, pending, hold, COD receivable (§23, §24.2)
- [ ] **P2-2** `ledger_entries` — full column set from §23.2 including balance before/after and all related-entity FKs
- [ ] **P2-3** Immutability enforcement: model guard + PostgreSQL trigger blocking UPDATE/DELETE (§23.2)
- [ ] **P2-4** Adjustment / reversal / corrective entry types with links to the entry they correct (§23.2)
- [ ] **P2-5** `WalletService`: credit, debit, hold, release, reserve — transactional with `lockForUpdate` (§36.1)
- [ ] **P2-6** All 26 transaction types from §23.1 as a typed enum with handlers
- [ ] **P2-7** `wallet_transactions` status lifecycle — all 12 statuses from §23.3
- [ ] **P2-8** Wallet statement UI with bucket breakdown, filters, export (§33.7)
- [ ] **P2-9** Ledger integrity job: re-derive balances, assert match, alert on mismatch (§28.1)
- [ ] **P2-10** Concurrency tests: parallel debits cannot overdraw; parallel credits do not lose writes (§43)

## P2.B Deposits & minimum balance (§24)

- [ ] **P2-11** `deposit_rules` with scope precedence (global/package/user/website/domain/hosting) + effective dates (§24.1)
- [ ] **P2-12** `deposit_rule_changes` audit trail (previous, new, actor, reason, effective date)
- [ ] **P2-13** Required-deposit enforcement at activation and at website setup (§24)
- [ ] **P2-14** Minimum-balance reservation — reserved balance excluded from withdrawal (§24.2, §43)
- [ ] **P2-15** Low + critical balance thresholds and grace period tracking (§24.1)
- [ ] **P2-16** Graded low-balance actions: notify → show required top-up → grace → restrict chargeable services → pause website setup/renewal → disable website → restrict account (§24.3)
- [ ] **P2-17** Automatic service restoration after verified top-up (§24.3, §43)
- [ ] **P2-18** Deposit refundability config: full / partial / non-refundable / reserved until cancellation / usable for charges / withdrawable after liabilities (§24.4)
- [ ] **P2-19** Wallet top-up flow (any gateway) with allocation to deposit vs usable balance
- [ ] **P2-20** Scheduled balance-check job + low-balance notifications (§41)

## P2.C Payment gateways (§26)

- [ ] **P2-21** EPS driver
- [ ] **P2-22** SSLCommerz driver
- [ ] **P2-23** SurjoPay driver
- [ ] **P2-24** AmarPay driver
- [ ] **P2-25** bKash driver
- [ ] **P2-26** Nagad driver
- [ ] **P2-27** Stripe driver
- [ ] **P2-28** PayPal driver
- [ ] **P2-29** Per-gateway sandbox/live config, enable/disable, credential encryption (§26.4)
- [ ] **P2-30** Unified callback + webhook handling with signature verification and fast queued processing (§26.4)
- [ ] **P2-31** Refund + partial refund + payment reversal where supported (§26.3, §26.4)
- [ ] **P2-32** Payment receipts + transaction logs + currency handling (§26.4)
- [ ] **P2-33** Payment purposes wired: registration, package, combined activation, renewal, upgrade, wholesale order, website order, wallet deposit/top-up, website setup, domain, hosting, maintenance (§26.3)
- [ ] **P2-34** Gateway reconciliation job + mismatch alerts (§28.1)
- [ ] **P2-35** Tests: payment callbacks, webhooks, duplicate payment prevention, idempotency (§43)
- [ ] **P2-36** `currency_code` column on every financial table, default `BDT`; ledger multi-currency-ready without exchange-rate accounting (D4)
- [ ] **P2-37** Record original currency, original amount, and settlement info for international gateways — never silently rewrite the BDT base ledger (D4)
- [ ] **P2-38** Stripe and PayPal implemented against the driver contract but **shipped disabled** until merchant accounts exist (D4)

---

# Phase 3 — Central Catalog & Inventory

## P3.A Catalog (§11)

- [ ] **P3-1** `categories` (nested, slug, image, SEO, order, enable/disable) (§11.3)
- [ ] **P3-2** `brands` (slug, logo, active) (§11.3)
- [ ] **P3-3** `products` with the full §11.1 field set
- [ ] **P3-4** `product_attributes` + values + `product_variants`
- [ ] **P3-5** `product_media` (images, videos, alt text, ordering)
- [ ] **P3-6** `product_price_tiers` — quantity-based wholesale pricing (§11.1)
- [ ] **P3-7** Min/max order quantity + suggested/min/max selling price fields (§11.1)
- [ ] **P3-8** Product status enum — all 11 states from §11.2 — with transitions
- [ ] **P3-9** `product_package_eligibility` + `product_user_eligibility` (§11.1)
- [ ] **P3-10** Dropshipping-enabled and wholesale-enabled flags (§11.1)
- [ ] **P3-11** Related products + featured status (§11.1)
- [ ] **P3-12** SEO metadata + product schema fields for partner websites (§11.1, §34.3)
- [ ] **P3-13** Admin catalog UI: list, filters, bulk actions, media manager, variation builder
- [ ] **P3-14** Category/brand reorder + enable/disable + product assignment (§11.3)

## P3.B Product creation restrictions (§12) — hard requirement

- [ ] **P3-15** Policies denying product/category/brand/variation creation to non-authorized users
- [ ] **P3-16** UI: no create/import affordances rendered for regular users
- [ ] **P3-17** Request validation rejecting central SKU / stock / wholesale price / locked-field modification
- [ ] **P3-18** API authorization mirroring the same rules (§12)
- [ ] **P3-19** Database-level guards where appropriate (§12)
- [ ] **P3-20** Block external product import paths entirely (§12)
- [ ] **P3-21** **`product-restrictions` test group** — assert rejection at UI, controller, policy, API, and validation layers (§12, §43)

## P3.C Inventory (§19)

- [ ] **P3-22** `warehouses` + `stock_items` with available / reserved / processing / sold / returned / damaged (§19)
- [ ] **P3-23** `stock_movements` full history with before/after and reason (§19)
- [ ] **P3-24** `stock_adjustments` — admin/authorized only, with reason + audit (§19)
- [ ] **P3-25** `stock_reservations` — transaction-safe reserve/release with `SELECT … FOR UPDATE` (§19.1, §36.1)
- [ ] **P3-26** Reservation expiry + release job
- [ ] **P3-27** Central stock shared across all websites; per-website availability view (§19.1)
- [ ] **P3-28** Out-of-stock protection + overselling prevention (§19)
- [ ] **P3-29** Low-stock alerts + notifications (§19)
- [ ] **P3-30** User-allocated stock support where applicable (§19)
- [ ] **P3-31** **Concurrency test:** parallel orders on last unit — exactly one succeeds (§43)

---

# Phase 4 — ERP Wholesale Browsing, Cart & Checkout

- [ ] **P4-1** ERP product browsing: search, category/brand/stock/price filters, wholesale eligibility filter (§13)
- [ ] **P4-2** Product detail view: variations, wholesale price, tiered pricing, MOQ, stock, related products (§13)
- [ ] **P4-3** `carts` + `cart_items` scoped to the authenticated user (§14)
- [ ] **P4-4** Add / update quantity / remove with min, max, and stock validation (§14)
- [ ] **P4-5** Server-side tiered price calculation (§14)
- [ ] **P4-6** Coupon + discount application (§14)
- [ ] **P4-7** Billing address, shipping address, delivery charge, tax (§14)
- [ ] **P4-8** Payment method selection + order summary + checkout confirmation (§14)
- [ ] **P4-9** Payment initiation → order creation with `source = erp_wholesale` (§14, §18.1)
- [ ] **P4-10** Stock reservation on order creation (§19.1)
- [ ] **P4-11** Invoice generation (§14)
- [ ] **P4-12** Wholesale order tracking for the user (§10.2)
- [ ] **P4-13** Tests: browsing, cart, checkout, MOQ/stock validation, tiered pricing (§43)
- [ ] **P4-14** Optional **intended resale channel** field on wholesale orders — reporting only, no enforcement (D21)

---

# Phase 5 — Dropshipping Selection & Dedicated Websites

## P5.A Product selection & publishing (§15)

- [ ] **P5-1** Eligible-product browsing with search/filter, scoped by package eligibility (§15)
- [ ] **P5-2** `website_products` selection + publish / unpublish with publication status (§15)
- [ ] **P5-3** Package publish-limit enforcement (§15, §8.1)
- [ ] **P5-4** Website-specific category placement, display order, featured selection (§15)
- [ ] **P5-5** `website_product_price_rules`: admin controls whether user pricing is allowed, min/max/suggested price, allowed margin, locked vs editable fields (§15.1)
- [ ] **P5-6** User-settable website price, promotional price, promo title, marketing description — validated against admin bounds (§15.1)
- [ ] **P5-7** Synchronization status surface per product (§15)

## P5.B Website provisioning & lifecycle (§16)

- [ ] **P5-8** `websites` with all §16.2 setup fields
- [ ] **P5-9** Website status enum — all 14 states from §16.4 — with transitions + history
- [ ] **P5-10** Setup charge, domain charge, hosting charge billing via wallet/payment (§16.2, §24)
- [ ] **P5-11** `website_domains` + `website_hostings` with expiry tracking and renewal reminders (§41)
- [ ] **P5-12** Website management from ERP: info, logo, banner, contact, colours, branding, theme (§16.3)
- [ ] **P5-13** Website-scoped orders, customers, coupons, discounts, shipping settings, reports (§16.3)
- [ ] **P5-14** **Block product creation through website management** (§16.3)
- [ ] **P5-15** Grace period + suspension + maintenance mode handling (§16.4, §24.3)

> Storefront features (§16.1) are **not** a sub-task of provisioning — they are a whole
> customer-facing application. See **P5.D** below.

## P5.C API & webhook integration (§17)

- [ ] **P5-17** `website_credentials`: API key + encrypted secret, permission scopes, rotation, revocation (§17.3)
- [ ] **P5-18** Signed-request authentication for website ↔ ERP calls (§17.3)
- [ ] **P5-19** Per-credential rate limiting via Redis (§17.3)
- [ ] **P5-20** Webhook signature verification both directions (§17.3)
- [ ] **P5-21** Idempotency + duplicate-order prevention on inbound webhooks (§17.3, §43)
- [ ] **P5-22** Outbound sync jobs: products, images, pricing, availability, stock, categories (§17.1)
- [ ] **P5-23** Inbound sync: website orders, order items, customers, payments, shipping, courier status, returns, refunds, cancellations (§17.1)
- [ ] **P5-24** Sales, commission, and wallet transaction propagation where applicable (§17.1)
- [ ] **P5-25** Sync modes: real-time, near-real-time, scheduled, manual retry by authorized user (§17.2)
- [ ] **P5-26** Retry with backoff + `sync_queue_failures` + manual retry UI (§17.3)
- [ ] **P5-27** `api_logs` + `webhook_logs` with redaction (§17.3, §42)
- [ ] **P5-28** Connection monitoring + health status + last-sync display (§16.2, §17.3)
- [ ] **P5-29** **Isolation test:** one website's credentials cannot access another website's or user's data (§17.3)
- [ ] **P5-30** Tests: product sync, website cart/checkout, order sync, duplicate prevention (§43)

## P5.D Customer-facing storefront application (§16.1)

§16.1 lists 20 storefront features. This is a second, public-facing e-commerce application —
sized accordingly rather than as one line item under provisioning.

- [ ] **P5-16** Storefront application scaffold + build/deploy pipeline **⚠ Q11**
- [ ] **P5-31** Multi-tenant routing: resolve website context from domain/subdomain, 404 on unknown host
- [ ] **P5-32** Per-website theming: logo, banner, colours, branding, contact info pulled from ERP (§16.3)
- [ ] **P5-33** Homepage (§16.1)
- [ ] **P5-34** Product listing + category pages with server-side pagination (§16.1)
- [ ] **P5-35** Product search (§16.1)
- [ ] **P5-36** Product filtering (§16.1)
- [ ] **P5-37** Product detail page: variations, media, price, availability (§16.1)
- [ ] **P5-38** Cart (§16.1)
- [ ] **P5-39** Checkout: addresses, shipping method, payment method, summary (§16.1)
- [ ] **P5-40** Guest checkout where enabled (§16.1)
- [ ] **P5-41** Customer account: register, login, profile, order history (§16.1)
- [ ] **P5-42** Website-scoped coupons + discounts (§16.1, §16.3)
- [ ] **P5-43** Shipping methods + rate calculation (§16.1)
- [ ] **P5-44** Payment methods for website orders routed through **Feriwala-controlled gateways** — partner gateway credentials are explicitly out of scope for v1 (§16.1, §26.3, D12)
- [ ] **P5-45** Order tracking page (§16.1)
- [ ] **P5-46** Return request + refund request flows (§16.1)
- [ ] **P5-47** Contact information, legal pages, custom 404 (§16.1, §34.3)
- [ ] **P5-48** **Package-gated feature toggles** — "depending on Package, the Website may include…" (§16.1)
- [ ] **P5-49** Storefront responsive design, performance budget, and the five UI states (§33.8, §33.10, §39)
- [ ] **P5-50** Storefront → ERP submission: orders, customers, payments via P5.C inbound API (§17.1)

> Storefront SEO (slugs, metadata, canonical, OG, product/organization/breadcrumb schema,
> sitemap, robots, 301s) is tracked at **P10-19**, not duplicated here.

---

# Phase 6 — Order Management, Fulfillment, Courier

## P6.A Unified OMS (§18)

- [ ] **P6-1** `orders` with all mandatory fields from §18: ref, source, user, website, customer, products, payment, fulfillment, courier, delivery, financial breakdown, notes
- [ ] **P6-2** All six order sources: ERP wholesale, dedicated website, manual entry, API, admin entry, future external channel (§18.1)
- [ ] **P6-3** `order_statuses` seeded with the full §18.2 system set, `is_system` undeletable
- [ ] **P6-4** Admin-created **custom order statuses** (§18.2)
- [ ] **P6-5** `order_status_transitions` guarded transition map
- [ ] **P6-6** `order_status_history` with previous, new, actor, at, reason, internal note, user-visible note, notification status (§18.3)
- [ ] **P6-7** `order_notes` (internal vs customer-visible) + full audit history (§18)
- [ ] **P6-8** User order dashboard, strictly self-scoped, with permitted actions from §18.4
- [ ] **P6-9** Manual order creation where permitted (§18.4)
- [ ] **P6-10** Order confirmation, customer verification, cancellation flows (§18.4)
- [ ] **P6-11** Invoice, packing slip, shipping label generation (§18.4)
- [ ] **P6-12** Return request + refund request flows with approval and inspection (§18.2, §18.4)
- [ ] **P6-13** Self-scoped order export (§18.4)
- [ ] **P6-14** Admin order dashboard with every breakdown in §18.5 (status, type, website, user, product, courier, payment method, date, fulfillment, payment, COD)
- [ ] **P6-15** Stock lifecycle wiring: reserve on new, decrement on complete, release on cancel, update on inspected return (§19.1)
- [ ] **P6-16** Tests: order status workflow, self-scoped access, duplicate order prevention (§43)

## P6.B Fulfillment (§20)

- [ ] **P6-17** Fulfillment request + warehouse assignment
- [ ] **P6-18** Picking + packing workflow with staff assignment
- [ ] **P6-19** Packaging materials + fulfillment charge → wallet debit
- [ ] **P6-20** Fulfillment status + notes + history
- [ ] **P6-21** Packing slip + shipping label documents
- [ ] **P6-22** User-wise fulfillment reports

## P6.C Courier (§21)

- [ ] **P6-23** `CourierProvider` contract + manager + `courier_providers` with encrypted credentials
- [ ] **P6-24** Manual courier workflow: courier name, tracking number, delivery charge, COD amount, order status, delivery status, return charge, notes, manual reconciliation (D8)
- [ ] **P6-25** Courier booking + tracking number + delivery status polling/webhooks
- [ ] **P6-26** Delivery charge, COD amount, return charge capture
- [ ] **P6-27** Delivery failure handling
- [ ] **P6-28** Courier reconciliation + courier-wise reports
- [ ] **P6-29** Verify a second provider can be added without core changes (§21)
- [ ] **P6-30** Steadfast driver (D8)
- [ ] **P6-31** Pathao driver (D8)
- [ ] **P6-32** Warehouse selection: admin-configurable priority, default warehouse first, fall through on insufficient stock, authorized manual override, **every assignment and reassignment audited** (D15)
- [ ] **P6-33** Source-based fulfillment rules: different rules, charges, or approval requirements per order source (D13)

---

# Phase 7 — Commission, Referral, Withdrawal, Settlement

## P7.A Commission (§22)

- [ ] **P7-1** `commission_rules` across all six scopes: global, package, user, product, category, campaign (§22.1)
- [ ] **P7-2** Fixed and percentage types, calculation base, effective/expiry dates, maximum commission (§22.1)
- [ ] **P7-3** Priority resolution when multiple rules match (§22.1)
- [ ] **P7-4** `commission_rule_changes` history (§22.1)
- [ ] **P7-5** Eligibility engine: successful payment, delivered, completed, return period elapsed, verified sale, not cancelled, not refunded, plus configured conditions (§22.2)
- [ ] **P7-6** Commission accrual job → ledger credit (§22)
- [ ] **P7-7** Commission status lifecycle — all 8 states from §22.3
- [ ] **P7-8** **Reversal** on cancellation / return / refund with ledger reversal entry (§22.2)
- [ ] **P7-9** Tests: calculation across scopes, priority resolution, reversal (§43)
- [ ] **P7-39** User sales & earnings dashboard: sales, earnings, commissions/margins — the §10.1 view the user actually looks at
- [ ] **P7-40** **Retail margin as a commission calculation base** — derived from the permitted selling price, settled through Feriwala, credited to the wallet. One earnings engine, several calculation bases (D12)
- [ ] **P7-41** Ledger types `referee_reward_credit` / `referrer_reward_credit`; **no independent joining bonus** (D14)

## P7.B Referral (§25)

- [ ] **P7-10** Unique referral code + referral link + registration URL per active user (§25.1)
- [ ] **P7-11** `referrals` — single level only, unlimited width, **no downline logic anywhere** (§25.1)
- [ ] **P7-12** `referral_plans` with the full §25.4 configuration set
- [ ] **P7-13** Qualification engine: registration, KYC submitted, package selected, combined payment complete, deposits met, KYC approved, account activated, holding period elapsed, plan conditions met (§25.2)
- [ ] **P7-14** Rewards for **both** referrer and new user; fixed / percentage / package-based / plan-based / campaign-based (§25.3)
- [ ] **P7-15** Reward release to wallet after holding period (§25.4)
- [ ] **P7-16** Reversal rules on failed/cancelled/refunded/reversed/fraudulent payments (§25.5)
- [ ] **P7-17** Fraud prevention: self-referral block, duplicate reward block, duplicate account signals, no multi-level, no referrer replacement after qualification (§25.5)
- [ ] **P7-18** Referral dashboard: code, link, referred users, qualification state, rewards
- [ ] **P7-19** Tests: unlimited direct referrals, qualification, both rewards, reversal, self-referral prevention, duplicate reward prevention (§43)

## P7.C Withdrawal (§27)

- [ ] **P7-20** `withdrawal_methods` (bank, bKash, Nagad, admin-defined) with required-field schemas (§27.1)
- [ ] **P7-21** `user_withdrawal_accounts` with verification
- [ ] **P7-22** **Default withdrawal rules** — all §27.2 fields
- [ ] **P7-23** **User-specific withdrawal rules** — all §27.3 fields
- [ ] **P7-24** Override resolver: valid user-specific rule beats the corresponding default, field by field (§27.3, §44)
- [ ] **P7-25** `withdrawal_rule_changes`: previous value, new value, changed_by, reason, effective date, expiry date, audit log (§27.3)
- [ ] **P7-26** Request flow with available-balance and reserved-minimum-balance display (§27.1)
- [ ] **P7-27** Eligibility checks: KYC, active package, minimum wallet balance, eligible balance, frequency and daily/weekly/monthly limits (§27.2)
- [ ] **P7-28** Approval workflow: review, verify, approve/reject, release payment, attach proof/reference, mark paid (§27.4)
- [ ] **P7-29** Withdrawal status lifecycle — all 9 states from §27.5 — with history
- [ ] **P7-30** Processing fee calculation + ledger entries for gross, fee, net
- [ ] **P7-31** Tests: default rules, user-specific override, reserved balance protection, approval flow (§43)

## P7.D COD & settlement (§28)

- [ ] **P7-32** `cod_collections`: COD amount, courier-collected amount, collection date, courier charge, return charge, adjustment, net (§28)
- [ ] **P7-33** Separate tracking of online vs COD payments (§28)
- [ ] **P7-34** `settlements` + items: user payable, platform receivable, settlement status and reference (§28)
- [ ] **P7-35** Scheduled COD settlement checks (§41)
- [ ] **P7-36** Reconciliation across all 12 areas in §28.1
- [ ] **P7-37** Mismatch → system alert + reconciliation issue + admin notification + report (§28.1)
- [ ] **P7-38** Tests: COD settlement, financial reconciliation (§43)

---

# Phase 8 — Notifications & SMS

## P8.A Dashboard notifications (§29)

- [ ] **P8-1** Notification centre UI: unread count, list, mark read, filters, deep links (§33.2)
- [ ] **P8-2** `notification_events` catalogue covering all 32 events in §29
- [ ] **P8-3** Wire every domain event to its notification
- [ ] **P8-4** Notification preferences: users control marketing/informational only. Security, login, payment, KYC, account status, package expiry, wallet warnings, withdrawal, order-critical, domain/hosting expiry, website suspension, and legal notices are **non-optional** (D20)
- [ ] **P8-5** Notification history view (§31.2)

## P8.B SMS (§30)

- [ ] **P8-6** BulkSMSBD driver
- [ ] **P8-7** Nova SMS driver
- [ ] **P8-8** SSLCommerz SMS driver
- [ ] **P8-9** Twilio driver
- [ ] **P8-10** Provider selection, priority, credentials, enable/disable (§30.2)
- [ ] **P8-11** Bangla + English event templates with variables (§30.2)
- [ ] **P8-12** Queue-based delivery — never inline (§30.2)
- [ ] **P8-13** Delivery status, failed SMS logs, retry (§30.2)
- [ ] **P8-14** Provider balance + cost tracking where supported (§30.2)
- [ ] **P8-15** Enable/disable at global / provider / event / account-status / package / user level (§30)
- [ ] **P8-16** Package SMS quota enforcement (§8.1)
- [ ] **P8-17** SMS history + SMS report (§30.2)
- [ ] **P8-18** Verify a fifth provider can be added without core changes (§30.1)

---

# Phase 9 — Reports & Analytics

- [ ] **P9-1** Report framework: definition, filters, permissions, pagination, totals, export pipeline
- [ ] **P9-2** Shared filter set: date, status, product, category, package, user, website, payment method, search, sort (§31.3)
- [ ] **P9-3** CSV / Excel / PDF export via queued jobs + `report_exports` with expiring download links (§31.3, §39)
- [ ] **P9-4** Printable views (§31.3)
- [ ] **P9-5** Account & KYC reports: registration, KYC, active, inactive, package-wise users (§31.1)
- [ ] **P9-6** Package reports: purchase, renewal, expiry (§31.1)
- [ ] **P9-7** Fee reports: registration fee, package fee, combined activation payment (§31.1)
- [ ] **P9-8** Wallet reports: deposit, minimum balance, low balance, top-up, reserved balance, wallet balance, wallet transaction, financial ledger (§31.1)
- [ ] **P9-9** Website reports: setup, dedicated website, domain renewal, hosting renewal (§31.1)
- [ ] **P9-10** Product reports: product, selected product, published product, product sales (§31.1)
- [ ] **P9-11** Order reports: ERP wholesale, dropshipping, website, source, status (§31.1)
- [ ] **P9-12** Inventory reports: inventory, stock movement (§31.1)
- [ ] **P9-13** Operations reports: fulfillment, courier, COD (§31.1)
- [ ] **P9-14** Payment reports: payment, gateway-wise transaction, refund (§31.1)
- [ ] **P9-15** Earnings reports: commission, referral, referral reward (§31.1)
- [ ] **P9-16** Payout reports: withdrawal, user-specific withdrawal rule, settlement (§31.1)
- [ ] **P9-17** Finance reports: financial adjustment, reconciliation, revenue (§31.1)
- [ ] **P9-18** System reports: SMS delivery, API usage, API failure, webhook failure, audit, backup, system activity (§31.1)
- [ ] **P9-19** All 21 self-scoped user reports from §31.2
- [ ] **P9-20** Permission-based report visibility (§31.3)
- [ ] **P9-21** **Cross-user access test group:** URL manipulation, API request, export, modified parameters — all must fail (§31.3)
- [ ] **P9-22** Scheduled reports (§41)
- [ ] **P9-23** Query performance pass with `EXPLAIN ANALYZE` on volume data; add indexes only where measured (§37)

---

# Phase 10 — CMS, Landing Page, SEO

## P10.A CMS (§4)

- [ ] **P10-1** `pages` + `page_sections` + section type registry (§4.2)
- [ ] **P10-2** Section CRUD: create, edit, reorder, enable/disable (§4.2)
- [ ] **P10-3** Draft / published status + scheduled publication (§4.2)
- [ ] **P10-4** Content editing: text, images, videos, buttons/links, colours (§4.2)
- [ ] **P10-5** Desktop / mobile visibility toggles (§4.2)
- [ ] **P10-6** Preview before publication (§4.2)
- [ ] **P10-7** `page_revisions` with restore (§4.2)
- [ ] **P10-8** Role-based CMS access (§4.2)
- [ ] **P10-9** `menus` + `menu_items` for header and footer navigation (§4.1)
- [ ] **P10-10** Media library with alt text and optimized variants (§4.2, §39)

## P10.B Public site (§4.1, §4.3)

- [ ] **P10-11** Landing sections: header, nav, hero, about, benefits, dropshipping info, wholesale info, package previews, how it works, features, statistics, testimonials, FAQ, contact, CTA buttons, legal links, footer, social links, custom sections (§4.1)
- [ ] **P10-12** Public pages: home, about, packages, how it works, FAQ, contact, terms, privacy, refund policy, login, registration, password reset, plus admin-created pages (§4.3)
- [ ] **P10-13** **Verify no product catalog, pricing, cart, checkout, or order placement is publicly reachable** (§4, §44)
- [ ] **P10-14** Public site performance: lazy loading, responsive images, minification, code splitting (§39)

## P10.C SEO (§34)

- [ ] **P10-15** Landing SEO: friendly URLs, meta title/description, canonical, Open Graph, social image, organization schema, FAQ schema (§34.1)
- [ ] **P10-16** XML sitemap generation (scheduled) + robots.txt (§34.1, §41)
- [ ] **P10-17** Image alt text enforcement + 301 `redirects` management (§34.1)
- [ ] **P10-18** ERP privacy: no-index directives, no sensitive data in URLs, no DB IDs in public URLs (§34.2)
- [ ] **P10-19** Partner website SEO: product/category slugs, metadata, canonical, Open Graph, product + organization + breadcrumb schema, sitemap, robots, alt text, 301s, custom 404 (§34.3)
- [ ] **P10-20** Tests: SEO slugs, ERP no-index rules (§43)

---

# Phase 11 — Security, Performance, Scalability, Backup, Monitoring

## P11.A Backup & recovery (§35)

- [ ] **P11-1** Weekly automatic encrypted database backup (§35.1, §41)
- [ ] **P11-2** Retention policy + secure storage + optional off-server/cloud target (§35.1)
- [ ] **P11-3** Backup success/failure logs + failure notifications (§35.1)
- [ ] **P11-4** Restore testing procedure + verification (§35.1, §35.3)
- [ ] **P11-5** Manual backup: permission check, password confirmation, 2FA, audit log, rate limit, scope restriction (§35.2)
- [ ] **P11-6** **Regular users cannot access platform backups** (§35.2)
- [ ] **P11-7** Documented recovery procedures: database, application, media, website data, configuration, verification, disaster recovery (§35.3)

## P11.B Security hardening (§36)

- [ ] **P11-8** Walk §36 item by item and verify each control (hashing, encryption, HTTPS, secure cookies, CSRF, input validation, output escaping, SQLi, XSS, upload validation, rate limiting, brute force, session, password reset, 2FA for sensitive roles, data masking, API auth, server-side authorization)
- [ ] **P11-9** Verify §36.1 financial controls on every money path: transactions, row locking, idempotency, duplicate prevention, immutable ledger, adjustment/reversal, approval workflows, accurate types, server-side calculation, audit logs
- [ ] **P11-10** Verify audit logging covers every item in §36.2
- [ ] **P11-11** Confirm ordinary admins cannot edit or delete audit logs (§36.2)
- [ ] **P11-12** Security review pass + dependency audit

## P11.C Performance (§39)

- [ ] **P11-13** N+1 elimination + eager loading audit across all list/report endpoints
- [ ] **P11-14** Redis caching strategy + invalidation rules (§38, §39)
- [ ] **P11-15** Confirm every heavy operation from §39 runs on a queue
- [ ] **P11-16** Image optimization, lazy loading, responsive images (§39)
- [ ] **P11-17** Asset minification, code splitting, production builds, CDN readiness (§39)
- [ ] **P11-18** Gzip/Brotli compression + HTTP caching headers (§39)
- [ ] **P11-19** Optimized API responses + server-side pagination everywhere (§39)
- [ ] **P11-20** Performance monitoring + budgets for landing page, ERP, websites, APIs, reports (§39)

## P11.D Scalability (§40)

- [ ] **P11-21** Verify stateless app servers — no local sessions, no local-only files
- [ ] **P11-22** Shared/object media storage in production config
- [ ] **P11-23** Health-check endpoints (app, DB, Redis, queue) (§40, §42)
- [ ] **P11-24** Independent web and queue worker scaling; worker monitoring (§40)
- [ ] **P11-25** DB connection management + read-replica readiness (§40)
- [ ] **P11-26** Load balancer / reverse proxy configuration notes (§40)

## P11.E Scheduled tasks (§41)

- [ ] **P11-27** Register every scheduled task from §41 (28 items) with `onOneServer` + `withoutOverlapping`
- [ ] **P11-28** Verify duplicate-execution protection in a simulated multi-server run (§41)

## P11.F Logging & monitoring (§42)

- [ ] **P11-29** Dedicated log channels: application, payment, wallet, withdrawal, SMS, API, webhook, product sync, order sync, queue, backup, security, audit (§42)
- [ ] **P11-30** Failed job management + retry UI (§42)
- [ ] **P11-31** Error tracking + critical failure alerts (§42)
- [ ] **P11-32** Disk, database, Redis, queue, integration monitoring (§42)
- [ ] **P11-33** **Log scrubbing**: no passwords, tokens, API secrets, gateway secrets, full payment credentials, unmasked PII, unprotected KYC (§42)
- [ ] **P11-34** Horizon production hardening: auth + authorization + network-level restriction (D3; base setup is P0-52)
- [ ] **P11-35** Closure lifecycle: account/website → Closed/read-only, stop new orders, disable storefront access (D18)
- [ ] **P11-36** Self-scoped data export available to the user **before** final closure (D18)
- [ ] **P11-37** Admin-configurable retention period + post-retention personal-data anonymisation (D18)
- [ ] **P11-38** Preserve orders, payments, wallet entries, audit logs, financial records through closure; **permanent deletion requires explicit administrative approval** and never removes legally required financial or audit records (D18)

---

# Phase 12 — Final QA, Accessibility, Documentation

- [ ] **P12-1** Walk §43's full testing list and close every gap (55 named areas)
- [ ] **P12-2** Concurrency suite: concurrent financial transactions, overselling, duplicate payment, duplicate order, duplicate reward (§43)
- [ ] **P12-3** Permissions + self-scoped data access suite (§43)
- [ ] **P12-4** Accessibility audit: contrast, keyboard nav, focus states, labels, form errors, semantic headings, alt text, non-colour-only status, touch targets (§33.9)
- [ ] **P12-5** Responsive verification on desktop, laptop, tablet, mobile; no hover-only actions (§33.8)
- [ ] **P12-6** Verify all five UI states exist on every important screen (§33.10)
- [ ] **P12-7** UI review against §33.1 — confirm nothing on the "avoid" list crept in
- [ ] **P12-8** **Sign-off pass against §44** (30 mandatory business rules), item by item
- [ ] **P12-9** **Sign-off pass against §45** (Final Core Requirements), item by item
- [ ] **P12-10** Deployment guide, runbook, recovery documentation
- [ ] **P12-11** Admin user manual / feature documentation
- [ ] **P12-12** Seed data + demo environment for handover

---

## Progress

Counted from the checkboxes above — `[x]` done, `[~]` started. Recount rather than
increment by hand; a progress table that has drifted is worse than none.

| Phase                                           | Tasks   | Done   | Started |
| ----------------------------------------------- | ------- | ------ | ------- |
| P0 Foundation                                   | 55      | 27     | 10      |
| P1 Identity & Onboarding                        | 78      | 30     | 7       |
| P2 Money Core                                   | 38      | 0      | 0       |
| P3 Catalog & Inventory                          | 31      | 0      | 0       |
| P4 Wholesale                                    | 14      | 0      | 0       |
| P5 Dropship, Websites & Storefront              | 50      | 0      | 0       |
| P6 OMS, Fulfillment, Courier                    | 33      | 0      | 0       |
| P7 Commission, Referral, Withdrawal, Settlement | 41      | 0      | 0       |
| P8 Notifications & SMS                          | 18      | 0      | 0       |
| P9 Reports                                      | 23      | 0      | 0       |
| P10 CMS & SEO                                   | 20      | 0      | 0       |
| P11 Hardening                                   | 38      | 0      | 0       |
| P12 Final QA                                    | 12      | 0      | 0       |
| **Total**                                       | **451** | **57** | **17**  |

### Revision log

- **2026-09-03** — **D23** approved: `User` identity separated from `BusinessAccount`, so platform
  staff need no commercial activation and invited staff need no KYC of their own. P1-64 restated to
  carry the split; **P1-78** added for the application-layer conversion. Factory states and a
  route-access matrix added. Total 450 → 451.
- **2026-09-03** — Activation gate refinements approved and recorded as **D22**. Added **P1-75**
  (three explicit outcome actions, no generic Reject), **P1-76** (`EvaluateActivationReadiness` and
  `approval_pending_at`, making `ApprovalPending` canonical), **P1-77** (concurrency: one winner,
  loser writes nothing). D5 confirmed: local development runs natively in WSL with
  `php artisan serve`; Docker and Sail must not be required. Total 447 → 450.
- **2026-09-01** — Verification pass against the spec. Corrected six item counts (permission
  actions 23→20, order statuses 30+→28, website statuses 13→14, notification events ~35→32,
  self-scoped reports 20→21, admin reports ~60→56). Added P5.D — the §16.1 customer-facing
  storefront was scoped as a single checkbox and is in fact a second application (+20 tasks).
  Added global search (P0-47), indexing checklist (P0-48), future KYC update request (P1-63),
  user sales & earnings dashboard (P7-39). Total 391 → 415.
- **2026-09-01** — All 21 open questions and ambiguities answered and approved; recorded in
  [04-decisions.md](04-decisions.md). Added 32 decision-driven tasks: i18n + visual direction gate
  (D6), Spatie account scoping (D2), Horizon (D3), **storefront API contract pulled forward into
  Phase 0** (D11), staff management (D1), tax engine (D19), fee refundability (D17), downgrade
  guard (D16), currency recording (D4), Steadfast + Pathao (D8), warehouse priority (D15),
  source-based fulfillment (D13), retail-margin earnings base (D12), referee/referrer ledger types
  (D14), notification mandatories (D20), closure and retention lifecycle (D18), resale channel
  field (D21). Total 415 → 447.
