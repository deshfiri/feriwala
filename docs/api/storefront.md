The API a partner's website uses to read the catalogue and place orders (`/api/storefront/v1`). **Frozen at v1:** fields may be added, nothing is removed or renamed without a v2. The full contract is `requirements/05-storefront-api-contract.md`; this page is its reference form.

## Authentication

There are no tokens. Every request is signed with the website's credential:

```
Authorization: Feriwala-HMAC-SHA256 Credential=<key_id>, Signature=<hex>
X-Feriwala-Timestamp: <unix seconds>
X-Feriwala-Nonce: <uuid v4>
```

The signature is `HMAC-SHA256(secret, canonical_request)`, where the canonical request is the method, the path plus sorted query string, the timestamp, the nonce and the hex SHA-256 of the raw body, one per line. HTTPS is required. A timestamp more than 300 seconds from server time, or a nonce seen in the last 600 seconds, answers `401`. Each credential carries scopes (`catalog:read`, `inventory:read`, `orders:read`, `orders:write`, `customers:write`, `returns:write`); a call outside them answers `403`. The credential identifies the website, so no call names one.

The signing string and header names are specified in contract §3.2 — the Swagger "Authorize" button cannot compute a signature, so use the contract's sample code to test.

## Idempotency

Every write must carry `Idempotency-Key: <uuid v4>`. The same key is answered once with the same bytes however many times it arrives.

## Money

Flat Taka. Amounts are in the additive `amount` field as a decimal string. The legacy `minor_units` key survives only through the compatibility adapter in contract §4.1.

## Rate limits

Separate budgets for reads, writes and other calls, per credential and per website. A `429` carries `Retry-After`.
