# 02 — Data Model Map

Domain-by-domain table map. This is the target shape, not final DDL — column lists are indicative,
migrations get written per phase. PostgreSQL throughout. Money columns are `BIGINT` minor units
plus a `currency` column. Every publicly-addressable table carries `public_id` (ULID) and/or
`slug`. Soft deletes only where the spec implies archival (§37).

## Conventions

| Convention    | Rule                                                                                        |
| ------------- | ------------------------------------------------------------------------------------------- |
| PK            | `bigint` identity, internal only                                                            |
| Public ref    | `public_id` ULID + human ref (`ORD-`, `PAY-`, `TXN-`, `WDR-`, `INV-`, `STL-`)               |
| Money         | `bigint` minor units + `currency char(3)`, never float                                      |
| Timestamps    | `created_at`, `updated_at`; `deleted_at` where archival applies                             |
| Actor columns | `created_by`, `updated_by`, `approved_by` → `users.id`                                      |
| Status        | text/enum-backed column + separate `*_status_history` table where the spec requires history |
| JSON          | `jsonb` (never `json`), with GIN indexes only where actually queried                        |

---

## Account & Identity (§5, §6)

| Table                            | Notes                                                                                                                                                                                                       |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `users`                          | extend starter kit: `public_id`, `status`, `mobile`, `mobile_verified_at`, `dob`, `gender`, `country`, `nationality`, `referral_code` (unique), `referred_by_user_id`, `activated_at`, `current_package_id` |
| `user_addresses`                 | present/permanent/billing/shipping, typed                                                                                                                                                                   |
| `user_status_history`            | previous, new, changed_by, reason, internal note, user-visible note, at                                                                                                                                     |
| `user_devices` / `user_sessions` | device + session history, logout-other-devices (§6)                                                                                                                                                         |
| `login_attempts`                 | brute-force + suspicious-login detection                                                                                                                                                                    |
| `account_restrictions`           | reason, scope, applied_by, starts_at, ends_at, auto_restore rule                                                                                                                                            |

Indexes: `email`, `mobile`, `referral_code`, `status`, `public_id`.

**Starter-kit tables kept:** `passkeys`, two-factor columns. `teams` / `memberships` /
`team_invitations` — pending decision (see [99-open-questions.md](99-open-questions.md) Q1);
target repurpose is _staff sub-accounts under one owner account_, matching the package "staff
limit" feature.

## KYC (§7)

| Table                      | Notes                                                                                   |
| -------------------------- | --------------------------------------------------------------------------------------- |
| `kyc_document_types`       | admin-defined: name, required flag, accepted formats, max size, instructions, active    |
| `kyc_document_type_scopes` | package-specific / country-specific applicability                                       |
| `kyc_submissions`          | user, status, submitted_at, deadline_at, review round                                   |
| `kyc_submission_fields`    | dynamic field values per submission                                                     |
| `kyc_documents`            | private disk path, mime, size, checksum, encrypted flag                                 |
| `kyc_reviews`              | reviewer, previous status, new status, at, reason, internal note, user-visible feedback |
| `kyc_document_accesses`    | **every** view/download: who, when, IP, user agent (§7.5)                               |

## Packages (§8)

| Table                     | Notes                                                                                                                                                                            |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `packages`                | name, slug, descriptions, fee, registration_fee override, validity, renewal fee/frequency, grace period, active                                                                  |
| `package_features`        | typed key/value: website facility, domain, hosting, publish limit, order limit, staff limit, SMS quota, API access, support level, report access, fulfillment/courier facilities |
| `package_charges`         | website setup, maintenance, domain, hosting                                                                                                                                      |
| `package_deposit_rules`   | required deposit, minimum balance (also see wallet rules)                                                                                                                        |
| `user_packages`           | subscription: user, package, started_at, expires_at, status, renewal state, source (purchase/upgrade/downgrade/manual/promotional)                                               |
| `package_change_requests` | upgrade/downgrade, proration, additional deposit, effective date, approval                                                                                                       |

## Billing / Payments (§9, §26)

| Table                            | Notes                                                                                                                                                                                                       |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `payments`                       | ref, user, gateway, purpose, gross, fee, net, currency, status, gateway_reference, idempotency_key (unique), initiated/completed timestamps                                                                 |
| `payment_allocations`            | **the key table** — splits one payment into components: registration_fee, package_fee, wallet_deposit, website_setup, domain, hosting, tax, discount, gateway_charge. Satisfies §5.1/§9 "stored separately" |
| `payment_attempts`               | each gateway round-trip                                                                                                                                                                                     |
| `payment_logs`                   | request/response, secrets redacted                                                                                                                                                                          |
| `refunds`                        | full/partial, reason, gateway ref, status                                                                                                                                                                   |
| `invoices` / `invoice_lines`     | issued for activation, package, orders, services                                                                                                                                                            |
| `coupons` / `coupon_redemptions` | code, type, value, eligibility, effective dates, usage limits                                                                                                                                               |
| `tax_rules`                      | rate, scope, effective dates                                                                                                                                                                                |
| `fee_rules`                      | global + package-specific registration fee, effective dates (§9)                                                                                                                                            |
| `payment_gateways`               | slug, driver, enabled, sandbox/live mode                                                                                                                                                                    |
| `payment_gateway_credentials`    | encrypted, per mode                                                                                                                                                                                         |

