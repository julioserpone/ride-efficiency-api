# Profitability Contract v1

> **Status:** Draft for approval. Source of truth for the `gig-economics` (PHP) and `gig-economics` (npm) packages.
> **calculation_version:** `1`
> **Fixtures:** `shared/profitability-fixtures/v1/cases.json`

## 1. Purpose

Define the single profitability calculation so the API (PHP, `julioserpone/gig-economics`) and mobile (TypeScript, npm `gig-economics`) produce the **same verdict** for the same inputs. All monetary values are decimal strings (never floats) to avoid precision loss.

## 2. Inputs

| Field | Type | Notes |
| :--- | :--- | :--- |
| `platform` | enum | `uber`, `didi`, `indrive`, `lyft`, `custom` |
| `gross_fare` | decimal string | Gross fare offered/paid |
| `currency` | ISO 4217 | Currency code |
| `distance_km` | decimal string | Billable distance |
| `deadhead_distance_km` | decimal string | Unpaid pickup distance (default `0`) |
| `duration_minutes` | int or null | Optional, informational |
| `energy.mode` | enum | `combustion` or `electric` |
| `energy.price_per_unit` | decimal string | Per gallon (combustion) or per kWh (electric) |
| `energy.economy` | decimal string | km per unit |
| `commission_rate` | decimal string (percent) | e.g. `25` = 25% |
| `depreciation_per_km` | decimal string | Money per km |
| `other_costs_per_trip` | decimal string | Fixed costs per trip (default `0`) |
| `target_profit_per_km` | decimal string | Target profit per km (default `0.15`) |

## 3. Formula

```
total_distance = distance_km + deadhead_distance_km
commission     = gross_fare * (commission_rate / 100)
energy_cost    = (total_distance / economy) * price_per_unit
depreciation   = total_distance * depreciation_per_km
other_costs    = other_costs_per_trip
net_profit     = gross_fare - commission - energy_cost - depreciation - other_costs
profit_per_km  = net_profit / total_distance   (6 decimals, HALF_UP; if total_distance == 0 use 1)
verdict        = fromProfitPerKilometre(profit_per_km, target_profit_per_km)
break_even_fare = (energy_cost + depreciation + other_costs) / (1 - commission_rate/100)
                  (12 decimals; if commission_rate >= 100%, break_even_fare = 0)
```

Rounding: money fields to the currency scale used by the library (inputs/outputs as decimal strings; intermediate math at full precision, final money to 2-6 decimals as the library emits). Parity tests compare decimal values, not float.

## 4. Verdict thresholds

`marginalBand = 0.10` (relative).

- If `target_profit_per_km <= 0`: `profit_per_km > 0` → `accept`, else `reject`.
- `lower = target * (1 - 0.10)`, `upper = target * (1 + 0.10)`.
- `profit_per_km >= upper` → `accept`
- `profit_per_km >= lower` → `marginal`
- else → `reject`

## 5. Units & currencies

- Distances in kilometres. Energy economy in km per unit (gallon/litre/kWh).
- Money is always a decimal string in the given currency. No currency conversion in v1.
- `commission_rate` is in percentage points.

## 6. calculation_version

Every profile/sync response that depends on this contract must include `calculation_version: "1"`. When the formula changes, bump the version and add a new fixture directory (`v2/`). Mobile caches the profile by `calculation_version` and invalidates on change.

## 7. Offer-level adjustments (mobile/API)

Offers may carry `surge_amount`, `tip_amount`, `toll_amount`. These are applied on top of the core calculation: `effective_gross_fare = offered_fare + surge + tip`; tolls are added to `other_costs`. The core library call uses the effective values. This layer must keep the same verdict semantics.

## 8. Parity requirement

- PHP (`julioserpone/gig-economics`) and TS (npm `gig-economics`) must produce identical `net_profit`, `break_even_fare` and `verdict` for every case in `shared/profitability-fixtures/v1/cases.json`.
- CI in both repos runs the fixture suite; drift fails the build.