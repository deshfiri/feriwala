# 05 — Storefront ↔ ERP API Contract

**Version `v1` · Status: 🔒 APPROVED AND FROZEN — 2026-09-01 · Task P0-53**

Pulled forward from Phase 5 into Phase 0 per your final direction, so the catalog, inventory,
order, and wallet modules are built against a contract that is already frozen.

> **Frozen.** `v1` is now the stable contract. Non-breaking additions (optional request fields,
> new response fields, new endpoints, new enum values) may ship into `v1`. Anything breaking —
> removing or renaming a field, narrowing a type, adding a required request field, changing an
> error code's meaning — requires `v2` and must be raised for approval first, since the storefront
> boundary is one of the architecture-locked items in [04-decisions.md](04-decisions.md).

Governing decisions: **D11** (separate storefront application, scoped credentials, no direct
database access) and **D12** (Feriwala is merchant of record; partner gateway credentials are out
of scope for v1). Spec basis: §16, §17, §36.

---

## 1. Shape of the integration

```
        ERP (private, authenticated)                 STOREFRONT (public)
        ┌────────────────────────────┐               ┌────────────────────────┐
        │ Central catalog            │               │ Listing / detail       │
        │ Central stock              │──── PULL ────▶│ Cart / checkout        │
        │ Pricing per website        │  (storefront  │ Customer accounts      │
        │ Categories                 │   reads)      │ Order tracking         │
        │                            │               │                        │
        │ Orders / customers         │◀─── PUSH ─────│ Order submission       │
        │ Payments / returns         │  (storefront  │ Returns / refunds      │
        │ Wallet / ledger / KYC      │   writes)     │                        │
        │  ⛔ never exposed          │               │                        │
        └────────────────────────────┘               └────────────────────────┘
                     │                                          ▲
                     └────────── WEBHOOKS (ERP notifies) ───────┘
                         stock, price, product, order status
```

Two directions, deliberately asymmetric:

- **Storefront reads** catalog data by **pulling** — resilient, cacheable, replayable.
- **ERP notifies** of changes by **webhook** — so stock and price move in near real time without
  the storefront polling aggressively.
- **Storefront writes** orders and customers by **pushing** to the ERP.

`Wallets, ledger entries, KYC, commissions, referrals, withdrawals, and other partners' data are
not addressable through this API at all.` There is no endpoint, no scope, and no parameter that
reaches them. This is the D11 isolation guarantee, and it is enforced by route surface, not by
permission checks alone.

---

## 2. Base URL and versioning

```
https://<erp-host>/api/storefront/v1
```

- The version is in the path. A breaking change means `v2`, with `v1` supported until every
  storefront has migrated.
- **Breaking:** removing a field, renaming a field, narrowing a type, adding a required request
  field, changing an error code's meaning.
- **Non-breaking (may ship into `v1`):** adding an optional request field, adding a response
  field, adding a new endpoint, adding a new enum value _where the client is documented to
  tolerate unknown values_ — which it is, see §8.

---

## 3. Authentication

Every request is signed. Bearer tokens alone are not used, because the same mechanism has to
secure webhooks travelling the other way.

### 3.1 Credentials

Each website gets a credential pair (§17.3):

| Field                       | Notes                                                    |
| --------------------------- | -------------------------------------------------------- |
| `key_id`                    | public identifier, e.g. `wsk_01J8Z...`                   |
| `secret`                    | 32-byte random, shown once, **stored encrypted at rest** |
| `scopes`                    | explicit allow-list, see §3.4                            |
| `rotated_at` / `revoked_at` | rotation and revocation supported                        |

### 3.2 Signing

```
Authorization: Feriwala-HMAC-SHA256 Credential=<key_id>, Signature=<hex>
X-Feriwala-Timestamp: <unix seconds>
X-Feriwala-Nonce: <uuid v4>
```

The signature is `HMAC-SHA256(secret, canonical_request)` where:

