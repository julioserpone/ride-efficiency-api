# Coding standards

How code in this repository must be written. Read it together with
[`CONTRIBUTING.md`](CONTRIBUTING.md) (how a change is delivered) and
[`TESTING.md`](TESTING.md) (how it is proven). Most of these rules are enforced
by `composer lint` (Laravel Pint) and `composer types:check` (Larastan).

## Language

- **English only.** Code, identifiers, migrations, columns, routes, comments,
  docblocks, tests, fixtures and API payload keys are written in English.
  Spanish is only allowed in user-facing copy handled by the frontend and mobile
  repositories — never in this codebase.
- Responses and error messages are English too; the consumers localize them.

## Syntax and files

- Every PHP file starts with `<?php`, and declares the namespace on the next
  block. `declare(strict_types=1)` is **not** used in this project — do not add
  it to new files; follow the surrounding files instead.
- One class per file, named after the file. Namespaces follow PSR-4:
  `App\` → `app/`, `Database\Factories\` → `database/factories/`,
  `Database\Seeders\` → `database/seeders/`, `Tests\` → `tests/`.
- Use PHP 8.4 syntax: constructor property promotion, enums, `match`, named
  arguments, first-class callable syntax and `readonly` where it fits.
- Always use curly braces for control structures, even for a single statement.
- Scaffold files with `php artisan make:*` and pass `--no-interaction`.

## Naming

- Classes are `PascalCase`; methods and variables are `camelCase`; constants are
  `SCREAMING_SNAKE_CASE`; enum cases are `TitleCase` (`Platform::Uber`).
- Name things for what they mean in the domain, not for where the data came
  from. Dominant nouns here are **shift**, **earning**, **fuel invoice**,
  **country**, **efficiency** and **profitability**.
- Methods describe the action and read as a sentence:
  `calculateNetProfit()`, `resolveDepreciationRatePerKm()`, not `calc()`.
- Predicates read as a question and return `bool`: `isProfitable()`,
  `hasOdometer()`.
- Units are part of the name: `depreciation_rate_per_km`, `total_km_gps`,
  `profitPerKilometre()`, `costPerHour()`.

## Typing

- Type every method parameter and every return value; use union types and
  `?Type` instead of docblock-only types.
- Use constructor property promotion:
  `public function __construct(public OcrProcessingService $ocr) {}`. Do not
  leave an empty, zero-parameter `__construct()` behind.
- Prefer PHPDoc blocks over inline comments, and only for what the signature
  cannot express: array shapes (`array<string, mixed>`), generics, Eloquent
  `@property` annotations.
- Larastan runs at **level 7** over `app/`, `config/`, `database/`, `routes/`
  and `bootstrap/app.php`. Never lower the level or exclude a path to make a
  change pass; fix the type instead.

## Money, decimals and floats

- Money that reaches the database or a JSON response is kept in the columns and
  casts the schema already defines: `decimal:2` for amounts, `decimal:8` for
  rates. Do not widen these to `float`.
- Round only at the boundary where a value is stored or presented, using
  `round($value, 2)` — never round intermediates that feed further arithmetic.
- When a calculation moves to the `julioserpone/gig-economics` library, do the
  arithmetic there: `Money`/`Percentage` are arbitrary-precision and must never
  be reduced to a `float` in the middle of a computation.
- `(float)` casts are acceptable only when reading a database value for a
  display-oriented calculation that is rounded immediately afterwards.

## Laravel conventions

- **Do things the Laravel way.** Prefer framework features (validation,
  resources, policies, queued jobs, facades) over hand-rolled equivalents.
- HTTP controllers stay thin: validate in a `FormRequest`, delegate the work to
  an `App\Actions\*` class or a service, return an `JsonResource`.
- Business logic lives in `app/Actions` and `app/Services`; models hold
  relationships, casts and scopes only.
- Database work inside an action runs in `DB::transaction()`.
- Routes are versioned under `/api/v1/...` with names prefixed `api.v1.*`, and
  every route except token issuance is protected by `auth:sanctum`.
- Never return an Eloquent model directly from a controller: shape the payload
  with an API resource and keep response keys stable — two client applications
  depend on them.
- Long-running work (OCR, imports, reports) belongs in a queued job, not in the
  request cycle.
- Configuration lives in `config/*.php` and reads `env()` **only** there. Never
  call `env()` outside the `config/` directory — use `config('...')` with a
  default.

## Migrations and schema

- Migrations are additive and reversible: implement `down()` for every `up()`.
- Table and column names are `snake_case` and singular/plural per Laravel
  convention (`daily_shifts`, `energy_invoices`, `depreciation_rate_per_km`).
- Add a foreign key and the matching index together; declare `nullOnDelete` or
  `cascadeOnDelete` explicitly so the intent is readable.
- Never edit a migration that has already run in production; add a new one.

## Tests

- Every behavioural change ships with a test (see [`TESTING.md`](TESTING.md)).
- Never delete or skip a test without approval; fix it or explain why it is
  obsolete in the PR description.

## Errors and logging

- Validation failures are handled by `FormRequest` rules, not by manual checks
  inside the controller.
- Throw specific exceptions with a message that states the concept and the
  offending value. Never swallow an exception to keep a flow running.
- Log operational failures (OCR, external calls) through the `Log` facade with
  context; never log secrets, tokens or full request payloads.

## Formatting

- Run `vendor/bin/pint --dirty --format agent` after touching PHP files; CI runs
  `composer lint:check`.
- Pint uses the `laravel` preset (`pint.json`). Do not add a custom ruleset to
  work around a formatting preference.
- Imports are ordered and unused ones removed.

## Documentation

- Only document what is asked for: this repository keeps three committed guides
  (`CONTRIBUTING.md`, `CODING_STANDARDS.md`, `TESTING.md`) plus `README.md`.
- Update `README.md` when the public API surface changes.
