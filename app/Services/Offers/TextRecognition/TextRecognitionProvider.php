<?php

namespace App\Services\Offers\TextRecognition;

interface TextRecognitionProvider
{
    public function name(): string;

    public function recognize(string $absolutePath, TextRecognitionOptions $options): OcrResult;
}