```
canonical_request =
    <HTTP-METHOD>       \n
    <path + sorted query string> \n
    <X-Feriwala-Timestamp>       \n
    <X-Feriwala-Nonce>           \n
    <hex sha256 of raw request body, empty string hashed when no body>
```

Verification requires **all** of:

1. `key_id` resolves to an active, non-revoked credential.
2. Signature matches, compared in constant time.
3. Timestamp within **±300 seconds** of server time.
4. Nonce unseen for that credential within the last 600 seconds (Redis, `locks` database).

Failing any check returns `401` and is written to `api_logs` with the signature redacted.

### 3.3 Transport

HTTPS only. Plain HTTP is refused, not redirected — a redirect would leak the signed request.

### 3.4 Scopes

Least privilege. A credential carries only what its storefront needs:

| Scope             | Grants                                                       |
| ----------------- | ------------------------------------------------------------ |
| `catalog:read`    | products, categories, media, pricing for **its own website** |
| `inventory:read`  | stock availability for its own published products            |
| `orders:write`    | submit orders                                                |
| `orders:read`     | read back its own orders                                     |
| `customers:write` | create/update its own customers                              |
| `returns:write`   | submit return and refund requests                            |

There is deliberately **no** `wallet:*`, `ledger:*`, `kyc:*`, `commission:*`, or `user:*` scope.
Those are not omissions to be filled in later — the surface does not exist.

### 3.5 Tenancy

The credential **is** the tenancy boundary. Every query is scoped to the website that owns the
credential, server-side, before any user-supplied filter is applied. A `website_id` parameter is
never accepted from the client. Cross-website access is impossible by construction, and the test
group at P5-29 asserts it.

---

## 4. Conventions

### 4.1 Money

Always an object, never a bare number — mirroring `Money::jsonSerialize()` so the storefront never
performs arithmetic on a float:

```json
{ "minor_units": 123456, "currency": "BDT", "decimal": "1234.56" }
```

Amounts are **integer minor units** (poisha). `decimal` is informational for display.

### 4.2 Identifiers

Public ULIDs and slugs only. No database IDs cross the boundary (§34.2, §34.3).

```json
{ "id": "01J8Z9K3M7QX2P4R6T8V0W1Y3B", "slug": "cotton-panjabi-navy" }
```

### 4.3 Timestamps

ISO 8601, UTC, with offset: `2026-09-01T14:32:07+00:00`.

### 4.3.1 Mobile numbers

Mobile numbers are the primary customer key (§6.2), so their format is part of the contract rather
than a detail left to each storefront.

- Transmitted in **E.164**: `+8801712345678`.
- The ERP normalises on receipt — strips spaces, hyphens, and parentheses, converts a leading `0`
  to the website's default country dialling code, and rejects what it cannot resolve with
  `422 invalid_mobile_number`.
- The **normalised** form is what is stored, indexed, and matched on. A storefront sending
  `01712-345678` and one sending `+8801712345678` must resolve to the same customer.

### 4.4 Pagination

Cursor-based, stable under concurrent writes:

```json
{
  "data": [ ... ],
  "meta": { "next_cursor": "eyJpZCI6...", "has_more": true }
}
```

Default page size 50, maximum 200 via `?limit=`.

### 4.5 Errors

```json
{
    "error": {
        "code": "insufficient_stock",
        "message": "Only 3 units of SKU FW-1043-NVY-M remain.",
        "details": { "sku": "FW-1043-NVY-M", "requested": 5, "available": 3 },
        "request_id": "01J8Z9K3M7QX2P4R6T8V0W1Y3B"
    }
}
```

`code` is stable and machine-readable; `message` is human-facing and may change. `request_id`
appears in `api_logs` for support. Messages never contain another partner's data, internal IDs,
stack traces, or SQL.

