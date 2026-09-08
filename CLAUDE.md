<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:

- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
    - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>

# Feriwala ERP

> Everything below is project-specific and sits outside the Boost block on purpose, so
> `boost:update` cannot overwrite it.

Feriwala is an ERP for wholesale and dropshipping. Read [requirements.txt](requirements.txt) for
the specification and [requirements/](requirements/) for the plan — start with
[requirements/04-decisions.md](requirements/04-decisions.md), which is the authoritative record of
what has been approved.

## Non-negotiables

These come from the specification and from approved decisions. Do not work around them; if one
seems wrong, raise it rather than routing past it.

1. **Money is never a float.** Use `App\Support\Money\Money` and `App\Casts\MoneyCast`. Stored as
   `BIGINT` minor units plus a `currency_code` column. All calculation is server-side — the client
   displays figures, never computes them.
2. **Ledger entries are immutable.** No update, no delete. Corrections are new adjustment,
   reversal, or corrective rows. The same applies to audit logs.
3. **Every financial mutation** runs inside a database transaction with row locking, is idempotent
   when externally triggered, and writes a ledger entry.
4. **Statuses move through `transitionTo()`**, never by assigning the attribute. Status enums
   implement `TransitionableState` and declare their own legal moves.
5. **Self-scoping is a query concern, not a UI one.** A non-admin must not reach another user's
   data through a URL, an API call, an export, or a modified parameter (§31.3).
6. **Only admins create products.** Regular users select from the central catalog. Enforced at UI,
   controller, policy, API, and validation layers, with tests that assert rejection (§12).
7. **Feriwala is merchant of record** for dropshipping. Partner websites never use their own
   gateway credentials. Retail margin is a commission _calculation base_, not a separate money
   path (D12).
8. **No database IDs in public URLs.** Use `HasPublicId` (ULID) or `HasSlug`.

## Architecture-locked

Changes to any of these must be raised for approval **before** implementation: merchant of record ·
financial ledger · payment direction · storefront separation · single account structure · product
ownership · wallet settlement · referral rewards.

The storefront API contract at
[requirements/05-storefront-api-contract.md](requirements/05-storefront-api-contract.md) is
**frozen at v1**. Non-breaking additions may ship into v1; anything breaking needs v2 and approval.

## Where things go

```
app/Domain/<Module>/     Models, Actions, Data, Enums, Events, Jobs, Policies, Queries, Rules
app/Integrations/        Payment, Sms, Courier drivers behind one contract each
app/Support/             Money, Concurrency, Idempotency, Rules, References, Localization
app/Concerns/            Model traits (HasPublicId, HasSlug, HasReference, HasStateMachine)
app/Http/Controllers/    {Public,Erp,Admin,Api,Webhook}
resources/js/components/ App components; ui/ is vendored shadcn — do not hand-edit
```

Business logic lives in **Actions**. Controllers validate, authorize, delegate, respond. Models
hold relationships, casts, and scopes — not workflow. See
[app/Domain/README.md](app/Domain/README.md).

## Environment

PostgreSQL 18 and Redis 8, running natively in WSL (decision D5, amended twice — read it before
changing versions). **Docker and Sail are not a development path** and nothing may come to require
them: PHP, PostgreSQL, Redis and Node all run natively, and the app is served by
`php artisan serve`. `compose.yaml` exists only as the production-parity reference. Production stays
container-ready; that is a deployment concern and does not reach back into local development. Redis carries cache, sessions, queues, rate limiting, and locks — on **four
separate databases**, with locks isolated so a cache flush cannot drop a lock guarding a financial
operation. Verified against the live server.

Tests run in a **`testing` schema** inside the same database, not a separate one, so the suite
needs no `CREATEDB` privilege. `DB_SEARCH_PATH` in `phpunit.xml` selects it.

**One suite at a time.** Every run shares that one schema, and `RefreshDatabase` opens by dropping
every table in it — so two concurrent runs deadlock on the drop and fail in ways that look exactly
like code defects (`SQLSTATE[40P01]`, scattered across whichever files happened to be running). If
you need a second run, give it a schema of its own:

```bash
psql -c 'CREATE SCHEMA IF NOT EXISTS testing_mine'
DB_SEARCH_PATH=testing_mine php artisan test
```

**P0-57** covers doing this automatically per run.

**Never switch session, cache, or queue to `file`/`sync`/`database` drivers**, even temporarily to
work around a local environment problem. The application must stay stateless and horizontally
scalable (§40, D10). `.env.example` is the reference.

## UI

Design tokens live in `resources/css/app.css`; the approved visual direction is a
neutral warm-grey surface with near-black primary actions and one restrained orange accent.

- Reuse `DataTable` for lists — it already covers search, filters, sort, server-side pagination,
  column visibility, bulk actions, and every state.
- Status is never conveyed by colour alone. Use `StatusPill`, which requires a label (§33.9).
- Every important screen needs its loading, empty, error, and permission-denied states. Components
  are in `resources/js/components/states/`.
- Money renders through `MoneyAmount`, always tabular. Pass `direction` explicitly — ledger rows
  store a debit as a positive amount with a debit type, so inferring from the sign is wrong.
- User-facing strings go through `useTranslation()` with lines in `lang/en` and `lang/bn`.

## Tests

Pest loads every test file into **one global function namespace**. A bare
`function resolver()` in one test file collides with the same name in another, and with
Laravel's own helpers (`session()`, `cache()`, `report()`) — the failure is a fatal
"Cannot redeclare" that takes the whole suite down, not one test.

So: **prefix every test helper with what it belongs to** — `dataScopeResolver()`,
`localeTestSession()`, `lockOverArrayStore()` — or define it inside the test closure.

Groups are declared in `tests/Pest.php`. Run a slice with `pest --group=wallet`.

## Checks

```bash
vendor/bin/pint --parallel        # PHP formatting
composer types:check              # PHPStan level 8, baseline in phpstan-baseline.neon
vendor/bin/pest                   # tests
npm run check                     # format + lint + types
```

`vp` owns TypeScript and CSS formatting. If your editor also formats on save, point it at
`vp check --fix` or turn it off for this project — the two disagree about how ternary continuations
are indented, and a file open in the editor will keep reappearing in `vp check` no matter how often
it is fixed. `resources/js/pages/onboarding/kyc-history.tsx` did this five times in one sitting. It is
whitespace and blocks nothing; just do not spend commits on it.

Run PHPStan through `composer types:check`, not `vendor/bin/phpstan` directly. A stale result cache
in `build/phpstan` puts the run on a path where bootstrap files never execute, and Larastan then
dies with `Undefined constant "Larastan\Larastan\LARAVEL_VERSION"` — a message that points nowhere
near the cause and persists until the cache is cleared. `--debug` hides it, because that path is
single-process. [scripts/phpstan.sh](scripts/phpstan.sh) clears the cache and retries **only** on
that message; every other failure passes straight through.

All four must pass before work is considered done. The PHPStan baseline holds starter-kit
debt only — it should shrink over time and must never grow.
