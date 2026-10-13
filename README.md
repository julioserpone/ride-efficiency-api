# Ride Efficiency — API

Laravel 13 API-only service for the Ride Efficiency platform. It owns the database and
exposes JSON endpoints for gig-economy profitability: vehicles, individual rides,
per-platform earnings, fuel and electric-energy invoices with OCR extraction,
depreciation and net profit.

## Architecture

This repository contains **no web interface**. The only browser-facing route is the
informational landing page at `/`. All other routes are JSON API endpoints authenticated
with Laravel Sanctum **personal access tokens** (bearer tokens).

| Repository | Stack | Role |
| --- | --- | --- |
| `ride-efficiency-api` (this repo) | Laravel 13, PostgreSQL | Database, models, business logic, JSON API |
| `ride-efficiency-frontend` | Vue 3 + Vite + PrimeVue | Web dashboard SPA |
| `ride-efficiency-mobile` | React Native | Mobile app |

Both consumers authenticate by requesting a token from `POST /api/v1/auth/token` and
sending it as an `Authorization: Bearer <token>` header. CORS is configured through the
`CORS_ALLOWED_ORIGINS` environment variable.

## Stack

- PHP 8.4+ / Laravel 13
- PostgreSQL (Sail / Docker via `compose.yaml`)
- Laravel Sanctum 4.x — API token authentication
- Laravel Pint, Larastan (PHPStan), Pest

## Getting started

```bash
composer setup   # install deps, create .env, generate key, migrate
composer dev     # serve + queue listener + pail logs
```

## API surface

```
POST    /api/v1/auth/token              Issue a personal access token
GET     /api/v1/auth/user               Show the token owner
DELETE  /api/v1/auth/token              Revoke the current token

GET     /api/v1/shifts                  Paginated shifts with earnings
POST    /api/v1/shifts                  Start a daily shift
GET     /api/v1/shifts/{shift}          Show a shift
POST    /api/v1/shifts/{shift}/close    Close a shift and compute net profit

GET     /api/v1/energy-invoices        Paginated energy invoices
POST    /api/v1/energy-invoices        Upload an energy receipt image and queue OCR
GET     /api/v1/energy-invoices/{id}   Show an energy invoice
DELETE  /api/v1/energy-invoices/{id}   Delete an energy invoice
PATCH   /api/v1/energy-invoices/{id}/review  Confirm fields when OCR needs review

GET     /api/v1/countries/{code}/energy-products  Country-specific fuel and energy catalog
GET     /api/v1/vehicles                List active vehicle profiles
POST    /api/v1/vehicles                Create a vehicle energy/economy profile
GET     /api/v1/ride-trips              Paginated individual ride profitability
GET     /api/v1/ride-trips/{id}         Show a calculated ride
POST    /api/v1/vehicles/{id}/trips     Calculate and save one ride's profitability

GET     /api/v1/stats/weekly            Aggregated metrics, last 8 weeks
GET     /api/v1/stats/monthly           Aggregated metrics, last 6 months
GET     /api/v1/stats/summary           Global totals, averages, best/worst shift
GET     /api/v1/stats/efficiency        Profit per km/hour, fuel cost per km
```

## Domain model

- `daily_shifts` — automatic metrics (GPS km, connected time, scanned offers) and
  calculated metrics (applied fuel cost, depreciation, real net profit).
- `daily_earnings` — per-platform earnings (Uber, DiDi, …) belonging to a shift.
- `energy_invoices` — uploaded fuel/electricity receipts, explicit energy amount/unit,
  country/product, currency and asynchronous OCR/review state. OCR results needing
  correction use `review_required` until the driver confirms the invoice.

- `energy_products` — country-specific names and OCR aliases mapped to the general
  energy types understood by `gig-economics`. The seeded catalog covers the US,
  Mexico, Colombia, Ecuador, Peru and Chile and can be extended with additional rows.
- `vehicles` — a user's country, energy type/unit and measured distance per energy unit.
- `ride_trips` — each trip's platform, distance, fare, itemized costs, net profit,
  break-even fare and accept/marginal/reject verdict.
- `countries` — currency and depreciation rate used by profitability calculations.

Invoice endpoints and their backing table use the neutral `energy-invoices` /
`energy_invoices` names. The former `/api/v1/fuel-invoices` endpoints are removed;
mobile and web clients must switch to `/api/v1/energy-invoices`.

The ride endpoint accepts `platform_name`, `gross_fare`, `distance_km`, and optional
`deadhead_distance_km`, `duration_minutes`, `commission_rate`, `other_costs_per_trip`,
`daily_shift_id` and `energy_price_per_unit`. If no unit-price override is supplied,
the API uses the latest completed invoice matching the vehicle's country, energy type,
unit and currency. Unknown marketplace names are treated as custom platforms; pass a
commission rate when one applies.

Energy units remain explicit end-to-end: combustion receipts can use gallons or litres,
and electric receipts use kilowatt-hours. Invoice uploads accept an optional `country_id`
when the purchase took place outside the driver's configured country. The OCR recognizes
English and Spanish labels and common decimal/thousands separators; uncertain or
incomplete scans are retained for manual review rather than marked complete.

## Conventions
- All code, migrations, variable names, endpoints and comments are written in **English**.
- Use `php artisan make:*` to scaffold files and `--no-interaction` in scripts.
- Every change must be covered by a test. Run `php artisan test --compact` (optionally
  with `--filter=` to narrow the scope).
- Run `vendor/bin/pint --dirty --format agent` after touching PHP files.

## Guides for contributors and AI assistants
Read these before changing code; they are the source of truth for how work is done
in this repository:

| Guide | Covers |
| --- | --- |
| [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md) | Branch model, Conventional Commits, pull requests, definition of done |
| [`docs/CODING_STANDARDS.md`](docs/CODING_STANDARDS.md) | Language, syntax, naming, typing, money handling, Laravel conventions, migrations |
| [`docs/TESTING.md`](docs/TESTING.md) | Pest layout, conventions, required coverage, how to run the suite |

## Deployment

Laravel Cloud is the recommended deployment target.
