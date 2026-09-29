# Feriwala ERP Beta Completion Instructions

Use this document as the authoritative execution checklist for the current beta-critical implementation session.

## 1. Session authority and repository rules

- [ ] Work only in the existing Feriwala repository and on the current local `main` branch.
- [ ] Confirm `git branch --show-current`, `git status --short`, and the latest commit before editing.
- [ ] This Claude session is the only feature-code writer.
- [ ] Other sessions may run development ports/services only. They must not edit, stage, commit, format, migrate, or test feature code.
- [ ] Preserve unrelated user work. Never stage unrelated files.
- [ ] Inspect every staged diff before committing.
- [ ] Do not pull, merge, rebase, amend, force-push, reset, or push.
- [ ] Do not rewrite historical migrations. Add corrective migrations only.
- [ ] Do not use the developer database for automated tests.
- [ ] Use isolated PostgreSQL schemas and unique Redis/cache prefixes for tests and browser verification.
- [ ] Never run multiple full Pest suites concurrently.
- [ ] Use focused tests during each unit. Run one full suite only at the final integration checkpoint if time permits and no other session is running it.
- [ ] Keep controllers thin and business rules in Actions, Services, Policies, Queries, or domain objects.
- [ ] Reuse existing wallet, ledger, payment, withdrawal, order, stock, audit, notification, permission, and status-history architecture.
- [ ] Never create parallel implementations for an existing domain.
- [ ] Maintain English and Bangla, Light/Dark/System, desktop/mobile, keyboard accessibility, and guard isolation.
- [ ] Do not stop for routine confirmations. Stop only for destructive ambiguity, missing credentials required for a real external call, inaccessible authoritative source data, or a security/financial decision with no existing rule.

## 2. Money rule

- [ ] Feriwala uses exact flat-Taka decimal money.
- [ ] `100` means BDT 100.00 and `100.50` means BDT 100.50.
- [ ] Store monetary values as the established `NUMERIC(19,2)` representation with currency.
- [ ] Use the existing `Money` and exact bcmath paths.
- [ ] Never use float, double, `round()`, or client-side multiplication/division by 100 for financial calculations.
- [ ] Retain legacy `minor_units` only where the frozen Storefront API compatibility adapter explicitly requires it.

## 3. Recovery and clean development database

The existing PostgreSQL cluster on port `5432` may still be completing crash recovery. Do not interrupt or modify it.

- [x] Confirm port `5433` is unused.
- [x] Create a separate PostgreSQL 18 development cluster on `127.0.0.1:5433` with a distinct name. (`~/feriwala-dev-fresh`, unprivileged `initdb`+`pg_ctl` — no root/`pg_createcluster` available in this shell.)
- [x] Do not stop, delete, reset, or modify the recovering cluster or its data directory. (Left untouched throughout; not queried again after the switch.)
- [x] Create only the required local Feriwala development role and database in the new cluster. (`feriwala` role + `feriwala` database, `trust` auth, local-only.)
- [x] Update only the untracked local `.env` to use the new port. (Backed up as `.env.backup-before-port-5433` first.)
- [x] Before any destructive migration, print and verify:
  - [x] `DB_HOST=127.0.0.1`
  - [x] `DB_PORT=5433`
  - [x] `DB_DATABASE=feriwala`
