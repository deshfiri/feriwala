# Feriwala ERP — Planning Workspace

This folder holds my working plan for building **Feriwala ERP** from the specification in
[requirements.txt](../requirements.txt). Nothing here changes the spec — it is my reading of it,
plus the sequencing I intend to follow.

| Document                                     | Purpose                                                                                   |
| -------------------------------------------- | ----------------------------------------------------------------------------------------- |
| [00-understanding.md](00-understanding.md)   | What the system is, in my own words. Current repo state, what exists, what is missing.    |
| [01-architecture.md](01-architecture.md)     | Technical decisions: modular layout, money handling, state machines, extensibility seams. |
| [02-data-model.md](02-data-model.md)         | Domain-by-domain entity/table map.                                                        |
| [03-roadmap.md](03-roadmap.md)               | 13 phases, ordered by dependency, with exit criteria per phase.                           |
| [TODO.md](TODO.md)                           | **The master checklist.** Every task, grouped by phase, with stable IDs.                  |
| [99-open-questions.md](99-open-questions.md) | Decisions I need from you before or during specific phases.                               |

## Status

- **Spec read:** complete (2,185 lines, 46 sections)
- **Codebase surveyed:** complete
- **Plan:** drafted and verified, awaiting your approval
- **Implementation:** not started

## Verification pass (2026-09-01)

I re-checked the plan against the spec rather than trusting the first draft:

| Check                                       | Result                                                                                                 |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| All 46 spec sections referenced in the plan | ✅ — except §1, §3, §46, which are meta-sections, not requirements                                     |
| All 30 core modules in §3 map to a phase    | ✅ verified one by one                                                                                 |
| Task IDs contiguous, no gaps or duplicates  | ✅ 415 tasks across 13 phases                                                                          |
| Progress table matches actual task counts   | ✅                                                                                                     |
| Item counts quoted from the spec            | ❌ **six were wrong** — corrected, see the revision log in [TODO.md](TODO.md)                          |
| Scope sizing                                | ❌ **§16.1 storefront was one checkbox for a whole application** — now P5.D, +20 tasks, and Q11 raised |

Four smaller gaps were also closed: global search (§33.2), the §37 indexing checklist,
"request future KYC updates" (§7.2), and the user-facing sales/earnings view (§10.1).

## Ground rules I am holding myself to

1. Section 45 ("Final Core Requirements") and Section 44 ("Mandatory Business Rules") are
   non-negotiable. I will not silently drop, shrink, or substitute any of them. If something
   there turns out to be impractical, I raise it in [99-open-questions.md](99-open-questions.md)
   and wait for your call.
2. Every financial mutation goes through a database transaction, writes an immutable ledger
   entry, and is covered by a test.
3. Every "user can only see their own data" rule is enforced server-side (policy + query scope),
   not just hidden in the UI, and is covered by a test that tries to break it.
4. Product creation restrictions (Section 12) are enforced at UI, controller, policy, API, and
   validation layers — with tests that assert rejection.
5. No feature is "done" until it has: server authorization, validation, audit logging where
   sensitive, tests, and the full set of UI states (loading / empty / error / permission denied).
