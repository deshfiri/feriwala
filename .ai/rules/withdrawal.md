---
paths:
    - 'app/Domain/Withdrawal/**'
---

# Withdrawal

## Wire KycRestrictions::blocksWithdrawals() when Client/Partner withdrawals are built

`KycConsequence::BlockWithdrawals` and `KycRestrictions::blocksWithdrawals()` exist and are settable by staff on a §7.4 re-verification, but nothing enforces them: `app/Domain/Withdrawal/` is empty, so there is no Client/Partner withdrawal action to guard. Staff can choose "No new withdrawals" today and it does nothing.

When the Client/Partner withdrawal batch lands, its request action must call `blocksWithdrawals($account)` and refuse with `refusalReason($account)` — the same shape `PlaceWholesaleOrder` uses. Block only a _new_ request; a withdrawal already in flight is an existing obligation and is never cancelled by a KYC consequence.

Supplier withdrawals are a different owner type (`SupplierWithdrawal`) and are deliberately out of scope — KYC consequences attach to a `BusinessAccount`, not a `Supplier`.
