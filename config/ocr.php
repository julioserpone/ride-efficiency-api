<?php

return [
    'provider' => env('OCR_PROVIDER', 'azure'),
    'providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('OCR_PROVIDERS', 'azure'))))),
    'azure' => [
        'endpoint' => env('AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT'),
        'key' => env('AZURE_DOCUMENT_INTELLIGENCE_KEY'),
        'model' => env('AZURE_DOCUMENT_INTELLIGENCE_MODEL', 'prebuilt-receipt'),
        'api_version' => env('AZURE_DOCUMENT_INTELLIGENCE_API_VERSION', '2024-11-30'),
        'poll_interval_ms' => (int) env('AZURE_DOCUMENT_INTELLIGENCE_POLL_INTERVAL_MS', 500),
        'timeout_seconds' => (int) env('AZURE_DOCUMENT_INTELLIGENCE_TIMEOUT_SECONDS', 60),
    ],
];
