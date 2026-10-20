# Ride Efficiency — API

Laravel 13 API-only service for the Ride Efficiency platform. It owns the database and
exposes JSON endpoints for gig-economy profitability: vehicles, individual rides,
per-platform earnings, fuel and electric-energy invoices with OCR extraction,
depreciation and net profit.

## Architecture

This repository contains **no web interface**. The only browser-facing route is the
informational landing page at `/`. All other routes are JSON API endpoints authenticated
with Laravel Sanctum **personal access tokens** (bearer tokens).

| Repository                        | Stack                   | Role                                       |
| --------------------------------- | ----------------------- | ------------------------------------------ |
| `ride-efficiency-api` (this repo) | Laravel 13, PostgreSQL  | Database, models, business logic, JSON API |
| `ride-efficiency-frontend`        | Vue 3 + Vite + PrimeVue | Web dashboard SPA                          |
| `ride-efficiency-mobile`          | React Native            | Mobile app                                 |

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

Requirements: PHP 8.4.1 or newer, Composer 2, and Docker for the default Sail
PostgreSQL development stack. Keep local environment files and credentials out of
commits; use synthetic data for development and tests. See [`SECURITY.md`](SECURITY.md)
before reporting a potential vulnerability.

Receipt images are processed asynchronously through Azure AI Document Intelligence's
`prebuilt-receipt` model. Configure `AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT` and
`AZURE_DOCUMENT_INTELLIGENCE_KEY` in `.env` (copy the names from `.env.example`); never
commit credentials. `OCR_PROVIDER` selects the active implementation and defaults to
`azure`; `OCR_PROVIDERS` lists the implementations enabled in the application. The
Azure model, API version, polling interval and request timeout can be configured with
the corresponding `AZURE_DOCUMENT_INTELLIGENCE_*` variables in `.env.example`.
Uploads are queued, so run a queue worker in development or deployment. The worker
submits the receipt, polls Azure for the completed analysis, maps extracted fields to
the invoice, and sends incomplete results to manual review. Provider request metadata
and the full response are encrypted on the invoice record. Tests use synthetic Azure
responses and do not call Azure.

To add another provider, implement `ReceiptAnalysisProvider` and register it in
`ReceiptAnalysisProviderManager`; the controller and queue orchestration can remain
unchanged. The `types:check` Composer script sets PHPStan's analysis limit to 512 MB,
so `composer test` uses it automatically.

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

GET     /api/v1/countries              Active countries and currency metadata
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
  country/product, currency and asynchronous OCR/review state. Supplier references
  `vendors`; extracted invoice lines are stored separately in `energy_invoice_items`.
  OCR results needing correction use `review_required` until the driver confirms the
  invoice.
- `vendors` — a shared supplier catalog, matched by country and normalized tax
  identifier, with separate legal and commercial names and a supplier address.
- `energy_invoice_items` — one row per receipt line, including its description, product,
  quantity/unit, unit prices, subsidy and line totals. An invoice can have multiple
  items without collapsing different products into one energy quantity.

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
when the purchase took place outside the driver's configured country. Multipart uploads
continue to accept the `image` file field; web and mobile clients may also submit
`image_base64` containing raw base64 or a data URI. The asynchronous receipt analysis
uses the configured provider's receipt model. `OCR_PROVIDER` chooses the active driver
(currently `azure`); `OCR_PROVIDERS` lists drivers enabled for the application. Provider
request metadata and the complete raw provider response are encrypted at rest on the
invoice record. Uncertain or incomplete scans are retained for manual review rather
than marked complete. Invoice
records retain supplier and service-station details, customer identity (separate from the
authenticated user), vehicle plate, payment method, invoice identifiers, and invoice-level
tax totals. Invoice JSON exposes the normalized supplier as `vendor` (including
`legal_name`, `commercial_name` and `address`) and its separate line items as `items`.
Each item can include `product_description`, `item_code`, `quantity`, `quantity_unit`,
`unit_price`, `unit_price_without_subsidy`, `subsidy_amount`, `subtotal_amount`,
`tax_rate`, `tax_amount`, `total_amount` and `total_amount_without_subsidy`. Invoice-level
fields include `vendor_name`, `vendor_tax_id`, `vendor_address`, `station_name`,
`station_brand`, `customer_name`, `customer_tax_id`, `customer_email`, `vehicle_plate`,
`payment_method`, `invoice_number`, `authorization_number`, `subtotal_amount`,
`tax_amount`, `tax_rate` and `total_amount_without_subsidy`. Station brand and other
fields remain nullable when they are not printed or cannot be read; the OCR does not
infer them. The `PATCH /api/v1/energy-invoices/{id}/review` endpoint accepts optional
corrected values, including a list of `items`. `customer_name` and `customer_tax_id`
describe the billed party and are not assumed to belong to the authenticated driver.
Item-specific fields previously returned at invoice level now belong to `items`; web and
mobile consumers must read them there.

## Conventions

- All code, migrations, variable names, endpoints and comments are written in **English**.
- Use `php artisan make:*` to scaffold files and `--no-interaction` in scripts.
- Every change must be covered by a test. Run `php artisan test --compact` (optionally
  with `--filter=` to narrow the scope).
- Run `vendor/bin/pint --dirty --format agent` after touching PHP files.

## Guides for contributors and AI assistants

Read these before changing code; they are the source of truth for how work is done
in this repository:

| Guide                                                  | Covers                                                                            |
| ------------------------------------------------------ | --------------------------------------------------------------------------------- |
| [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md)         | Branch model, Conventional Commits, pull requests, definition of done             |
| [`docs/CODING_STANDARDS.md`](docs/CODING_STANDARDS.md) | Language, syntax, naming, typing, money handling, Laravel conventions, migrations |
| [`docs/TESTING.md`](docs/TESTING.md)                   | Pest layout, conventions, required coverage, how to run the suite                 |
| [`AGENTS.md`](AGENTS.md)                               | Repository-wide instructions for coding agents                                    |
| [`.ai/rules/index.md`](.ai/rules/index.md)             | Path-scoped AI rules and rule files to read                                       |
| [`SECURITY.md`](SECURITY.md)                           | Private vulnerability reporting and supported releases                            |

For changes, run `composer test` (Pint, PHPStan and Pest). Pull requests use the
checklist in [`.github/PULL_REQUEST_TEMPLATE.md`](.github/PULL_REQUEST_TEMPLATE.md);
bug and feature issues use the structured forms in `.github/ISSUE_TEMPLATE/`.

## Deployment

Laravel Cloud is the recommended deployment target.
