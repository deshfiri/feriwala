# Domain layer

One folder per business module. Modules are boundaries, not packages — a single Laravel
application, a single migration set, a single test suite, with the seams drawn here.

## Layout inside a domain

```
Domain/<Name>/
    Models/       Eloquent models — relationships, casts, scopes. No workflow.
    Actions/      Business operations. One class, one public handle(). This is where logic lives.
    Data/         DTOs crossing boundaries (into actions, out to Inertia).
    Enums/        Statuses and typed vocabularies. Status enums implement TransitionableState.
    Events/       Domain events other modules may react to.
    Listeners/    Reactions to other modules' events.
    Jobs/         Queued work.
    Policies/     Authorization. Every model has one — no exceptions.
    Queries/      Read models and report queries, including self-scoping.
    Rules/        Validation rules specific to this domain.
    Services/     Long-lived collaborators that do not fit an Action.
```

Create subfolders when you need them, not before.

## Rules

1. **Controllers validate, authorize, delegate, respond.** Business logic belongs in an Action.
2. **Money is never a float.** Use `App\Support\Money\Money` and `App\Casts\MoneyCast`.
3. **Statuses move through `transitionTo()`**, never by assigning the attribute.
4. **Every financial mutation** runs in a database transaction with row locking, writes an
   immutable ledger entry, and is idempotent when externally triggered.
5. **Self-scoping is a query concern.** A non-admin must not be able to read another user's rows
   through a URL, an API call, an export, or a modified parameter (§31.3).
6. **Cross-domain calls go through Actions or Events**, not by reaching into another domain's
   models and rewriting their state.

## Reference

- Architecture: [requirements/01-architecture.md](../../requirements/01-architecture.md)
- Data model: [requirements/02-data-model.md](../../requirements/02-data-model.md)
- Approved decisions: [requirements/04-decisions.md](../../requirements/04-decisions.md)