## Wallet & Ledger (§23, §24)

| Table                  | Notes                                                                                                                                                                                                                                                                                                           |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `wallets`              | user, currency, and the balance buckets: `total`, `reserved`, `usable`, `available_withdrawal`, `pending`, `hold`, `required_deposit`, `cod_receivable`                                                                                                                                                         |
| `ledger_entries`       | **immutable.** ref, user, wallet, type, source, debit, credit, balance_before, balance_after, bucket amounts, status, related_* FKs (order, payment, withdrawal, package, website, service, commission, referral), created_by, approved_by, description, internal note, `reverses_entry_id`, `adjusts_entry_id` |
| `wallet_transactions`  | operational view over ledger with the §23.3 status lifecycle                                                                                                                                                                                                                                                    |
| `deposit_rules`        | scope (global/package/user/website/domain/hosting), required initial, minimum balance, top-up amount, deadline, frequency, grace period, low + critical thresholds, restriction rules, restoration rules, effective dates                                                                                       |
| `deposit_rule_changes` | previous/new value, changed_by, reason, effective date                                                                                                                                                                                                                                                          |
| `wallet_holds`         | amount, reason, related entity, released_at                                                                                                                                                                                                                                                                     |
| `balance_alerts`       | threshold crossings, notification state, grace period tracking                                                                                                                                                                                                                                                  |

Enforcement: DB trigger + model guard blocking `UPDATE`/`DELETE` on `ledger_entries`.

## Catalog (§11, §12)

| Table                                             | Notes                                                                                                                                                                                                                            |
| ------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `categories`                                      | nested (parent_id), slug, image, SEO fields, sort order, active                                                                                                                                                                  |
| `brands`                                          | slug, logo, active                                                                                                                                                                                                               |
| `products`                                        | name, slug, sku (unique), barcode, descriptions, category, brand, wholesale_price, base_cost, suggested/min/max selling price, min/max order qty, status, featured, dropshipping_enabled, wholesale_enabled, SEO + schema fields |
| `product_variants`                                | sku, attribute combination, prices, stock link                                                                                                                                                                                   |
| `product_attributes` / `product_attribute_values` |                                                                                                                                                                                                                                  |
| `product_media`                                   | images + videos, alt text, sort order                                                                                                                                                                                            |
| `product_price_tiers`                             | quantity-based wholesale pricing (§11.1, §13)                                                                                                                                                                                    |
| `product_package_eligibility`                     | which packages may sell it                                                                                                                                                                                                       |
| `product_user_eligibility`                        | per-user allow/deny                                                                                                                                                                                                              |
| `related_products`                                |                                                                                                                                                                                                                                  |

Indexes: `slug`, `sku`, `status`, `category_id`, `brand_id`, composite for catalog filters.

## Inventory (§19)

| Table                | Notes                                                                                 |
| -------------------- | ------------------------------------------------------------------------------------- |
| `warehouses`         |                                                                                       |
| `stock_items`        | product/variant × warehouse: available, reserved, processing, sold, returned, damaged |
| `stock_reservations` | order, item, qty, expires_at, released_at — the overselling guard                     |
| `stock_movements`    | type, qty delta, before/after, reason, reference, actor                               |
| `stock_adjustments`  | admin-initiated, reason, approval                                                     |
| `low_stock_alerts`   |                                                                                       |

All writes: transaction + `SELECT … FOR UPDATE` on `stock_items`.

## Wholesale (§13, §14)

| Table                                                                            | Notes                   |
| -------------------------------------------------------------------------------- | ----------------------- |
| `carts` / `cart_items`                                                           | ERP-only cart, per user |
| Wholesale orders reuse the unified `orders` tables with `source = erp_wholesale` |

## Dropshipping & Websites (§15, §16, §17)

