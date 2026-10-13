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

**Stated host (additive, D30).** A storefront serving several websites from one deployment may send
`X-Feriwala-Storefront-Host: shop.example.com` with any request. When it is present, the ERP
resolves that host itself — the website's own subdomain, or one of its verified and active custom
domains — and answers `403 host_mismatch` unless the host belongs to the website that owns the
credential. Without the header, nothing changes. The header can only narrow a request; it never
selects a website on its own.

---

## 4. Conventions

### 4.1 Money

Always an object, never a bare number — mirroring `Money::jsonSerialize()` so the storefront never
performs arithmetic on a float:

```json
{ "amount": "1234.56", "currency": "BDT", "formatted": "৳1,234.56" }
```

`amount` is **flat Taka** as a decimal string: `"100"` is BDT 100 and `"100.50"` is BDT 100.50.
Nothing on either side multiplies or divides by 100. `formatted` is informational for display.

This matches Feriwala's own convention exactly (D26) — there is no translation layer between this
API and the ERP, and no boundary at which the meaning of a number changes.

#### Minor-unit compatibility period

Before 2026-09-23 this field was `{"minor_units": 123456, "currency": "BDT", "decimal": "1234.56"}`,
where the amount was an integer count of poisha. Adding `amount` is a **non-breaking addition**
under §8 and ships into v1; no v2 is required.

**Outbound**, every money object carries `amount` **and**, for the compatibility period, the legacy
`minor_units` and `decimal` keys alongside it. Existing integrations keep working unchanged.

**Inbound**, a money object may supply either `amount` (preferred) or `minor_units` (legacy).
Exactly one is accepted per object: supplying both is a `422`, not a silent preference, because a
payload that disagrees with itself is the single way a 100-times error could enter. A legacy
`minor_units` value is converted once, at the edge, by an explicit compatibility adapter; nothing
behind that adapter carries minor units.

**Ending the period.** The legacy keys are removed in v2, not silently from v1. Until then:

- Integrations should migrate to `amount`. It is unambiguous and needs no scaling.
- New endpoints and new fields are `amount`-only from the outset.
- No new internal Feriwala code reads or writes `minor_units`; the repository guard test enforces
  this, and the adapter is its one documented exemption.
- Tests assert that a legacy-shaped payload and a flat-Taka payload for the same order produce
  identical stored amounts — proving the adapter can cause neither a 100-times overcharge nor a
  100-times undercharge.

Price-mismatch comparisons (§6.1.1) are exact decimal comparisons at two decimal places. The
zero-tolerance rule is unchanged in substance: one poisha of difference is still a rejection, now
expressed as `0.01`.

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

**Per website as well (additive, D30).** The same budgets also apply to each website across all of
its credentials together, so issuing a second credential never doubles a website's allowance. A
request must fit both budgets; the headers report whichever has less left.

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
GET  /products                     ?updated_since= &cursor= &limit= &category= &status=   (+ §5.1.2)
GET  /products/facets              (additive, §5.1.2)
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
                "amount": "2490.00",
                "currency": "BDT",
                "formatted": "৳2,490.00"
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

#### 5.1.1 What is listed (v1 compatibility correction, D31)

A product appears on every catalogue endpoint — list, detail, facets, inventory — only while it is
**published on the calling website and eligible for that website's owner to sell by
dropshipping**, the same eligibility order pricing applies. A trashed, inactive, channel-disabled or
package-excluded product, or one in a switched-off category, is never listed, so nothing listed is
refused at checkout for being ineligible. Before D31 an ineligible product could still be listed and
was only refused at `POST /orders`; that was a defect, and the correction narrows no field.

#### 5.1.2 Search, filters and sorting (additive, D31)

```
GET  /products   ?q= &sort= &price_min= &price_max= &category= &brand= &attributes[<slug>]=
                 &in_stock= &featured= &flash_deal= &status= &updated_since= &cursor= &limit=
GET  /products/facets                same filters, no cursor
```

