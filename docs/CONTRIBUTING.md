# Contributing & Git conventions

This guide defines how changes reach this repository: the branch model, the
commit message format, and how pull requests are written. Follow it for **every**
change, whether it comes from a human or an AI assistant.

Related guides: [`CODING_STANDARDS.md`](CODING_STANDARDS.md) (how the code must be
written) and [`TESTING.md`](TESTING.md) (how it must be proven to work).

## Branch model

`main` is always releasable. Never commit directly to `main`; every change
arrives through a pull request from a short-lived branch.

Branch naming uses the same type vocabulary as commits:

```
<type>/<short-kebab-summary>
```

Examples:

```
feat/gig-economics-config
fix/weighted-fuel-cost-rounding
docs/commit-conventions
refactor/extract-ocr-service
test/shift-close-boundaries
chore/bump-phpstan
```

- Keep the summary short, lowercase and kebab-cased.
- One logical change per branch. Rebase on `main` before opening the PR.
- Dependabot branches (`dependabot/composer/...`) are the only exception: they
  are created and named by the bot.

## Commit messages — Conventional Commits

Commits follow the [Conventional Commits](https://www.conventionalcommits.org/)
specification. The subject line is:

```
<type>(<optional scope>): <imperative summary>
```

- **type** — one of the values in the table below.
- **scope** *(optional)* — the affected area: `shifts`, `energy-invoices`,
  `ocr`, `stats`, `auth`, `models`, `config`, `docs`, `ci`, `deps`. Use the
  module or domain concept, not a file name.
- **summary** — imperative mood ("add", not "added"/"adds"), lowercase, no
  trailing period, 72 characters or fewer.

### Types

| Type       | Use for                                                     |
| ---------- | ----------------------------------------------------------- |
| `feat`     | A new user-facing capability                                |
| `fix`      | A bug fix                                                   |
| `refactor` | A change that neither fixes a bug nor adds a feature        |
| `perf`     | A change that improves performance                          |
| `test`     | Adding or correcting tests only                             |
| `docs`     | Documentation only                                          |
| `build`    | Build tooling or dependencies (Composer, PHP tooling)       |
| `ci`       | Continuous-integration configuration                        |
| `chore`    | Maintenance that fits no other type                         |
| `style`    | Formatting/whitespace without a behavioural change          |

### Body and footers

- Leave one blank line after the subject, then explain **what** and **why** (not
  how). Wrap at 72 characters.
- Use `BREAKING CHANGE:` in the footer to flag an incompatible change — most
  often a change to a JSON response shape — and describe the migration.
- Reference issues in the footer with `Refs: #123` / `Closes: #123`.

### Examples

```
feat(shifts): compute net profit with the gig-economics library

Replace the inline float arithmetic in the shift close action with the
trip profitability calculator, which keeps money in arbitrary-precision
decimals and returns a verdict per shift.

Refs: #57
```

```
feat(config): add the gig-economics configuration file

Publish config/gig-economics.php so the tax rate, default energy type and
profit target are editable per environment instead of hard-coded.
```

```
fix(energy-invoices): keep the OCR language list when Tesseract is missing

The service now falls back to the configured languages instead of failing
with "Failed loading language".
```

```
docs: document commit, coding and testing conventions
```

## Pull requests

Keep the PR focused on one logical change.

- **Title** — same format as a commit subject, e.g.
  `feat(shifts): compute net profit with the gig-economics library`.
- **Description** — start with a one-paragraph summary of the change and the
  problem it solves, then:
  - **What changed** — a bulleted list of the notable changes.
  - **Why** — the motivation or the linked issue.
  - **How to test** — the commands a reviewer runs (`composer test`).
  - **API impact** — `none`, or the affected endpoints and their new shape. The
    Vue dashboard (`ride-efficiency-frontend`) and the React Native app
    (`ride-efficiency-mobile`) consume these responses; a breaking change must
    be called out at the top and versioned.
  - **Checklist** — tests added/updated, `composer test` green.
- **Size** — one logical change. Split unrelated work into separate PRs.
- **Migrations** — call out any schema change and whether it is reversible.

A pull request is ready to merge only when `composer test` passes (Pint, PHPStan
and Pest, in that order) and the description explains how the change was
verified.

## Definition of done

- [ ] The branch is up to date with `main` and named `<type>/<summary>`.
- [ ] Commits follow Conventional Commits, each one self-contained.
- [ ] `composer test` passes locally (lint + types + tests).
- [ ] Tests cover the new or changed behaviour (see [`TESTING.md`](TESTING.md)).
- [ ] Public API changes are reflected in `README.md`.
- [ ] The PR description explains what, why, how to test and the API impact.