| Table                                  | Notes                                                                                                                                                                                                     |
| -------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `websites`                             | user, package, name, domain, subdomain, status, charges, deposit requirements, branding, theme, activation/expiry, last_sync_at, connection_health                                                        |
| `website_categories`                   | site-local category tree / placement                                                                                                                                                                      |
| `website_products`                     | selection + publication: product, website, visibility, category placement, display order, featured, selling price, promotional price, promo title, marketing description, publication status, sync status |
| `website_product_price_rules`          | admin bounds: user pricing allowed, min/max/suggested price, allowed margin, locked fields                                                                                                                |
| `website_settings`                     | payment, shipping, contact, legal, SEO                                                                                                                                                                    |
| `website_credentials`                  | API key + encrypted secret, scopes, rotated_at, revoked_at                                                                                                                                                |
| `website_domains` / `website_hostings` | provider, registered_at, expires_at, renewal state, charges                                                                                                                                               |
| `website_status_history`               |                                                                                                                                                                                                           |
| `api_logs` / `webhook_logs`            | endpoint, direction, payload (redacted), status, duration                                                                                                                                                 |
| `webhook_deliveries`                   | attempts, next_retry_at, signature                                                                                                                                                                        |
| `sync_queue_failures`                  | entity, reason, retry state, manual retry actor                                                                                                                                                           |

## Orders / OMS (§18)

| Table                      | Notes                                                                                                                                                                                                                                                                               |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `orders`                   | ref, source (erp_wholesale/website/manual/api/admin/external), user, website, customer snapshot, addresses, financial breakdown (subtotal, discount, tax, shipping, COD fee, total, paid, due), payment status, fulfillment status, courier status, delivery status, current status |
| `order_items`              | product/variant snapshot, qty, unit price, line totals, commission basis                                                                                                                                                                                                            |
| `order_statuses`           | seeded system statuses + admin-created custom (§18.2), `is_system` undeletable                                                                                                                                                                                                      |
| `order_status_transitions` | allowed transition map                                                                                                                                                                                                                                                              |
| `order_status_history`     | previous, new, changed_by, at, reason, internal note, user-visible note, notification status (§18.3)                                                                                                                                                                                |
| `order_notes`              | internal vs customer-visible                                                                                                                                                                                                                                                        |
| `order_customers`          | website customer records                                                                                                                                                                                                                                                            |
| `returns` / `return_items` | request, approval, inspection outcome                                                                                                                                                                                                                                               |
| `order_refunds`            | links to `refunds`                                                                                                                                                                                                                                                                  |

## Fulfillment & Courier (§20, §21)

| Table                               | Notes                                                                                                                         |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `fulfillments`                      | order, warehouse, status, staff, charges, packing materials, notes                                                            |
| `fulfillment_items`                 |                                                                                                                               |
| `packing_slips` / `shipping_labels` | generated documents                                                                                                           |
| `courier_providers`                 | driver slug, enabled, credentials (encrypted)                                                                                 |
| `courier_shipments`                 | order, provider, tracking number, status, delivery charge, COD amount, return charge, booked_at, delivered_at, failure reason |
| `courier_status_updates`            | polled/webhook status trail                                                                                                   |
| `courier_reconciliations`           | statement import, matched/unmatched, variance                                                                                 |

## Commission (§22)

| Table                     | Notes                                                                                                                                           |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `commission_rules`        | scope (global/package/user/product/category/campaign), fixed or percentage, calculation base, priority, max commission, effective dates, active |
| `commission_rule_changes` | audit trail                                                                                                                                     |
| `commissions`             | order, order item, user, rule applied, base amount, rate, amount, status (§22.3), eligibility conditions met, available_at                      |
| `commission_reversals`    | cause (cancel/return/refund), amount, ledger link                                                                                               |

## Referral (§25)

| Table                   | Notes                                                                                                                                                                                                     |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `referral_plans`        | referrer reward, new-user reward, fixed/percentage, base, eligible package, minimum qualifying payment, KYC requirement, activation requirement, holding period, max reward, dates, reversal rule, active |
| `referrals`             | referrer, referred user, code used, qualified_at, status, plan applied                                                                                                                                    |
| `referral_rewards`      | beneficiary (referrer or new user), amount, status, released_at, ledger link                                                                                                                              |
| `referral_fraud_checks` | self-referral, duplicate account, duplicate reward signals                                                                                                                                                |

Hard constraints: unique `(referred_user_id)` — a user is referred once, ever; referrer cannot
equal referred; unique `(referral_id, beneficiary_type)` on rewards to block duplicates.

## Withdrawal (§27)