| Parameter                    | Meaning                                                                                             |
| ---------------------------- | --------------------------------------------------------------------------------------------------- |
| `q`                          | up to 100 characters; matches name, BPC or an active variation's BPC, case-insensitively, literally |
| `sort`                       | `featured`, `newest`, `price_asc`, `price_desc`, `name`, `best_selling`; omitted means id order     |
| `price_min` / `price_max`    | flat Taka (`"2500"` or `"2500.00"`), on the price a customer is charged now, promotion included     |
| `category`                   | the shop's own category, else a catalogue category and its subcategories (slug or id)               |
| `brand`                      | brand slug or id                                                                                    |
| `attributes[<slug>]=<value>` | up to five; a product matches when an active variation carries each value                           |
| `in_stock`                   | `true`/`false` — the same figure the product's own availability shows                               |
| `featured`                   | `true`/`false` — the website's featured flag                                                        |
| `flash_deal`                 | `true`/`false` — a promotion in force now that has an end time                                      |
| `status`                     | `published` only; it is the only status this surface ever shows                                     |

Every sort ends on a stable tie-breaker, so cursor pages never repeat or skip a product. An unknown
sort or status, a malformed amount, or `price_min` above `price_max` is `422 validation_failed`.

`best_selling` ranks by the last 30 days of sales on the calling website: paid online orders and
cash-on-delivery orders the customer confirmed, placed in the window and not since cancelled,
refund pending, partially refunded or refunded, counted in units net of returned goods received.
Ties go to the product with more orders, then to the earlier selection; a product with no sales
in the window follows every one that sold. **Freshness:** the figures are rebuilt every fifteen
minutes, so a sale can take up to that long to move a product. No sales figure is exposed.

`/products/facets` returns `brands` (id, slug, name, product count), `attributes` (slug, name, and
values with product counts) and `price_range` (`minimum`, `maximum`, or `null`), over exactly the
products the same filters list. It is bounded: at most 200 brands, 50 attributes and 100 values per
attribute.

#### 5.1.3 Promotions and flash deals (additive, D31)

A partner may give a promotional price a window (`starts_at` inclusive, `ends_at` exclusive, either
optional, stored in UTC). While it applies, `price` is the promotional price and
`compare_at_price` the regular one; outside it, `price` is the regular price and
`compare_at_price` is `null`. The product payload adds `promotion: { starts_at, ends_at }` while a
promotion applies, `null` otherwise. Each opening and closing sends one `product.price_changed`.
Order pricing uses the same rule at the moment the order is priced.

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

### 5.4 Website configuration (additive, D30)

```
GET  /site                         scope: catalog:read
```

What the storefront needs to present the calling website: its name and public addresses, whether
it is open, under maintenance or unavailable, whether it is accepting orders, its branding
(theme, colours, logo, banner, tagline, about), its public contact details, its default and
available locales, its currency, and its checkout settings (payment methods and the
cash-on-delivery limit). The website is the credential's; nothing about the owner's account,
package, wallet, suppliers or other websites is ever included, and no secret is.

### 5.5 Delivery areas (additive, D31)

```
GET  /delivery/areas               ?parent=     scope: catalog:read
```

The Bangladesh administrative directory: divisions when `parent` is omitted, otherwise the active
children of `parent`. Each area is `{ id, type, name: { en, bn }, has_children }`; `type` is
`division`, `district`, `upazila` or `union`, and `id` is a stable identifier such as
`district:3:26`, never a database id. At most 1,000 areas per response; an unknown `parent` is
`404`. The same for every website, so it may be cached for an hour. Delivery is still charged at the
flat per-package fee whatever area is chosen.

---

## 6. Endpoints — storefront writes to ERP

### 6.0 Quote a cart (additive, D31)

```
POST /checkout/quote               scope: orders:write   no Idempotency-Key
```

```json
{
    "items": [{ "sku": "FW-1043-NVY-M", "quantity": 2 }],
    "payment_method": "online",
    "delivery": { "area_id": "district:3:26" },
    "coupon_code": null
}
```

What the cart would cost now, from the same pricing and checkout rules `POST /orders` uses: each
line's unit price and total with its advisory availability (`in_stock`, `quantity`, `reservable`),
the subtotal, discount, shipping, tax, `tax_included`, grand total, the delivery basis (`flat`),
the payment methods, cash-on-delivery eligibility and limit, `quoted_at` and an advisory
`valid_until`.