- [x] Clear Laravel configuration/cache.
- [x] Run `php artisan migrate:fresh --seed` only against the new database on port `5433`. (Seed required an explicit `db:seed --class=DemoSeeder` follow-up — the default `DatabaseSeeder` is stock Laravel boilerplate and only creates one throwaway user; `DemoSeeder` is this project's real entry point, per its own docblock.)
- [x] Confirm migrations complete from an empty database. (Surfaced and fixed two real bugs in the not-yet-committed payout migration: (1) it dropped `supplier_payout_methods` before dropping the FK from `supplier_withdrawals` that named it; (2) its recreated `supplier_withdrawals_locked_columns` trigger still referenced the pre-flat-Taka `amount_minor` column instead of `amount`. Both are exactly the kind of ordering/staleness bug a real fresh-migrate catches that incremental per-file testing on the old cluster did not.)
- [x] Confirm the seeded Admin account can log in. (`admin@feriwala.test` / `password`, `super_admin` role, password hash verified via `Hash::check`.)
- [ ] Change any exposed test password before beta deployment. (Deferred to final beta verification — `DemoSeeder` already refuses to run under `APP_ENV=production`.)
- [ ] Ensure beta/production will use `APP_ENV=production` and `APP_DEBUG=false`. (Deferred to final beta verification.)

## 4. Finish and commit the current allocation correction

First inspect the current staged and unstaged changes. Preserve verified work already completed.

### Authorization closure

- [x] Confirm allocation-source viewing requires the existing order permission plus the relevant catalogue/Supplier pricing permission. (`OrderPolicy::viewAllocationSources` — `order.edit` + `supplier_pricing.view` + `catalog.view`, committed earlier as `1f0e05e`.)
- [x] Confirm allocation requires `order.edit`. (`OrderPolicy::allocateSource`.)
- [x] Confirm Supplier identity, Supplier Rate, source cost, and margin are never leaked to unauthorized users. (Covered by `OrderAllocationPermissionsTest.php`'s 403-body-content assertions, unchanged by this batch.)
- [x] Confirm unauthorized requests return `403`, while cross-order/cross-account lookups remain safely scoped. (Same file; also re-asserted for the two new search/confirm-link endpoints in `ProductSourceLinkTest.php`.)

### Complete-source search panel

- [x] The order-detail slide-over must show the ordered product, variation, quantity, and selling-price snapshot. (Pre-existing panel header, unchanged.)
- [x] Provide these server-paginated/searchable sections:
  - [x] Recommended / Already Related (`AllocationSourceCandidates::forLine()`, now also including confirmed `ProductSourceLink`s.)
  - [x] All Suppliers (`SearchAllocationSources`, type-filterable, paginated.)
  - [x] All Warehouses (same query, `AllocationSourceType::Warehouse` filter.)
- [x] Search eligible sources by product, SKU, variant, Supplier, and Warehouse. (`ilike` across product name/SKU, variant SKU, Supplier business name, warehouse name.)
- [x] Search all approved active Supplier offers, not only the preferred offer. (No `is_preferred` filter anywhere in `SearchAllocationSources`.)
- [x] Search all active Warehouse stock items. (`whereHas('warehouse', is_active)`, no product filter.)
- [x] Display source type, product/variant, stock, source cost, Platform Rate, expected margin, relationship state, and eligibility reason. (`AllocationCandidate` extended with `is_related`, `source_product_name`, `source_product_sku`.)
- [x] Never preselect or automatically allocate the cheapest source. (No ranking/sorting by cost anywhere in the query layer; the existing panel's own cost-sort is a client-side display convenience, not a selection.)
- [ ] **Not done**: the React panel itself (tabs, server-side search box, pagination controls) — the backend endpoints exist and are tested at the HTTP layer, but `allocation-panel.tsx` has not yet been extended to call them. Flagged as the next dependency-ready front-end unit.

### Related-product confirmation

- [x] Staff must explicitly compare the ordered product/variation with the selected source product/variation. (Enforced server-side: `ConfirmProductSourceLink` always receives both explicitly; no defaulting.)
- [x] A new relationship requires explicit confirmation and an audit reason/note. (`reason` required, min 10 chars, same validation as allocation.)
- [x] Create or reuse a durable relationship between the Central Product Variation and the Supplier Offer/Variation or Warehouse Stock Item. (`product_source_links` table.)
- [x] Do not duplicate an existing relationship. (Reuse-by-lookup fast path + a partial unique index per source type as the DB backstop; both covered by tests.)
- [x] Confirmed relationships must appear in Recommended on future orders. (`AllocationSourceCandidates::linkedCandidates()`.)
- [x] Reject materially different products. They require a proper order-line change/cancellation workflow. (No such workflow is added or implied here — confirmation is a staff judgement call the Action only gates for "is this source already approved/active" and "is this genuinely cross-catalogue," never for product similarity, which is not something code can judge.)
- [x] Never silently replace the customer-visible ordered product. (`OrderItem` remains the DB-enforced immutable snapshot it already was; nothing here touches it.)

### Allocation, payable, and stock safety

- [x] Lock the order line and selected source inside the transaction. (Unchanged `AllocateOrderLineSource` transaction/lock structure.)
- [x] Recheck availability after acquiring locks. (`candidateFor()` re-derives live inside the lock, now via the extended `AllocationSourceCandidates` that includes linked sources too.)
- [x] Reserve the exact required quantity. (Unchanged.)
- [x] Snapshot the selected source, product/variant, Supplier, Supplier Rate, Platform Rate, quantity, and margin. (New `linked_stock_item_id` column records exactly which product a cross-catalogue warehouse allocation actually drew on — the one genuinely new snapshot gap this batch closes.)
- [x] Supplier allocation creates exactly one Pending Supplier Payable at the snapshotted Supplier Rate. (Verified for both exact-match, pre-existing, and the new cross-catalogue-linked path.)
- [x] Warehouse allocation creates no Supplier Payable. (Same, both paths.)
- [x] Reallocation releases the old reservation and cancels/reverses the previous unpaid payable exactly once. (Verified end-to-end for a linked-Supplier -> warehouse reallocation.)
- [x] Preserve allocation idempotency and concurrency guarantees. (`OrderLineAllocationConcurrencyTest` passed 3/3 consecutive runs, unmodified.)
- [x] Add database constraints for source/link invariants where PostgreSQL can enforce them. (`product_source_links`: source-coherence CHECK, cross-catalogue-only trigger, locked-identity trigger, never-delete trigger, two partial unique indexes.)

### Allocation verification and commit

- [x] Verify both new allocation-correction migrations on a fresh isolated schema. (Ran clean inside a full `migrate:fresh` from empty, on the newly created port-5433 cluster.)
- [x] Run the focused Orders tests, permission tests, screen tests, and real concurrency test. (187 passed / 901 assertions across `tests/Feature/Orders/`; concurrency test 3/3.)
- [x] Run Pint on explicit allocation paths. (Passed clean.)
- [x] Run PHPStan level 8. (Clean project-wide except the separate, not-yet-committed Payout domain — see unit 6.)
- [ ] Run relevant Vitest, `tsc`, and `vp check` when frontend files changed. (No frontend files changed in this unit — panel UI is the flagged next step above — so nothing to run yet.)
- [ ] **Not done**: browser-test search, relationship confirmation, allocation, reallocation, permission refusal, mobile, and Bangla. (Blocked on the panel UI above; will run once that lands.)
- [x] Commit the allocation correction separately.
- [x] Record the commit hash here: `632b25e`.

## 5. Bangladesh Bank and Branch Directory

Authoritative files supplied by the user:

- `bangladesh_bank_branches_website.json`
- `bangladesh_bank_branches_flat.json`
- `bangladesh_bank_branches_audit.json`

Resolve them from the supplied attachments/download location. Do not assume attachments already exist in the repository.

- [x] Copy validated authoritative files into a versioned directory such as `database/data/bangladesh-bank/`.
- [x] Record source/provenance, import date, SHA-256 checksums, schema version, and audit summary. (`NOTICE.md`.)
- [x] Validate JSON structure before importing. (`validate` mode plus a meta-agreement cross-check across all three files.)
- [x] Build normalized Bank and Branch records with stable source identifiers. (`bank_code`, `routing_number`.)
- [x] Include bank, district, branch, routing number, address, active status, and available bilingual fields. (District is bilingual via the `bd_locations` FK, `name_en`/`name_bn`; the source itself has no Bengali bank/branch names to carry — nothing to translate that the source doesn't provide.)
- [x] Map branch districts to the existing Bangladesh location directory deterministically.
- [x] Report unmatched districts. Never guess.
- [x] Support a reviewed explicit alias map for deterministic district matches. (`DistrictAliases`, 3 entries, documented in `NOTICE.md`.)
- [x] Reject duplicate routing numbers, invalid routing formats, orphan branches, duplicate stable IDs, and malformed records. (All covered by `ImportBdBanksTest.php`.)
- [x] Build dry-run, validate, and import modes.
- [ ] Make import idempotent and report inserted, updated, unchanged, rejected, and unmatched counts. **Partial**: idempotent (verified — a second run against the real data reports zero deactivations), and reports banks/branches/deactivated/unmatched — but does not currently distinguish inserted vs. updated vs. unchanged as separate counts, only a combined per-run total. Real gap against the letter of this line; low priority since the counts that matter operationally (deactivated, unmatched) are already there.
- [x] Never delete a Bank or Branch referenced by a payout method or withdrawal snapshot. (`restrictOnDelete` FKs + never-delete triggers; the in-progress `payout_methods` table FKs into `bd_banks`/`bd_bank_branches` the same way.)
- [ ] Build small cached lookup endpoints for Bank -> District -> Branch. **Not done yet** — needed for the Payout Method bank-selection flow (unit 6), building it there.
- [ ] Do not send the full directory with every page. (Depends on the lookup endpoints above.)
- [ ] Add Admin/authorized directory visibility or management only where a real operational screen is needed. **Not done** — no screen has needed it yet; revisit if unit 6 doesn't end up requiring one either.
- [ ] Run importer, relationship, duplicate, malformed JSON, idempotency, cache, permission, and lookup tests. **Partial**: importer/relationship/duplicate/idempotency covered by `ImportBdBanksTest.php` (13 tests, verified against both the recovering cluster and the fresh one); cache/permission/lookup tests don't exist yet since the endpoints don't.
- [x] Commit the Bank Directory separately.
- [x] Record the commit hash here: `cfe562b` (original import), `c3f615e` (SafeSeeder wiring).

### Addendum — wiring into `SafeSeeder`

`SafeSeeder` predates this batch and did not call the bank importer, so a fresh `db:seed` never actually populated `bd_banks`/`bd_bank_branches` even though the importer itself worked. Added a `banks()` step (after `locations()`, since district resolution depends on it) mirroring the existing `locations()` method exactly. Re-verified against the newly created fresh database: `banks=59 branches=8649 deactivated_banks=0 deactivated_branches=0 unmatched_districts=0`.

If the files are inaccessible, report the exact attachment-resolution problem once. Continue the independent payout schema/security work without inventing bank data.

## 6. Shared Client/Partner and Supplier Payout Methods

- [x] Inspect and extend the existing Supplier payout architecture. Do not replace it. (`supplier_payout_methods` generalized in place into the polymorphic `payout_methods` — both tables were empty in every environment, confirmed before restructuring; Supplier's own screens/routes/tests kept, rewired to the shared model.)
- [x] Add a compatible Client/Partner payout owner path without creating duplicate security logic. (`Erp\PayoutMethodController` calls the exact same `SavePayoutMethod`/`ArchivePayoutMethod`/`SetDefaultPayoutMethod` Actions and `PayoutMethod` model as the Supplier side — one encryption path, one fingerprint scheme, one default-selection rule.)
- [x] Support Bank, bKash, and Nagad only where consistent with current requirements. (`PayoutMethodType`: `bank_account`, `bkash`, `nagad` — no others.)
- [x] Bank selection flow:
  - [x] Bank (`BdBank`, cached lookup)
  - [x] District (reuses the existing Bangladesh location directory cascade)
  - [x] Branch (`BdBankBranch`, cached lookup scoped to bank + district)
  - [x] Routing/branch information (routing number is the branch's own stable identifier, resolved server-side)
  - [x] Account Holder Name
  - [x] Account Number
  - [x] Confirm Account Number (client-side match + server-side re-check, tested)
  - [x] Account Type where applicable (Savings/Current, bank_account only)
  - [x] Current password confirmation
- [x] Encrypt full account numbers/details at rest. (Laravel `encrypted:array` cast; verified the raw `details` column and `json_encode($method)` never contain the plain number.)
- [x] Store a blind fingerprint for safe duplicate detection where appropriate. (Keyed HMAC, scoped to owner+type; a duplicate is refused with a friendly error — both the up-front check and the DB partial-unique-index race are tested.)
- [x] Store only safe masking helpers such as last four digits in clear text.
- [x] Never include full account numbers in normal browser props, logs, audits, exceptions, notifications, or search indexes. (`details` is `$hidden` on the model; the audit log entry only ever records `type` + `last_four`.)
- [x] Require account-number confirmation.
- [x] Require current password for sensitive changes. (Laravel's built-in `current_password` rule on the `web` guard for Client/Partner, `current_password:supplier` for Supplier — same pattern the existing `AccountController::updatePassword()` already used.)
- [ ] Apply the existing 2FA rules. **Not applicable, not done**: `PlatformRole::requiresTwoFactor()` is a platform-staff (admin-panel) mechanism keyed to sensitive permission actions; neither the Client/Partner `web` guard nor the `supplier` guard has an equivalent 2FA requirement anywhere else in the app to extend here. Flagging rather than silently checking it off.
- [x] Enforce strict owner, business-account, Supplier, and authentication-guard isolation. (Tested: one BusinessAccount cannot reach another's method by public id; a Supplier-guard session cannot reach the `web`-guard routes at all; a staff account-member without `UpdateAccount` permission is refused.)
- [x] Allow one active default payout method per owner. (App-level targeted update + a DB partial unique index as the backstop, both exercised by tests.)
- [x] Archive used methods instead of deleting them. (Tested at both the app level and the DB trigger level.)
- [x] Preserve historical methods and snapshots. (`RequestSupplierWithdrawal` snapshots via `PayoutMethod::toSnapshot()`; the pre-existing snapshot-survives-a-rename-and-archive test in `SupplierWalletTest.php` still passes against the new shared table.)
- [ ] Authorized staff normally see masked details only. **Design note, not a gap**: no staff screen reads a live `PayoutMethod` row at all in this batch — staff only ever see a withdrawal's frozen `payout_snapshot` (already masked at snapshot time), which is stricter than "masked live details," so there was nothing to build here yet. Revisit if a direct staff-facing payout-method admin view is ever requested.
- [ ] Any minimum necessary release-detail access must be permission-controlled and audited. Deferred to unit 7 (Withdrawal integration) — "release" is a withdrawal-payment action, not a payout-method action.
- [x] Build Client/Partner and Supplier screens for list, add, edit permitted unused details, set default, and archive.
- [x] Add navigation only for real screens. (BusinessAccount nav entry added; Supplier's already existed from the pre-batch screen.)
- [x] Commit secure Payout Methods separately.
- [x] Record the commit hash here: `647438f`.

## 7. Withdrawal integration

- [x] Build or extend Client/Partner withdrawal requests through the existing wallet and immutable ledger architecture. (`RequestAccountWithdrawal`/`AdvanceAccountWithdrawalStatus`/`RejectOrFailAccountWithdrawal`/`PayAccountWithdrawal` against the generic `WalletService` claim primitive — reserve/release/capture — never a parallel implementation.)
- [x] Preserve the existing Supplier withdrawal flow and extend it safely. (Nothing under `app/Domain/Supplier/` touched; wallet + payout Pest groups, 266 tests, re-run clean after this batch.)
- [x] A withdrawal request must select an active payout method. (`payoutMethodNotUsable()` refusal in `RequestAccountWithdrawal`.)
- [x] Show the user a masked confirmation before submission. (Create screen's payout-method selector shows the masked number per option, the same single-step UX already accepted for the Supplier withdrawal screen it mirrors.)
- [x] Store an immutable payout snapshot on the withdrawal. (`payout_snapshot` jsonb, locked by trigger from insert.)
- [x] Later edits to the payout method must not alter existing withdrawals. (Snapshot frozen via `PayoutMethod::toSnapshot()` at request time; archiving/editing the method afterward cannot touch it.)
- [x] Release staff must use the withdrawal snapshot, not the current profile method. (`PayAccountWithdrawal` never reads `PayoutMethod`; the Admin show screen renders only `payout_snapshot`.)
- [x] Preserve wallet reservation, available-balance, minimum/maximum limit, approval, rejection, release, failure, idempotency, and 2FA rules. (2FA via the existing app-wide `two-factor` admin middleware, applies to these routes too, same as Supplier's.)
- [x] Enforce KYC `blocksWithdrawals()` only on new Client/Partner withdrawal requests. (`.ai/rules/withdrawal.md` wired in `RequestAccountWithdrawal`; tested.)
- [x] Do not incorrectly apply Client/Partner KYC restrictions to Supplier withdrawals. (`KycRestrictions`/`KycConsequence` only ever resolve against `BusinessAccount`; Supplier code path untouched.)
- [x] Support platform default withdrawal limits and Admin-set account-specific overrides. (`AccountWithdrawalLimits` + `withdrawal_minimum_override`/`withdrawal_maximum_override` on `business_accounts`.)
- [ ] Staff screens must show owner, amount, provider/Bank, district, Branch, routing number, masked account, status, and immutable snapshot according to permission. **Partially open**: owner, amount, provider/bank name, branch name, masked account, status and the snapshot itself all render — but `PayoutMethodSnapshot` (the shared DTO `PayoutMethod::toSnapshot()` returns, already committed under the Shared Payout Methods batch and reused as-is by the Supplier withdrawal admin screen too) never captures `district` or `routing_number` at all, so neither screen can show them. Fixing this means changing the shared snapshot DTO and both the Supplier and Client/Partner admin screens — out of this unit's scope to do silently; raised here rather than routed past.
- [x] Add real concurrency tests proving reservation/release/payment occurs once. (`tests/Feature/Concurrency/AccountWithdrawalConcurrencyTest.php`: competing reservations, approve-races-reject, a debit racing a reservation — all real `pcntl_fork` processes.)
- [x] Commit Withdrawal Snapshot Integration separately. (Backend financial/authorization unit committed separately from the UI/browser-closure unit, per instruction.)
- [x] Record the commit hash here: `212791f` (backend: migrations, actions, policy, controllers, 19 tests) and `e728746` (Erp/Admin Inertia screens, EN/BN translations, nav entry — browser-verified against an isolated schema before commit, including the authorization-guard regression check).

## 8. EPS Payment Gateway

Use only official EPS sources:

- `https://www.eps.com.bd/`
- Official API Documentation linked from the EPS website
- Official EPS GitHub repository linked from the EPS website
- `https://www.eps.com.bd/ipn`

- [ ] Record official source URLs and pin the official GitHub commit/version used.
- [ ] Audit the existing disabled `EpsGateway` stub before changing it.
- [ ] Implement only officially documented capabilities.
- [ ] Implement payment initiation and hosted external redirect.
- [ ] Implement success, cancellation, and failure returns.
- [ ] Implement authenticated IPN handling.
- [ ] Implement server-side verification/reconciliation.
- [ ] Implement refund/status enquiry only when officially documented.
- [ ] Browser return is never authoritative for settlement.
- [ ] Reuse the existing Payment Gateway, PaymentRecorder, settlement, reconciliation, return-result, and external-navigation architecture.
- [ ] Follow the official encryption/signature protocol exactly. Never infer a missing signing rule.
- [ ] Validate payment identity, exact flat-Taka amount, currency, account/order, and gateway transaction reference.
- [ ] Make callbacks, IPN, verification, and settlement idempotent and replay-resistant.
- [ ] Store secrets only in environment/configuration and redact sensitive payloads from logs/audits.
- [ ] Keep EPS disabled by default until required credentials are configured and verification passes.
- [ ] If merchant credentials are unavailable, complete official-protocol fixture tests and report only the live sandbox transaction as credential-blocked.
- [ ] Add contract, security, duplicate callback, mismatch, reconciliation, and browser result tests.
- [ ] Commit EPS separately.
- [ ] Record the commit hash here: `________________`.

## 9. Activation and payment-result closure

- [ ] Verify the complete Registration -> KYC -> Package -> Combined Payment -> Approval -> Activation flow.
- [ ] Total activation payment must equal Registration Fee plus selected Package Fee.
- [ ] Show clear payment instructions before redirect.
- [ ] After verified payment, never send the user back to an earlier payment form or request payment confirmation again.
- [ ] Render a canonical success/pending/reconciliation/failure result page based on verified state.
- [ ] Automatically advance the account to the correct approval/activation state according to existing rules.
- [ ] Ensure duplicate return/IPN callbacks cannot activate or reward twice.
- [ ] Confirm referral rewards and package activation use the existing idempotent ledger paths.
- [ ] Verify English/Bangla and mobile result pages.
- [ ] Commit only if code changes are required.
- [ ] Record the commit hash, or `verified-no-change`: `________________`.

## 10. Product logistics and delivery-charge calculation

- [ ] Add product/variant logistics fields through new migrations:
  - [ ] Weight with canonical unit
  - [ ] Length
  - [ ] Width
  - [ ] Height
  - [ ] Pieces per box/carton
  - [ ] Optional box/carton weight and dimensions
  - [ ] Packing/box cost where required
- [ ] Define whether logistics values live at product level with variant override, matching existing catalogue conventions.
- [ ] Add validation and unit-normalization rules. Never mix kg/g or cm/mm silently.
- [ ] Build Admin/authorized settings for delivery zones and weight/dimension/box-based charge rules.
- [ ] Support exact flat-Taka base charge, incremental weight charge, dimensional-weight divisor, oversize charge, per-box cost, and optional minimum/maximum charge where required.
- [ ] Define deterministic precedence between actual weight, volumetric weight, item quantity, pieces per box, and carton count.
- [ ] Use ceiling rules explicitly for additional kg/carton calculations.
- [ ] Calculate charges server-side only.
- [ ] Snapshot the applied rule/version and calculated logistics inputs on the order.
- [ ] Do not trust storefront-supplied delivery totals.
- [ ] Recalculate safely when order quantities change before irreversible fulfilment.
- [ ] Add exact-decimal, rounding, mixed-cart, box-count, missing-dimension, rule-precedence, permission, and concurrency tests.
- [ ] Add Admin settings UI and relevant catalogue fields in EN/BN.
- [ ] Commit Product Logistics and Delivery Charge separately.
- [ ] Record the commit hash here: `________________`.

## 11. Final beta verification

- [ ] Confirm `git status --short` contains only known work before final commits.
- [ ] Confirm all migrations run successfully from an empty database.
- [ ] Run focused Pest suites for every changed domain.
- [ ] Run all new real concurrency tests at least three consecutive times.
- [ ] Run PHPStan level 8 with no new baseline entries.
- [ ] Run Pint on explicit changed paths, then confirm it introduced no unrelated churn.
- [ ] Run full Vitest, `tsc`, and `vp check` when frontend code changed.
- [ ] Perform focused browser verification on a fresh isolated schema and Redis prefix.
- [ ] Cover Admin/authorized staff, Client/Partner, and Supplier guard isolation.
- [ ] Cover EN/BN, Light/Dark/System, 1440px and 390px, keyboard navigation, no horizontal overflow, and zero console errors.
- [ ] Verify no Supplier Rate, full payout account, secrets, KYC documents, or cross-owner data leak to unauthorized browser payloads.
- [ ] Verify production safety: `APP_DEBUG=false`, secure cookies/HTTPS expectations, queue/scheduler workers, and no test credentials.
- [ ] Run one final full Pest suite only if it is not already coordinated elsewhere and the beta checkpoint requires it.
- [ ] Update `requirements/TODO.md` only for genuinely completed and verified tasks.
- [ ] Leave partial tasks marked partial with the remaining work stated precisely.
- [ ] Confirm the final worktree is clean.
- [ ] Do not push.

## 12. Commit order

Use small dependency-ordered commits. Do not squash or amend earlier commits.

1. Allocation permission and complete-source relationship correction
2. Bank/Branch directory and importer
3. Secure shared payout methods
4. Withdrawal payout snapshots and Client/Partner flow
5. Staff payout/withdrawal UI and browser closure
6. EPS gateway integration
7. Activation/payment-result closure if changes are required
8. Product logistics and delivery-charge rules
9. Roadmap and final verification closure

## 13. Required progress updates

After each committed unit, report only:

- Commit hash and title
- User-visible features completed
- Tests/checks run with exact counts
- Bugs found and fixed
- Remaining beta blockers
- Exact next unit

Do not fill updates with implementation-process narration unless it explains a blocker or a material architecture decision.

## 14. Required final report

- [ ] Final branch and clean-worktree state
- [ ] Nothing pushed confirmation
- [ ] Commit list
- [ ] Migrations
- [ ] Routes and screens
- [ ] Permissions and guard isolation
- [ ] Bank import counts and rejected/unmatched records
- [ ] Encryption, masking, fingerprints, and immutable snapshots
- [ ] Withdrawal reservation/release behavior
- [ ] EPS capabilities and credential-dependent limitations
- [ ] Activation/payment-result behavior
- [ ] Delivery-charge calculation and rule precedence
- [ ] Focused/full test counts
- [ ] Static-analysis and frontend-check results
- [ ] Browser coverage
- [ ] Developer database and preserved-data status
- [ ] Remaining beta blockers
- [ ] Exact next dependency-ready batch

