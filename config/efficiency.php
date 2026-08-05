<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Shift Depreciation Rate Per Kilometer
    |--------------------------------------------------------------------------
    |
    | Default rate per km used to calculate vehicle depreciation if no
    | country-specific or custom depreciation rate is configured.
    |
    */

    'depreciation_rate_per_km' => (float) env('SHIFT_DEPRECIATION_RATE_PER_KM', 0.15),

];