| HTTP  | Meaning                                                                         |
| ----- | ------------------------------------------------------------------------------- |
| `400` | malformed request                                                               |
| `401` | signature, timestamp, or nonce failed                                           |
| `403` | authenticated but scope does not permit, or resource belongs to another website |
| `404` | not found _within this website's scope_                                         |
| `409` | idempotency conflict, or state conflict such as an already-cancelled order      |
| `422` | validation failed                                                               |
| `429` | rate limited — see `Retry-After`                                                |
| `5xx` | ERP fault; safe to retry with backoff                                           |

`403` and `404` are chosen carefully: a resource belonging to another website returns `404`, not
`403`, so the API cannot be used to probe whether a SKU or order exists elsewhere.

### 4.6 Rate limits

Per credential, enforced in Redis. Defaults, tunable per website:

| Class                     | Limit        |
| ------------------------- | ------------ |
| catalog / inventory reads | 600 / minute |
| order + customer writes   | 120 / minute |
| everything else           | 300 / minute |

Responses carry `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`.

### 4.7 Idempotency

**Required** on every `POST` and `PATCH`:

```
Idempotency-Key: <uuid v4>
```

Semantics:

- First use → processed, response stored against the key for **24 hours**.
- Replay with the **same** key and **same** body → the stored response, byte-for-byte. Never a
  second order.
- Replay with the same key and a **different** body → `409 idempotency_key_reused`.

This is what makes a storefront retry after a timeout safe (§17.3, §26.4, §36.1).

---

## 5. Endpoints — storefront reads from ERP

### 5.1 Catalog

```
GET  /products                     ?updated_since= &cursor= &limit= &category= &status=
GET  /products/{id}
GET  /categories                   ?updated_since= &cursor=
GET  /categories/{id}
```

`updated_since` is what makes scheduled reconciliation sync cheap: the storefront asks only for
what changed.

Product payload (abbreviated):

```json
{
    "id": "01J8Z9...",
    "slug": "cotton-panjabi-navy",
    "sku": "FW-1043-NVY",
    "name": "Cotton Panjabi — Navy",
    "short_description": "...",
    "description": "...",
    "brand": { "id": "01J8...", "name": "Aarong", "slug": "aarong" },
    "categories": [{ "id": "01J8...", "slug": "panjabi", "name": "Panjabi" }],
    "media": [
        {
            "url": "https://...",
            "alt": "Navy cotton panjabi, front",
            "position": 1,
            "type": "image"
        }
    ],
    "variants": [
        {
            "id": "01J8...",
            "sku": "FW-1043-NVY-M",
            "attributes": { "size": "M", "colour": "Navy" },
            "price": {
                "minor_units": 249000,
                "currency": "BDT",
                "decimal": "2490.00"
            },
            "compare_at_price": null,
            "availability": {
                "in_stock": true,
                "quantity": 12,
                "backorderable": false
            }
        }
    ],
    "min_order_quantity": 1,
    "max_order_quantity": 10,
    "seo": { "title": "...", "description": "...", "canonical": "https://..." },
    "published_at": "2026-08-14T09:00:00+00:00",
    "updated_at": "2026-09-01T11:04:22+00:00"
}
```

**`price` is the website's own selling price** — resolved server-side from the partner's permitted
price within admin bounds (§15.1). Wholesale price, base cost, and margin are **never** in this
payload. The storefront cannot see what Feriwala paid, and cannot compute the partner's earning.

### 5.2 Inventory

```
GET  /inventory                    ?skus[]= &cursor=
GET  /inventory/{sku}
```

Returns availability only — never warehouse identity, never reservation detail, never other
websites' allocation:

```json
{
    "sku": "FW-1043-NVY-M",
    "in_stock": true,
    "quantity": 12,
    "updated_at": "..."
}
```

`quantity` is advisory. **Availability shown at browse time is never a promise.** The binding
check is stock reservation at order submission (§5.3), which is transactional. A storefront that
treats this number as authoritative will oversell; the contract says plainly that it must not.

### 5.3 Orders (read-back)

```
GET  /orders                       ?cursor= &status= &placed_after=
GET  /orders/{id}
```

Scoped to the calling website. Returns order status, fulfillment status, courier status, tracking
number, and the customer-facing timeline — the data an order-tracking page needs, and nothing more.

