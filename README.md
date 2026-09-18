# Ride Efficiency — API

Laravel 13 API-only service for the Ride Efficiency platform. It owns the database and
exposes JSON endpoints for gig-economy shift profitability: GPS kilometres, connected
time, per-platform earnings, fuel invoices with OCR extraction, depreciation and net
profit per shift.

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

GET     /api/v1/fuel-invoices           Paginated fuel invoices
POST    /api/v1/fuel-invoices           Upload a receipt image and queue OCR
GET     /api/v1/fuel-invoices/{id}      Show a fuel invoice
DELETE  /api/v1/fuel-invoices/{id}      Delete a fuel invoice

GET     /api/v1/stats/weekly            Aggregated metrics, last 8 weeks
GET     /api/v1/stats/monthly           Aggregated metrics, last 6 months
GET     /api/v1/stats/summary           Global totals, averages, best/worst shift
GET     /api/v1/stats/efficiency        Profit per km/hour, fuel cost per km
```

## Domain model

- `daily_shifts` — automatic metrics (GPS km, connected time, scanned offers) and
  calculated metrics (applied fuel cost, depreciation, real net profit).
- `daily_earnings` — per-platform earnings (Uber, DiDi, …) belonging to a shift.
- `fuel_invoices` — uploaded receipt images and their asynchronous OCR state.
- `countries` — currency and depreciation rate used by profitability calculations.

## Conventions

- All code, migrations, variable names, endpoints and comments are written in **English**.
- Use `php artisan make:*` to scaffold files and `--no-interaction` in scripts.
- Every change must be covered by a test. Run `php artisan test --compact` (optionally
  with `--filter=` to narrow the scope).
- Run `vendor/bin/pint --dirty --format agent` after touching PHP files.

## Deployment

Laravel Cloud is the recommended deployment target.
