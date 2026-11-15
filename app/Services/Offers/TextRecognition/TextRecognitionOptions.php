<?php

namespace App\Services\Offers\TextRecognition;

class TextRecognitionOptions
{
    public function __construct(
        public readonly int $psm = 6,
        public readonly string $languages = 'spa+eng',
        public readonly string $variant = 'panel',
    ) {}
}