---

## 6. Endpoints — storefront writes to ERP

### 6.1 Submit an order

```
POST /orders
Idempotency-Key: <uuid>
```

```json
{
    "storefront_order_reference": "SF-2026-000481",
    "placed_at": "2026-09-01T14:30:00+00:00",
    "customer": {
        "storefront_customer_reference": "cus_8813",
        "name": "...",
        "email": "...",
        "phone": "+8801...",
        "is_guest": false
    },
    "shipping_address": {
        "line1": "...",
        "line2": null,
        "city": "...",
        "district": "...",
        "postcode": "...",
        "country": "BD"
    },
    "billing_address": { "...": "..." },
    "items": [
        {
            "sku": "FW-1043-NVY-M",
            "quantity": 2,
            "unit_price": { "minor_units": 249000, "currency": "BDT" }
        }
    ],
    "totals": {
        "subtotal": { "minor_units": 498000, "currency": "BDT" },
        "discount": { "minor_units": 0, "currency": "BDT" },
        "shipping": { "minor_units": 6000, "currency": "BDT" },
        "tax": { "minor_units": 0, "currency": "BDT" },
        "grand_total": { "minor_units": 504000, "currency": "BDT" }
    },
    "payment": {
        "method": "online",
        "gateway": "sslcommerz",
        "gateway_reference": "SSLCZ_TXN_...",
        "status": "paid"
    },
    "coupon_code": null,
    "customer_note": null
}
```

**Server-side revalidation is mandatory and non-negotiable.** The ERP recalculates every price,
discount, tax, and total from its own data. Submitted `unit_price` and `totals` are treated as the
storefront's _claim_, compared against the authoritative calculation, and a mismatch beyond zero
tolerance returns `422 price_mismatch` with both figures. A storefront cannot set its own prices
by asserting them here — that would be a direct route to fraud against the ledger.

Payment is likewise verified against the gateway independently (D12 — Feriwala is merchant of
record, so the ERP owns the gateway relationship and can always verify).

Order acceptance is **transactional**: stock reservation, order creation, and customer upsert
either all succeed or none do.

Responses:

| Code  | Meaning                                                                                                      |
| ----- | ------------------------------------------------------------------------------------------------------------ |
| `201` | accepted; body carries the ERP order `id`, `reference`, status, and `stock_reservation.expires_at`           |
| `409` | `duplicate_storefront_order` — this `storefront_order_reference` already exists                              |
| `422` | `insufficient_stock`, `price_mismatch`, `product_unavailable`, `payment_unverified`, `invalid_mobile_number` |

`storefront_order_reference` is unique per website and is a **second** duplicate guard beneath the
idempotency key — protecting against a storefront that retries with a fresh key (§17.3, §43).

### 6.1.1 Price mismatch — zero tolerance

The ERP independently recalculates **product price, discount, coupon, tax, delivery charge,
fulfillment charge, and final totals**, comparing in integer minor units against the correct
currency. **A difference of one poisha is a rejection.**

```json
{
    "error": {
        "code": "price_mismatch",
        "message": "Prices have changed since this cart was built. Ask the customer to review the updated total.",
        "details": {
            "authoritative": {
                "items": [
                    {
                        "sku": "FW-1043-NVY-M",
                        "quantity": 2,
                        "unit_price": {
                            "minor_units": 259000,
                            "currency": "BDT",
                            "decimal": "2590.00"
                        },
                        "line_total": {
                            "minor_units": 518000,
                            "currency": "BDT",
                            "decimal": "5180.00"
                        }
                    }
                ],
                "subtotal": { "minor_units": 518000, "currency": "BDT" },
                "discount": { "minor_units": 0, "currency": "BDT" },
                "tax": { "minor_units": 0, "currency": "BDT" },
                "shipping": { "minor_units": 6000, "currency": "BDT" },
                "grand_total": { "minor_units": 524000, "currency": "BDT" }
            },
            "submitted_grand_total": {
                "minor_units": 504000,
                "currency": "BDT"
            }
        },
        "request_id": "01J8Z9..."
    }
}
```

