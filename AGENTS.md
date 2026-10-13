# Agent instructions

These instructions apply to the whole repository. Before changing code, read
[`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md),
[`docs/CODING_STANDARDS.md`](docs/CODING_STANDARDS.md) and
[`docs/TESTING.md`](docs/TESTING.md). Use
[`.ai/rules/index.md`](.ai/rules/index.md) to find and read every area-specific
rule that applies to the files in scope.

## Project boundaries

- This repository is the Laravel 13 JSON API, not the web or mobile UI. Keep
  application UI in its consumer repositories.
- Preserve the `/api/v1` boundary, Sanctum authentication, authorization
  ownership checks and established response shapes. Coordinate contract
  changes with the web and mobile clients and document them in `README.md`.
- Treat migrations as deployed history: add reversible migrations instead of
  editing existing ones. Do not perform destructive data or schema changes
  without explicit approval and a safe rollout plan.
- Never commit secrets, tokens, real receipts, personal information, vehicle
  identifiers or production data. Use synthetic test fixtures and fake external
  services.

## Implementation

- Follow the installed PHP, Laravel and package versions; the application
  requires PHP 8.4.1 or newer and CI currently tests PHP 8.4 and 8.5.
- Follow existing Laravel conventions and the focused classes in
  `app/Actions`, `app/Services`, `app/Jobs`, and `app/Http`. Avoid introducing
  generic abstractions without a demonstrated need.
- Validate requests with Form Requests, use validated input, return explicit
  API responses, and scope user-owned records to the authenticated user.
- Keep monetary values in the existing decimal-safe domain types and preserve
  explicit energy units across persistence and API boundaries.
- Update the API documentation and response-contract tests when a public
  endpoint or response changes.

## Verification

- Add or update deterministic Pest tests for changed behavior, validation,
  authorization and API contracts. Fake queues, storage, HTTP and OCR as
  appropriate.
- Run the narrowest relevant tests first. Before proposing PHP changes, run
  `composer test` (Pint, PHPStan and Pest); follow
  [`docs/TESTING.md`](docs/TESTING.md) for focused commands and coverage.
- Keep pull requests focused, explain API and migration impact, and use the
  repository's Conventional Commit and review process in
  [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md).

## Maintaining agent guidance

Keep durable, repository-wide instructions here and path-specific rules in
`.ai/rules/`. Update the rule index when adding or moving a scoped rule. The
maintained project overview is `README.md`; do not duplicate outdated
architecture or design-system instructions here.
