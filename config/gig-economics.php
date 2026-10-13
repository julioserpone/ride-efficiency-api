<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gig Economics Configuration
    |--------------------------------------------------------------------------
    |
    | Defaults consumed by the julioserpone/gig-economics library. The library
    | itself is framework-agnostic and never reads the environment: this file
    | is the Laravel-side source of truth and is handed to the library with
    | `GigEconomics\Support\Configuration::fromArray(config('gig-economics'))`.
    |
    | Keys must match the array keys the library expects.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Default Tax Rate Percent
    |--------------------------------------------------------------------------
    |
    | Tax applied to each purchase subtotal when the imported log does not
    | carry its own tax rate.
    |
    */

    'tax_rate_percent' => (float) env('GIG_ECONOMICS_TAX_RATE', 15.0),

    /*
    |--------------------------------------------------------------------------
    | Default Energy Type
    |--------------------------------------------------------------------------
    |
    | Energy source assumed when the input omits it. One of: regular_gasoline,
    | premium_gasoline, diesel, ethanol, lpg, cng, electric.
    |
    */

    'default_energy_type' => env('GIG_ECONOMICS_DEFAULT_ENERGY_TYPE', 'regular_gasoline'),

    /*
    |--------------------------------------------------------------------------
    | Target Profit Per Kilometre
    |--------------------------------------------------------------------------
    |
    | Default profit target used to build the Accept / Marginal / Reject
    | verdict. Kept as a string so it travels as an arbitrary-precision
    | decimal and never loses precision.
    |
    */

    'target_profit_per_kilometre' => env('GIG_ECONOMICS_TARGET_PROFIT_PER_KILOMETRE', '0.15'),

];