Rules that make this safe:

- The response carries **only selling-side figures** — enough to refresh the customer's cart.
  Wholesale price, base cost, internal margin, and the partner's earning are never present, in
  this or any other error (D12).
- The storefront **must show the customer the revised total and get an explicit reconfirmation**
  before resubmitting. It must not silently accept the new price on the customer's behalf, and it
  must not alter an order the customer already confirmed.
- Resubmission is a **new** request with a **new** idempotency key.

### 6.1.2 Stock reservation

Accepting an order reserves stock inside the same database transaction that creates it. Every
reservation carries a stored `expires_at`; reservation and release both run under a distributed
lock and are idempotent, so a retry can never double-reserve or double-release.

| Order type         | Reserved when                                      | Default window                   | Becomes committed on           | Released on                                 |
| ------------------ | -------------------------------------------------- | -------------------------------- | ------------------------------ | ------------------------------------------- |
| **Online payment** | ERP accepts checkout and creates the pending order | **15 minutes**                   | verified successful payment    | failed, cancelled, or expired payment       |
| **COD**            | ERP accepts the COD order                          | **24 hours** confirmation window | customer or admin confirmation | expiry, cancellation, rejection, or failure |

Both windows are configurable in ERP settings; the values above are the v1 defaults.

- Expired reservations are released by the **scheduler**, not lazily at read time — so stock frees
  up predictably rather than only when someone happens to look.
- An authorized user may override a reservation manually; the override is **written to the audit
  log** (§36.2).
- **Overselling and negative stock are never permitted**, under any race, retry, or override.

**Late payment callback.** If a successful payment arrives after the reservation expired and the
stock is gone, the ERP does **not** create negative stock and does **not** silently cancel. The
order moves to **manual review** and enters the configured refund-or-resolution workflow. The
customer has paid; a human decides what happens next.

The `201` response includes the reservation deadline so the storefront can show the customer how
long they have:

```json
{
    "id": "01J8Z9...",
    "reference": "ORD-260901-K7M3QX9P",
    "status": "payment_pending",
    "stock_reservation": { "expires_at": "2026-09-01T14:47:00+00:00" }
}
```

### 6.2 Customers and guest checkout

```
POST  /customers
PATCH /customers/{id}
```

Guest checkout is supported and is the default path for a customer who has not registered.

**Required for a guest:** full name · normalized mobile number (§4.3.1) · shipping address ·
billing address when it differs. **Email is optional.**

#### Identity

- A storefront customer is keyed on **`(website_id, normalized_mobile)`**.
- **The same mobile number on two partner websites is two separate customers.** Partner A's
  customer list must never be inferable from Partner B's, and one partner must not learn that a
  person also shops with another.
- A guest checkout **never creates a Feriwala ERP user account** — an ERP account means KYC,
  packages, and a wallet, none of which apply to someone buying a shirt.
- The ERP assigns the customer a `public_id`, which is what appears in API payloads.

#### Order snapshots

Every order stores an **immutable snapshot** of the customer and both addresses as they were at
the time of the order. A later profile edit — a corrected name, a new address — must never rewrite
what a historical order says was shipped and to where. The live customer record and the order
snapshot are separate data with separate lifetimes.

#### Access to history — the part that must not be got wrong

Reusing a customer record for reporting and future orders is fine. **Reading that record's history
is not.**

- Entering a mobile number that already exists grants **nothing**. No order list, no addresses, no
  name, no confirmation that the number is known.
- Viewing previous orders or any sensitive customer data requires **OTP verification** of that
  mobile number, or an authenticated storefront customer session.
- OTP verification is configurable **per website and per order type**.

The failure mode this closes is simple: without it, anyone who guesses or knows a phone number
could enumerate that person's purchase history, addresses, and spending. Existence itself must not
leak — a lookup for an unknown number and a lookup for a known one are indistinguishable until OTP
succeeds.

