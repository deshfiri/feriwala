# 06 — Steadfast Courier: contract, behaviour and deployment

Steadfast is the first courier API behind the provider-neutral shipment domain (D8). This note
records what Banij relies on, what is **verified**, what is **assumed**, and what must happen before
and after the first real booking.

## 1. Where the contract comes from

Steadfast publishes no complete API reference. The contract is taken from the package Steadfast
maintains: `steadfast-courier/steadfast-courier-laravel-package`
(<https://github.com/steadfast-it/SteadFast-Courier-Laravel-Package>), read from source
(`src/SteadfastCourier.php`, `config/steadfast-courier.php`, `README.md`). Nothing that package does
not demonstrate is implemented.

| Capability   | Method       | Endpoint                       | Request                                                                                                        | Response (as documented)                                                 |
| ------------ | ------------ | ------------------------------ | -------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| Create order | POST         | `/create_order`                | `invoice`, `recipient_name`, `recipient_phone`, `recipient_address`, `cod_amount` (optional `note`, not sent)  | `status`, `consignment.{consignment_id, invoice, tracking_code, status}` |
| Status       | GET          | `/status_by_invoice/{invoice}` | none                                                                                                           | `status`, `delivery_status`                                              |
| Balance      | GET          | `/get_balance`                 | none                                                                                                           | `status`, `current_balance`                                              |
| Webhook      | inbound POST | Banij's callback URL           | header `Authorization: Bearer <token>`; body `consignment_id`, `invoice`, `status`, `cod_amount`, `updated_at` | n/a                                                                      |

Every outbound call sends `Api-Key`, `Secret-Key` and `Content-Type: application/json`. Base URL:
`https://portal.steadfast.com.bd/api/v1`.

**Not implemented, because the package has no such call:** cancellation, return requests, pickup
requests, partial-delivery quantities, a settlement signal, bulk booking, status by consignment id or
tracking code (Banij always looks up by its own invoice), and a tracking-page link.

**A discrepancy inside the package.** Its README gives `portal.steadfast.com.bd`; its config file
defaults to `portal.packzy.com`. Banij uses the first. The second is not in
`courier.steadfast.allowed_hosts` and is refused in production until Steadfast's merchant panel names
it for the account.

## 2. Status vocabulary

Eleven documented values, matched exactly (no case folding, no trimming). Anything else is unknown.
Steadfast has no picked-up or in-transit value, so only `delivered` and `cancelled` move a shipment;
the shared transition map is walked step by step to get there.

| Steadfast value                      | Courier target | Provider-final | Review | Financial effect                          |
| ------------------------------------ | -------------- | -------------- | ------ | ----------------------------------------- |
| `pending`                            | —              | no             | no     | none                                      |
| `in_review`                          | —              | no             | no     | none                                      |
| `hold`                               | —              | no             | yes    | none                                      |
| `delivered_approval_pending`         | —              | no             | no     | none (waits for `delivered`)              |
| `partial_delivered_approval_pending` | —              | no             | yes    | none                                      |
| `cancelled_approval_pending`         | —              | no             | no     | none (waits for `cancelled`)              |
| `unknown_approval_pending`           | —              | no             | yes    | none                                      |
| `delivered`                          | Delivered      | yes            | no     | existing delivery pipeline only           |
| `partial_delivered`                  | —              | yes            | yes    | none: no design for partial quantities    |
| `cancelled`                          | Cancelled      | yes            | no     | none                                      |
| `unknown`                            | —              | no             | yes    | none                                      |
| _anything else_                      | —              | no             | yes    | none; stored as received, staff told once |

A move the transition map does not allow (for instance `cancelled` for a parcel already in transit)
is stored and flagged, never forced. **"Delivered" is not "cash collected":** `RecordCodCollection`
stays the only path that records collection, and Banij never calls it from a courier status. The
webhook's `cod_amount` is ignored.

## 3. Behaviour that matters in operation

- **One booking per order, never blind.** A pending attempt is recorded under a row lock before the
  call (one live attempt per order, enforced by a partial unique index). A timeout, a dropped
  connection or an unreadable answer leaves it _uncertain_; it is reconciled by invoice and never
  retried until Steadfast says it has no such parcel (HTTP 404) or a person confirms, with a
  reason, that it was not booked.
- **Booking only asks a courier to take the parcel.** The order must already be Dispatched (the step
  that consumes stock). Booking does not dispatch, move stock, record COD, credit profit, or mark
  anything picked up or delivered.
- **Webhook and poll are one code path** (`ApplySteadfastStatus`), idempotent under a row lock.
- **Webhook security:** full `Authorization: Bearer …` compared in constant time before the body is
  read; 16 KiB cap; JSON only; the five documented fields; only a body hash is stored; an unmatched
  or repeated event answers the same success.
- **Secrets:** API key, secret key and webhook token are encrypted settings (`courier.steadfast.*`),
  masked in the UI and never sent to React. The one exception is a freshly generated webhook token,
  returned once in a `no-store` JSON response so it can be pasted into Steadfast.
- **SSRF:** in production the base address must be `https` on an allow-listed host with no
  credentials, port, query or fragment; redirects are never followed.

## 4. Deploying

1. Run the migrations. Two are additive: `2026_11_17_100000_add_provider_tracking_to_shipments` and
   `2026_11_17_110000_allow_system_shipment_tracking_events`.
2. Make sure the scheduler runs (`php artisan schedule:work` or the usual cron). `courier:steadfast-sync`
   runs every five minutes, `onOneServer` and `withoutOverlapping`, and does nothing while Steadfast
   is switched off. Run it by hand with `php artisan courier:steadfast-sync --limit=20`.
3. Grant `courier.manage_integrations` (Courier Manager has it) to whoever configures Steadfast.
4. Settings → **Steadfast Courier**: enter the API key and secret key, leave the address as the
   default, **save**, then **Test connection**. It reads the balance only: no booking, shipment or
   charge.
5. Same page: **Generate token**, copy it, and in Steadfast's merchant panel enter the **Callback URL**
   shown on the page and the token as the **Auth Token (Bearer)**. Whether the merchant panel offers
   webhook configuration for the account has not been confirmed.
6. Only then switch Steadfast **on**. It cannot be switched on while the keys are missing.

Nothing needs an environment variable. Do not put credentials in `.env`, in config, or in tests.

## 5. First real use: what to check before trusting it

Confirmed only against the package source, never against the live service. Before the first real
booking, with credentials configured through Settings:

- **Test connection** succeeds, and the base URL actually used is `portal.steadfast.com.bd`.
- A **read-only** `status_by_invoice` for an invoice that does not exist: confirm it is an HTTP 404
  (Banij reads 404 as "no such parcel"; anything else leaves a booking uncertain).
- The shape of a rejected key (Banij treats 401/403 as authentication, 422 as validation).
- Whether the merchant panel exposes webhook settings, and which status values the webhook actually
  sends (the package does not say; an unrecognised value is flagged, not guessed).
- Whether `recipient_address` or `invoice` have length or format limits (none are invented here).

A real `create_order` creates a live, possibly chargeable consignment. It is not run by any test and
must not be run without explicit approval.

## 6. Rolling back

Switch Steadfast off in Settings: booking and the scheduled poll stop. The callback does **not** stop:
it keeps applying statuses for parcels already booked. To refuse it as well, generate a new token and
do not give it to Steadfast. Existing shipments, attempts and receipts are kept; shipments are never
deleted. Both migrations have working `down()` methods.