| Table                       | Notes                                                                                                                                                                                                                                                        |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `withdrawal_methods`        | bank / bKash / Nagad / admin-defined, required fields schema                                                                                                                                                                                                 |
| `user_withdrawal_accounts`  | user's payout destinations, verified flag                                                                                                                                                                                                                    |
| `withdrawal_rules`          | scope = global (default) or user (override); min, max, processing fee, frequency, daily/weekly/monthly limits, eligible balance, required reserved balance, KYC requirement, active package requirement, approval workflow, processing time, effective dates |
| `withdrawal_rule_changes`   | previous value, new value, changed_by, reason, effective/expiry date (§27.3 mandates this)                                                                                                                                                                   |
| `withdrawals`               | ref, user, amount, fee, net, method, account snapshot, status (§27.5), reviewer, approved_at, paid_at, payment proof, transaction reference, note                                                                                                            |
| `withdrawal_status_history` |                                                                                                                                                                                                                                                              |

Resolver: user-scoped rule wins over global, per field, when valid and in effect.

## Settlement & Reconciliation (§28)

| Table                   | Notes                                                                                                        |
| ----------------------- | ------------------------------------------------------------------------------------------------------------ |
| `cod_collections`       | order, COD amount, courier collected amount, collection date, courier charge, return charge, adjustment, net |
| `settlements`           | ref, user, period, gross, deductions, net payable, status, paid_at                                           |
| `settlement_items`      | order-level breakdown                                                                                        |
| `reconciliation_runs`   | type (gateway/courier/wallet/ledger/commission/referral/withdrawal/deposit), period, status                  |
| `reconciliation_issues` | mismatch type, expected, actual, variance, resolution, resolved_by                                           |

## Notifications & SMS (§29, §30)

| Table                      | Notes                                                                                |
| -------------------------- | ------------------------------------------------------------------------------------ |
| `notifications`            | Laravel notifications table, database channel, for the dashboard notification center |
| `notification_preferences` | per user / per event                                                                 |
| `notification_events`      | catalogue of the 32 events in §29, with enable/disable per channel                   |
| `sms_providers`            | slug, driver, enabled, priority, credentials (encrypted), balance                    |
| `sms_templates`            | event, language (bn/en), body with variables                                         |
| `sms_messages`             | recipient, provider, template, rendered body, status, cost, provider ref, error      |
| `sms_logs`                 | delivery attempts, retries                                                           |

Delivery is queue-only (§30.2).

## Reporting (§31)

Reports read from domain tables — no separate storage except:

| Table               | Notes                                                                                          |
| ------------------- | ---------------------------------------------------------------------------------------------- |
| `report_exports`    | requested report, filters, format (CSV/Excel/PDF), status, file path, expires_at, requested_by |
| `scheduled_reports` | report, schedule, recipients, format                                                           |

Materialized views / summary tables considered later, only if measured queries demand it (§37
warns against over-engineering indexes; same discipline applies here).

## CMS (§4, §34)

| Table                  | Notes                                                                                                                                |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| `pages`                | slug, title, status (draft/published/scheduled), publish_at, SEO fields, layout                                                      |
| `page_sections`        | type (hero/about/features/stats/testimonials/faq/contact/custom), order, enabled, desktop/mobile visibility, content (jsonb), colors |
| `page_revisions`       | full snapshot, author, restored_from                                                                                                 |
| `media`                | uploads with alt text, dimensions, optimized variants                                                                                |
| `menus` / `menu_items` | header + footer navigation                                                                                                           |
| `redirects`            | 301 management (§34.1)                                                                                                               |
| `seo_settings`         | global meta, OG defaults, organization schema, robots                                                                                |

## Access, Audit, System (§32, §35, §36, §42)

| Table                                                             | Notes                                                                                        |
| ----------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| `roles`, `permissions`, `model_has_roles`, `role_has_permissions` | Spatie (pending Q2)                                                                          |
| `audit_logs`                                                      | **append-only.** actor, action, auditable type/id, before, after, reason, IP, user agent, at |
| `sensitive_action_confirmations`                                  | password/2FA confirmation records for §32.2                                                  |
| `approval_requests`                                               | maker-checker workflow: requester, approver, entity, payload, status                         |
| `settings`                                                        | key, group, type, value, is_encrypted                                                        |
| `backups`                                                         | type (auto/manual), scope, status, size, path, encrypted, retention_until, requested_by      |
| `backup_logs`                                                     | success/failure detail                                                                       |
| `system_alerts`                                                   | reconciliation mismatch, API failure, queue failure, disk/DB/Redis health                    |
| `jobs`, `failed_jobs`                                             | Laravel (Redis-backed queues, DB failed jobs)                                                |

## Index plan (§37)

Explicitly required indexes: unique slugs · emails · mobile numbers · referral codes · product
SKUs · order refs · payment refs · transaction refs · account status · KYC status · package
status · website status · product status · withdrawal status · domain expiry · hosting expiry ·
sales dates · frequently filtered report columns.

Discipline per §37: index what queries actually need, verified with `EXPLAIN ANALYZE` on seeded
volume data — not speculative indexes on every column.
