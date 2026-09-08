---
paths:
    - 'app/Domain/Account/Policies/**'
    - 'app/Http/Controllers/Admin/**'
    - 'app/Domain/Account/Queries/**'
---

# Who may read an account

## `view` is for the account holder; `viewDossier` is for an administrator

`BusinessAccountPolicy::view` returns true for a **member** of the account. That is correct — a
person may look at their own business. It is also why it must never guard an administrative screen.

The administrative screens carry material written _about_ the applicant by somebody else:

- internal reasons on a KYC update request (`request_reason`)
- reviewer notes on a status change (`internal_note`)
- reviewer identities, decision metadata, and audit context

§7.2 puts all of that beyond the applicant. So `viewDossier` treats **membership as a refusal**,
exactly as `approveActivation` already did, and then requires the platform `account.view`
permission. Belonging to an account is never a route to the platform's view of it.

Both `admin/accounts/{account}` and `admin/activations/{account}` authorise `viewDossier`. Any
future admin screen about a business account must do the same. Reaching for `view` because it is the
obvious name is how this was wrong in the first place — an owner opening their own account's admin
URL was shown the reviewer's private notes about their own application.

Guarded by `tests/Feature/Account/AdminAccountDetailTest.php` (both screens) and
`tests/Feature/Account/ActivationReviewScreenTest.php`, which asserts a reviewer who also owns a
business is refused the screen rather than merely having its buttons withheld.

## A page prop must not shadow a shared prop

`HandleInertiaRequests` shares `account` — the **viewer's** own account, which the sidebar badge and
the navigation both read. An Inertia page prop of the same name silently overwrites it for the whole
shell.

The account detail screen returns its subject as `business` for this reason. It was `account` first,
and the sidebar then named the business being looked at while the "My business" group appeared for
platform staff who have no account at all. Nothing failed; it just quietly lied.

Before naming a page prop, check it against what `HandleInertiaRequests::share()` already publishes.

## Navigation entries scoped to "my account" need an account

Everything under the business group in `useNavigation` addresses the reader's own account. Platform
staff have none, so those links lead to a refusal — worse than their absence. Gate on `account`
being present, not only on permissions.
