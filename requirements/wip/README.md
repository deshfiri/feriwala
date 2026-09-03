# Parked: the D23 identity / business-account split

**Nothing in this directory is wired into the application.** It is not autoloaded, not referenced,
and not executable. The repository is green without it.

## Why it is parked here rather than in place

The schema half of [D23](../04-decisions.md#d23--user-identity-vs-business-account-2026-09-03) is
designed and verified; the application half — roughly thirty files and their tests — is not
converted yet. A migration sitting in `database/migrations/` would run on the next `migrate` and
leave the code talking to columns that had moved. A half-wired schema is worse than no schema
change at all, so the migration is held outside that directory with a `.proposed` suffix, where
Laravel will not find it.

The models are here for the same reason. They compile, but `BusinessAccount` addresses tables that
do not exist until the migration runs, so leaving them under `app/` would invite something to
reference them before that is true.

## What is here

| File                                                                     | What it is                                                                                                                                                                                                                                                                                                               |
| ------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `2026_09_03_110000_separate_identity_from_business_account.php.proposed` | The schema move. Creates `business_accounts` and `business_account_members`, backfills one account per user from their personal team, repoints `kyc_submissions` / `payments` / `user_packages` / the status history onto the account, moves the current-subscription pointer, and reduces `users` to `identity_status`. |
| `models/UserStatus.php`                                                  | The identity lifecycle: Active, Locked, Suspended, Closed. Small on purpose — §6 asks for lock, unlock, suspend and close, and the commercial lifecycle stays on `AccountStatus`.                                                                                                                                        |
| `models/BusinessAccount.php`                                             | The commercial workspace. One owner, one account, enforced by a unique index.                                                                                                                                                                                                                                            |
| `models/AccountMembership.php`                                           | A person's place in one account. `user_id` is unique across the whole table: D1 puts a person in exactly one business.                                                                                                                                                                                                   |
| `models/BusinessAccountStatusChange.php`                                 | The account's status history, append-only.                                                                                                                                                                                                                                                                               |

## Verified

The migration applies and rolls back cleanly, twice each, against PostgreSQL 18.6. Two ordering
traps were found and fixed in the process:

- PostgreSQL does not rename a table's constraints along with the table, so the status-history
  columns are repointed **before** the rename, not after.
- `users.current_user_package_id` had to move too; an invited staff member has no package of their
  own, and leaving the pointer on `users` would give every staff member an always-empty
  subscription slot.

## How to bring it in

Task **P1-78**, in the order set out in the decision: finalise the ownership boundary, add
membership rules, move activation state, move the commercial relationships, add the global identity
gate, add the business-activation gate on ERP routes only, leave admin on identity plus permissions,
update the actions and queries — and only then move the migration into `database/migrations/` and
run forward, back and forward again.
