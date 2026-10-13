# Testing guide

This project uses [Pest](https://pestphp.com/) 4 on top of PHPUnit. Tests are part
of the definition of "done": a behavioural change without a test is an incomplete
change. `composer test` runs the suite (after Pint and PHPStan) and must be green
before a change is merged.

## Layout

```
tests/
├── Pest.php              # binds the base TestCase + RefreshDatabase to Feature
├── TestCase.php          # base class for the application test case
├── Feature/              # HTTP, database, jobs and full request/response flows
└── Unit/                 # fast, in-memory, no I/O
```

- **`Feature/`** — everything that touches the application: a request through a
  route, a model against the database, a queued job, an Artisan command. This is
  where most tests belong.
- **`Unit/`** — pure logic with no container, database or filesystem.
- `tests/Pest.php` applies `RefreshDatabase` to every `Feature` test, so each
  test starts from a clean schema and the factories create whatever it needs.

## Creating tests

```bash
php artisan make:test --pest ShiftCloseTest          # tests/Feature/ShiftCloseTest.php
php artisan make:test --pest --unit MoneyTest        # tests/Unit/MoneyTest.php
```

The `{name}` argument must not include the suite directory.

## Conventions

- **Pest functional style.** Use `it('...', function (): void { ... })` with a
  behaviour-focused description; never name a test after the method it calls.
- **One behaviour per test.** If the description needs an "and", split the test.
- **Arrange / Act / Assert.** Build the data, perform the single action under
  test, then assert.
- **`expect()` chains.** Prefer one `expect()` chain with `->and(...)` over
  several separate `expect()` calls.
- **Factories, not hand-built rows.** Use the model factories and their custom
  states; use `fake()` for generated values. Never insert rows with raw SQL.
- **`actingAs()` for auth.** Protected endpoints are exercised with a Sanctum
  token (`Sanctum::actingAs($user)`) or by sending the bearer header when the
  test is specifically about authentication.
- **Determinism.** No `sleep`, no randomness, no dependence on the wall clock.
  Freeze or pass time explicitly (`Date::setTestNow(...)`, `CarbonImmutable`)
  whenever a calculation depends on dates.
- **No network.** External calls (Tesseract, HTTP) are faked with
  `Http::fake()`, `Process::fake()` or a bound fake of the service.
- **Queues and events.** Assert that a job was dispatched with
  `Queue::fake()`/`Bus::fake()` and assert its side effects separately.

## What must be covered

Every endpoint or action you add or change needs at least:

1. **A happy path** with concrete, hand-computed expected values.
2. **A validation failure** — the `422` and the field-level error for each rule
   you added.
3. **An authorization failure** — the request is rejected without a token
   (`401`) and, where a record belongs to a user, when it belongs to someone
   else (`404`, never another user's payload).
4. **A boundary case** — zero kilometres, an empty collection, a missing
   optional relation, the first and last period of an aggregate.

Specifically, treat these as must-test:

- Profitability math: earnings minus applied fuel cost minus depreciation, at
  the stored `decimal:2` precision.
- Aggregates in `StatsController` (weekly, monthly, summary, efficiency) with a
  hand-computed expected result.
- The OCR pipeline's states (pending → processed → failed) with the OCR service
  faked.
- Any change to a JSON response shape, asserted field by field — the Vue
  dashboard and the React Native app break silently otherwise.

## Response assertions

- Assert status codes explicitly and assert the payload with
  `assertJsonPath()` / `assertJsonStructure()` against the documented shape.
- When a response shape changes, update every test that touches that endpoint in
  the same pull request.

## Working with the gig-economics library

Calculations delegated to `julioserpone/gig-economics` are tested through the
application boundary — the action or the endpoint — not by re-testing the
library's arithmetic. Assert on the values the API returns and on the numbers
persisted to the database:

```php
it('stores the net profit produced by the profitability calculator', function (): void {
    $shift = DailyShift::factory()->completed()->create();

    $response = $this->postJson("/api/v1/shifts/{$shift->id}/close");

    $response->assertOk();

    expect($shift->fresh()->real_net_profit)->toEqual($response->json('data.real_net_profit'));
});
```

The library returns `Money`, `Percentage`, `Distance` and `EnergyQuantity`
values backed by arbitrary-precision decimals. Compare them as strings or cast
them at the assertion site; never build an expected value with `float`
arithmetic and compare it for equality, because the intermediate rounding will
differ. A test of the profitability configuration belongs in
`tests/Feature/GigEconomicsConfigurationTest.php`.

## Fixtures

- Files used by tests (receipt samples, logs) live in `tests/Fixtures/` and are
  referenced through `base_path('tests/Fixtures/...')`.
- Fixtures use generic English column names and small but realistic data; never
  commit a real receipt, plate or personal identifier.
- A fixture may include a deliberately wrong calculated column to prove it is
  recomputed by the application.

Example:

```php
it('computes the net profit of a shift from earnings, fuel and depreciation', function (): void {
    $shift = DailyShift::factory()->create([
        'total_km_gps' => 120,
        'applied_fuel_cost' => 40.00,
    ]);

    DailyEarning::factory()->for($shift)->create(['gross_amount' => 200.00]);

    $result = app(CalculateShiftProfitabilityAction::class)->execute($shift);

    expect($result->estimated_depreciation)->toBe(18.00)
        ->and($result->real_net_profit)->toBe(142.00);
});
```

## Running

```bash
composer test                       # Pint check + PHPStan + full Pest suite
php artisan test --compact          # the Pest suite alone
php artisan test --compact --filter=shift
php artisan test --compact tests/Feature/StatsTest.php
vendor/bin/pint --dirty --format agent
```

Run the narrowest set that proves your change while you work, and the full
`composer test` before you finish.

## Definition of done

- [ ] New/changed behaviour is covered per the rules above.
- [ ] `composer test` passes locally (lint, PHPStan level 7, Pest).
- [ ] No test was deleted or skipped without approval.
- [ ] Response-shape changes are reflected in the tests of every affected
      endpoint.