### 6.3 Returns and refunds

```
POST /orders/{id}/return-requests
POST /orders/{id}/refund-requests
```

Both create a **request**, never an executed action. Approval, inspection, stock disposition, and
any refund payment happen in the ERP under the roles that own them (§18.2, §26.3). A storefront can
ask; it can never move money or stock.

---

## 7. Webhooks — ERP notifies storefront

The ERP `POST`s to the website's configured endpoint.

```
POST <website webhook_url>
X-Feriwala-Event: product.updated
X-Feriwala-Delivery: 01J8Z9K3M7QX2P4R6T8V0W1Y3B
X-Feriwala-Timestamp: <unix seconds>
X-Feriwala-Signature: sha256=<hex>
```

Signature is `HMAC-SHA256(webhook_secret, timestamp + "." + raw_body)`. The storefront **must**
verify it in constant time and reject anything older than 300 seconds.

### 7.1 Events

| Event                                                           | Fires when                                               |
| --------------------------------------------------------------- | -------------------------------------------------------- |
| `product.published` / `product.updated` / `product.unpublished` | selection or content changes                             |
| `product.price_changed`                                         | website selling price changes                            |
| `inventory.updated`                                             | available quantity changes                               |
| `inventory.out_of_stock`                                        | availability reaches zero                                |
| `category.updated`                                              | category tree or content changes                         |
| `order.status_changed`                                          | any order status transition                              |
| `order.cancelled`                                               | order cancelled in the ERP                               |
| `shipment.updated`                                              | courier status or tracking number changes                |
| `return.status_changed`                                         | return progresses                                        |
| `refund.completed`                                              | refund settled                                           |
| `website.suspended` / `website.restored`                        | package expiry, low balance, grace period (§16.4, §24.3) |

### 7.2 One endpoint per website

`v1` supports **exactly one active outbound webhook endpoint per partner website.**

- Each website has its own URL and its own **scoped signing secret**.
- **One website never receives another website's events.** Event fan-out is filtered by website
  ownership before dispatch, not at the consumer.
- **Secret rotation** is supported: during a configurable transition window the ERP signs with the
  current secret while the **previous secret remains valid for verification**, so a partner can
  roll a secret without dropping deliveries. The old secret stops being accepted when the window
  closes.

Multiple endpoints and per-event subscriptions are deliberately out of scope for `v1`; they can
arrive in a later version without breaking this one.

### 7.3 Delivery guarantees

- Delivery is **asynchronous and queue-based** — a slow or unreachable storefront never blocks an
  ERP request.
- **At least once.** `X-Feriwala-Delivery` is stable across retries; the storefront deduplicates
  on it and **must tolerate duplicate delivery**.
- **Ordering is not guaranteed.** Every payload carries `occurred_at` and the entity's
  `updated_at`; a consumer must ignore an event older than what it already holds.
- **Retries:** 8 attempts with exponential backoff (10s, 30s, 2m, 10m, 30m, 2h, 6h, 12h) on any
  non-`2xx` or timeout. A 10-second response timeout applies — acknowledge fast, process
  asynchronously.
- After the retry limit the delivery moves to a **failed / dead-letter state**, where an
  authorized user can **inspect and manually retry** it (§17.2). Repeated failures degrade the
  website's `connection_health`.

### 7.4 Delivery record

Every attempt is recorded, so a partner asking "did you send it?" has a factual answer:

| Field                                              | Purpose                                                          |
| -------------------------------------------------- | ---------------------------------------------------------------- |
| `event_id`                                         | stable identity across retries; the consumer's deduplication key |
| `website_id`                                       | ownership — and the filter that guarantees isolation             |
| `event_type`                                       | e.g. `inventory.updated`                                         |
| `payload_version`                                  | lets payload shape evolve independently of the API version       |
| `attempt`                                          | 1-based attempt counter                                          |
| `response_status`                                  | HTTP status returned, or null on timeout                         |
| `dispatched_at` / `responded_at` / `next_retry_at` | timing and backoff state                                         |
| `state`                                            | `pending`, `delivered`, `retrying`, `failed`                     |

