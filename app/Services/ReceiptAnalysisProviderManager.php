<?php

namespace App\Services;

use InvalidArgumentException;

class ReceiptAnalysisProviderManager
{
    public function __construct(private readonly AzureDocumentIntelligenceClient $azure) {}

    public function driver(?string $provider = null): ReceiptAnalysisProvider
    {
        $provider ??= (string) config('ocr.provider', 'azure');

        if (! in_array($provider, config('ocr.providers', ['azure']), true)) {
            throw new InvalidArgumentException("Receipt analysis provider [{$provider}] is not enabled.");
        }

        return match ($provider) {
            'azure' => $this->azure,
            default => throw new InvalidArgumentException("No receipt analysis service is registered for provider [{$provider}]."),
        };
    }
}
