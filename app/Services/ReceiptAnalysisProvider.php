<?php

namespace App\Services;

interface ReceiptAnalysisProvider
{
    /** @return array<string, mixed> */
    public function analyze(string $imageBytes, string $contentType): array;

    public function name(): string;
}
