The API behind the Feriwala mobile app. JSON over HTTPS, versioned in the path (`/api/mobile/v1`).

## Authentication

Sign in with `POST /auth/login` to get a bearer token, then send it on every other call:

```
Authorization: Bearer <token>
```

If the account has two-factor authentication on, login answers `202` with a `challenge_token`; finish with `POST /auth/two-factor-challenge`. A token belongs to one device. Tokens expire (30 days by default); a `401` means sign in again. `POST /auth/logout` revokes the current token.

A login that is suspended gets `403` with `code: account_unavailable` on any call, and its token is revoked.

## Language

Send `Accept-Language: en` or `bn`. Without it the person's saved language is used. Labels and messages come back in that language; every response carries `Content-Language`.

## Money

Money is **flat Taka**, never poisha. It is always an object produced by the server, with the amount as a decimal **string** and a currency code:

```json
{ "amount": "100.50", "currency": "BDT", "formatted": "৳100.50" }
```

Do not parse it into a float, and do not compute totals in the app — show what the server sends. Amounts you submit are decimal strings exactly as typed (`"100.50"`); more than two decimal places is rejected, never rounded.

## Identifiers

Resources are addressed by a public ULID or slug (`id`), never a database id. Anything not belonging to the signed-in person answers `404`.

## Business account gate

A business account must be activated before most of the API opens (onboarding, KYC, packages, profile, security and notifications are open earlier). Until then those calls answer `403` with `code: account_not_activated`; the app should send the person to onboarding. `code: no_business_account` means the login has no business (platform staff, invitees).

## Errors

| Status | Meaning                                                             |
| ------ | ------------------------------------------------------------------- |
| `401`  | Missing, expired or revoked token.                                  |
| `403`  | Not allowed. Check `code` (`account_not_activated`, …).             |
| `404`  | Does not exist, or is not yours.                                    |
| `422`  | Validation failed. `errors` maps each field to its messages.        |
| `429`  | Too many requests. Wait `Retry-After` seconds.                      |

Validation errors look like `{ "message": "…", "errors": { "field": ["…"] } }`.

## Pagination

List endpoints take `page` and `per_page` and return `data`, `links` and `meta` (`current_page`, `last_page`, `per_page`, `total`).

## Rate limits

120 requests per minute per signed-in person. Failed logins are throttled separately: 5 per minute per email and 30 per quarter-hour per address.
