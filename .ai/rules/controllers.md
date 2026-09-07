---
paths:
    - app/Http/Controllers/DashboardController.php
---

# Controllers

## The dashboard only ever serves activated business accounts

`/dashboard` sits behind `['auth', 'verified', 'business.activated']`. That gate sends an unactivated account to `onboarding.status` and sends platform staff — who have no business account (D23) — to their own admin queue via `HomeRoute`. So do not build onboarding-funnel branches or admin/queue panels into this page: both were tried and both are unreachable by construction.

What does reach it is six statuses, not one. `AccountStatus::isActivated()` admits `PackageRenewalDue`, `PackageExpired`, `LowWalletBalance`, `WalletTopupRequired` and `TemporarilyRestricted` alongside `Active`, and §33.3 asks the dashboard to carry exactly those. An account whose package lapsed yesterday is on this page.
