<?php

return [
    'provider' => env('RIDE_OFFER_OCR_PROVIDER', 'tesseract'), // tesseract | azure-read
    'tesseract' => [
        'binary' => env('TESSERACT_BINARY', 'tesseract'),
        'languages' => env('TESSERACT_LANGUAGES', 'spa+eng'),
        'psm_primary' => (int) env('TESSERACT_PSM_PRIMARY', 6),
        'psm_labels' => (int) env('TESSERACT_PSM_LABELS', 11),
        'timeout_seconds' => (int) env('TESSERACT_TIMEOUT_SECONDS', 20),
    ],
    'preprocess' => [
        'driver' => env('RIDE_OFFER_IMAGE_DRIVER'), // imagick | gd | null (auto)
        'scale' => (float) env('RIDE_OFFER_IMAGE_SCALE', 2.5),
        'crop_strategy' => env('RIDE_OFFER_IMAGE_CROP_STRATEGY', 'auto'), // auto | bottom_half | card
        'max_side' => (int) env('RIDE_OFFER_IMAGE_MAX_SIDE', 2400),
    ],
    'min_confidence' => (float) env('RIDE_OFFER_MIN_CONFIDENCE', 0.70),
    'image_retention_days' => (int) env('RIDE_OFFER_IMAGE_RETENTION_DAYS', 30),
    'reconciliation_tolerance' => [
        'fare_absolute' => env('RIDE_OFFER_RECONCILIATION_FARE_TOLERANCE', '0.01'),
        'distance_km_absolute' => env('RIDE_OFFER_RECONCILIATION_DISTANCE_TOLERANCE', '0.05'),
    ],
    'max_image_bytes' => (int) env('RIDE_OFFER_MAX_IMAGE_BYTES', 8 * 1024 * 1024),
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
];
