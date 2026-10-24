---
paths:
    - 'app/Domain/Wallet/**'
---

# Wallet

## Wallets hold two kinds of money; the type is decided in the domain

A wallet (account and Supplier) is one balance with two kinds in it: `platform_credit` (spendable in Banij, never withdrawable) and withdrawable. Every ledger entry carries `balance_type`. Defaults live in `LedgerTransactionType::creditBalanceType()` (top-up, deposit, rewards = platform credit; sales, commission, COD, Supplier settlement = withdrawable); a manual credit MUST state its type. Platform credit becomes withdrawable only through `ConvertWalletBalance` / `ConvertSupplierWalletBalance` (`wallet.convert_balance`, reason, audit, two entries sharing `conversion_reference`); there is no reverse. Never write a wallet column outside `WalletService` / `SupplierWalletService`.

## The formulas (account wallet) — do not shortcut them

`platform_credit` is the only stored split. The kinds are _holdings_; what can be spent or withdrawn is the holding less every claim against it.

- **Total** = `total`
- **Reserved / Hold by type**: `reserved_platform_credit` and `hold_platform_credit` are stored; `reserved_withdrawable = reserved - reserved_platform_credit`, same for hold.
- **Pending**, **minimum balance**, **required deposit** are untyped: they always stand against _withdrawable_ money first.
- **Available to Spend** = `usableBalance()` = `total - reserved - hold - pending - minimum_balance - (required_deposit if the deposit may not cover charges)`, floored at 0.
- **Platform Credit Available** = `min(max(0, platform_credit - reserved_pc - hold_pc), Available to Spend)`
- **Withdrawable Available** (`availableForWithdrawal()`) = `max(0, (total - platform_credit) - reserved_w - hold_w - pending - minimum_balance - required_deposit)`

Never compute withdrawable as `total - platform_credit`: that is a holding, and it includes reserved, held and pending money. With no platform credit the formula equals the pre-typed `total - reserved - hold - pending - minimum_balance - required_deposit`, so legacy availability is unchanged.

Supplier wallet: `Withdrawable Available = max(0, (total - platform_credit) - reserved_w - recovery)`, `Available to Spend = max(0, total - reserved - recovery)`; recovery stands against withdrawable money.

## Claims remember their split; nothing converts silently

A reservation or hold records how much of it came from platform credit (`wallet_transactions.platform_credit_amount`, the wallet's `*_platform_credit` counters). Release, capture, refund and reversal reuse that split and never re-derive it. A withdrawal claims withdrawable money only; any other claim takes platform credit first. `debit(..., $only)` / `allocateSpend($amount, $only)` take the whole amount from one kind or fail — a reversal of a credit is taken from the kind it was credited as and is refused when that kind is gone, never paid from the other. A gateway top-up or deposit is platform credit, so its refund is taken from platform credit.
