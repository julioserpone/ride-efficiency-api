<?php

namespace App\Services\Offers\TextRecognition;

use InvalidArgumentException;

class TextRecognitionProviderManager
{
    public function __construct(
        private readonly TesseractTextRecognitionProvider $tesseract,
    ) {}

    public function driver(?string $provider = null): TextRecognitionProvider
    {
        $provider ??= (string) config('ride_offer_ocr.provider', 'tesseract');

        return match ($provider) {
            'tesseract' => $this->tesseract,
            default => throw new InvalidArgumentException(
                "No ride offer text recognition provider is registered for [{$provider}].",
            ),
        };
    }
}