### 7.5 What webhooks are not

A webhook is a **hint that something changed**, not a source of truth. For anything that matters —
price, stock at checkout — the storefront re-reads from the API. This is deliberate: it means a
lost, delayed, or out-of-order webhook degrades freshness, never correctness.

---

## 8. Compatibility rules for the storefront

The storefront **must**:

1. Ignore unknown JSON fields rather than failing to parse.
2. Tolerate unknown enum values (status, event type) by treating them as "unrecognised" and not
   crashing — this is what lets the ERP add the admin-defined order statuses of §18.2 without a
   coordinated release.
3. Treat every list as paginated.
4. Retry `429` and `5xx` with exponential backoff and jitter; never retry `4xx` other than `429`.
5. Send an `Idempotency-Key` on every write.
6. Verify webhook signatures and deduplicate on delivery id.
7. Never cache catalog data past its `updated_at` without revalidating at checkout.

---

## 9. Health and observability

```
GET /health          — liveness, unauthenticated, no data
GET /connection      — authenticated; credential status, scopes, rate limit, last sync
```

Every request writes an `api_logs` row: credential, endpoint, status, duration, request id, with
signatures, secrets, and personal data redacted (§42).

---

## 10. Resolution of the four open items

All four were answered on 2026-09-01 and are now folded into the sections above. Recorded here so
the reasoning survives.

| #   | Question                         | Decision                                                                                                                                                                                                                                                                                                                                                                 | Where            |
| --- | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------- |
| 1   | Guest checkout customer identity | Guest checkout supported. Identity keyed on `(website_id, normalized_mobile)`; same number on two websites is two customers; no ERP account created; ERP-assigned `public_id`; immutable per-order customer and address snapshot; **history access gated behind OTP or an authenticated session**, configurable per website and order type                               | §4.3.1, §6.2     |
| 2   | Stock reservation expiry         | Configurable, with v1 defaults of **15 minutes** for online payment and a **24-hour** COD confirmation window. Stored `expires_at`, transactional and lock-guarded, idempotent, released by the scheduler, audited manual override, **never negative stock**. A late successful payment against expired stock goes to **manual review**, not to cancellation or oversell | §6.1.2           |
| 3   | Price mismatch tolerance         | **Zero.** One minor unit is a rejection. `422` with the authoritative selling-side totals so the cart can refresh; **never** wholesale price, base cost, or margin. Storefront must obtain explicit customer reconfirmation and resubmit with a new idempotency key                                                                                                      | §6.1.1           |
| 4   | Webhook endpoints                | **One active endpoint per website** with its own scoped secret, plus secret rotation with a previous-secret grace window. Async and queued, at-least-once, dead-letter state with authorized manual retry, full delivery record. Multiple endpoints and per-event subscriptions deferred past `v1`                                                                       | §7.2, §7.3, §7.4 |

## 11. Implementation obligations this contract creates

Recorded so they are not rediscovered mid-build. Each becomes a task in its phase:

| Obligation                                                                      | Phase   |
| ------------------------------------------------------------------------------- | ------- |
| Mobile normalisation service + `invalid_mobile_number` handling                 | P3 / P5 |
| `(website_id, normalized_mobile)` unique index on storefront customers          | P5      |
| Immutable customer + address snapshot columns on orders                         | P6      |
| OTP gate for guest order history, configurable per website and order type       | P5      |
| `stock_reservations.expires_at`, scheduler release job, audited manual override | P3      |
| Configurable reservation windows in ERP settings (online 15m, COD 24h)          | P3      |
| Manual-review state and refund/resolution workflow for late payments            | P6      |
| Authoritative recalculation service shared by ERP and storefront checkout       | P4 / P5 |
| Webhook secret rotation with previous-secret grace window                       | P5      |
| Delivery record table + dead-letter state + manual retry UI                     | P5      |