**A quote is advisory and writes nothing** — no order, payment, reservation, charge or ledger entry
— and is sent `Cache-Control: no-store`. `reservable` applies the reservation rules read-only
(one warehouse, existing reservations, the preferred supplier offer, the owner's allocation); stock
is held only by `POST /orders`. `POST /orders` prices the cart again and refuses any difference
with `price_mismatch` (§6.1.1), so a price change, a flash deal closing or stock running out between
quote and order is caught there. It refuses what `POST /orders` refuses, with the same codes:
`coupon_not_applicable`, `website_not_accepting_orders`, `product_unavailable`,
`quantity_not_allowed`, `payment_method_unavailable`; a repeated BPC or an unknown `area_id` is
`422 validation_failed`. It spends the read rate-limit budget.

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
            "unit_price": { "amount": "2490.00", "currency": "BDT" }
        }
    ],
    "totals": {
        "subtotal": { "amount": "4980.00", "currency": "BDT" },
        "discount": { "amount": "0.00", "currency": "BDT" },
        "shipping": { "amount": "60.00", "currency": "BDT" },
        "tax": { "amount": "0.00", "currency": "BDT" },
        "grand_total": { "amount": "5040.00", "currency": "BDT" }
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

`shipping_address.area_id` (additive, D31) is optional: an id from `GET /delivery/areas`, stored
with the address. An unknown one is `422 validation_failed`.

**`payment.return_url`** is where the customer is sent after paying (v1 compatibility correction,
D31). It must be `https://` on the standard port, with no username or password, no fragment,
nothing outside printable ASCII and at most 2,048 characters, and its host must belong to the
calling website **now**: its storefront subdomain, or a custom domain that is verified and active.
Anything else is `422 return_url_not_allowed`. It is checked again when the customer returns; if it
no longer holds — the domain lapsed, was suspended or moved — the customer goes to the website's
home page instead. Before D31 a domain recorded on the website was accepted whether or not it was
verified.

### 6.1.1 Price mismatch — zero tolerance

The ERP independently recalculates **product price, discount, coupon, tax, delivery charge,
fulfillment charge, and final totals**, comparing exact decimals at two places against the correct
currency. **A difference of `0.01` is a rejection.**

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
                            "amount": "2590.00",
                            "currency": "BDT",
                            "formatted": "৳2,590.00"
                        },
                        "line_total": {
                            "amount": "5180.00",
                            "currency": "BDT",
                            "formatted": "৳5,180.00"
                        }
                    }
                ],
                "subtotal": { "amount": "5180.00", "currency": "BDT" },
                "discount": { "amount": "0.00", "currency": "BDT" },
                "tax": { "amount": "0.00", "currency": "BDT" },
                "shipping": { "amount": "60.00", "currency": "BDT" },
                "grand_total": { "amount": "5240.00", "currency": "BDT" }
            },
            "submitted_grand_total": {
                "amount": "5040.00",
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

### 6.1.3 Confirming a COD order

```
POST /orders/{id}/confirmation/code     → 202, a code is sent to the customer's own number
POST /orders/{id}/confirmation          → 200, { "code": "123456" }
```

A COD order is created `customer_verification_pending`, **not** paid and **not** confirmed. The
ERP sends a six-digit code to the mobile number the order was placed with and the customer
confirms with it; confirming commits the reserved stock and moves the order to `confirmed`. The
money is collected on delivery and settled in the ERP (§28) — no invoice is issued here.

- The code is **never** returned to the storefront, in any response, error or webhook. It exists
  only in the SMS.
- Reading an order back carries `confirmation: { state, expires_at, resend_available_in }` — never
  the code and never how many attempts remain.
- A wrong code, a spent code and a code that was never issued get **one** answer
  (`confirmation_refused`, `422`); a closed window gets `confirmation_expired` (`409`). Which it
  was must not tell somebody guessing whether an order exists.
- Resends are held to a cooldown (`confirmation_code_too_soon`, `429`, with `retry_after`), and an
  order may be sent at most five codes in all (`confirmation_code_limit_reached`, `429`). A code
  the SMS provider did not take is resent by the ERP on its own, within the same limits.
- The window is the reservation's (§6.1.2). When it closes, the scheduler cancels the order and
  releases the stock exactly once.

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

**`return-requests`** (scope `returns:write`, idempotent like every write):

```json
{
    "reason": "not_as_described",
    "lines": [{ "sku": "FW-1043-NVY-M", "quantity": 1 }],
    "customer_note": "The colour is not what the picture showed.",
    "evidence": ["https://shop.example.com/uploads/photo-1.jpg"]
}
```

- `reason` is one of `damaged`, `wrong_item`, `not_as_described`, `missing_parts`, `changed_mind`,
  `other`.
- Only a **delivered or completed** order, inside the return window (seven days from delivery by
  default), for lines it sold and quantities not already claimed by an earlier return. Refusals:
  `order_not_returnable` (409), `return_window_closed` (409), `quantity_not_returnable` (422, with
  `returnable`), `line_not_on_order` (422), `nothing_returnable` (422).
- `201` with the return: `status` (`requested`, `approved`, `rejected`, `received`, `refunded`,
  `cancelled`), each line's `quantity`, `approved_quantity` and `received_quantity`, and
  `refund: { state, amount, refunded_at }`. Never a warehouse or what was done with the goods.
- Reading an order back carries `returnable` (what may still be sent back, per SKU, and until when)
  and `returns` (every return, newest first, with its customer-facing timeline).

**`refund-requests`** — a refund **without** goods coming back — is not served in this release. Who
decides such a refund and how its amount is set have not been decided; a refund for returned goods
follows the return and needs no separate request.

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

| Event                                                           | Fires when                                                         |
| --------------------------------------------------------------- | ------------------------------------------------------------------ |
| `product.published` / `product.updated` / `product.unpublished` | selection or content changes                                       |
| `product.price_changed`                                         | website selling price changes                                      |
| `inventory.updated`                                             | available quantity changes                                         |
| `inventory.out_of_stock`                                        | availability reaches zero                                          |
| `category.updated`                                              | category tree or content changes                                   |
| `order.status_changed`                                          | any order status transition                                        |
| `order.cancelled`                                               | order cancelled in the ERP                                         |
| `cod.confirmation_required`                                     | COD order awaiting the customer's code (§6.2)                      |
| `cod.confirmed`                                                 | customer confirmed a COD order with their code                     |
| `cod.expired`                                                   | COD confirmation window closed unconfirmed                         |
| `shipment.updated`                                              | courier status or tracking number changes                          |
| `return.status_changed`                                         | return progresses                                                  |
| `refund.completed`                                              | refund settled                                                     |
| `website.suspended` / `website.restored`                        | the website stops or starts being served (see below)               |
| `website.updated` (additive, D31)                               | branding, contact, images, serving hosts or payment methods change |

`website.suspended` fires when the website moves from a served status (active, low wallet balance,
grace period, a renewal pending, or maintenance) to one that is not — suspended, temporarily
disabled, package expired, closed or another unserved status — and `website.restored` on the way
back; the payload carries the new `status`. **Low wallet balance and the grace period are not
suspension**: the website is still served and still takes orders, so neither sends this event
(corrected by D31; the earlier row said otherwise).

`website.updated` carries only `{ id, updated_at }`: re-read `GET /site` (§5.4). It is not sent to
a website that is not being served. Payment methods are platform-wide: when a gateway change turns
online payment on or off in a currency, every served website selling in that currency is told; a
change that leaves the methods as they were tells nobody. At most one such event is pending per
website at a time, however many changes arrive before it is sent.

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

**The endpoint must be reachable from the internet and nowhere else** (D31). It must be `https://`
on the standard port with no credentials, and its name is resolved when it is registered and again
before every attempt, retries included: every address it resolves to must be public, so a name
pointing at a private, loopback, link-local, metadata or carrier-grade NAT address is refused. Each
attempt connects to exactly the address checked, so a DNS answer that changes in between cannot
redirect it, and a `3xx` is recorded as a non-`2xx` answer — a redirect is never followed.

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
| 3   | Price mismatch tolerance         | **Zero.** A difference of `0.01` is a rejection. `422` with the authoritative selling-side totals so the cart can refresh; **never** wholesale price, base cost, or margin. Storefront must obtain explicit customer reconfirmation and resubmit with a new idempotency key                                                                                              | §6.1.1           |
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
