<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tesseract Languages
    |--------------------------------------------------------------------------
    |
    | Languages (tessdata codes) requested from Tesseract, in priority order.
    | Only the ones actually installed on the host are used, so the OCR engine
    | never fails with a "Failed loading language" error.
    |
    */

    'languages' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OCR_LANGUAGES', 'spa,eng')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Page Segmentation Mode
    |--------------------------------------------------------------------------
    |
    | Tesseract PSM value used for the invoices. Mode 6 assumes a single
    | uniform block of text, which matches the layout of a printed receipt.
    | Values from 3 onwards require Tesseract 4 or later.
    |
    */

    'psm' => (int) env('OCR_PSM', 6),

];
