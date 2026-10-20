<?php

namespace App\Services;

use RuntimeException;

class OcrProcessingService
{
    public function __construct(
        private readonly ReceiptAnalysisProviderManager $providers,
        private readonly ReceiptDataExtractor $extractor,
    ) {}

    /**
     * Analyze an image from storage.
     *
     * @return array<string, mixed>
     */
    public function processImage(string $absoluteImagePath, ?string $countryCode = null): array
    {
        if (! is_readable($absoluteImagePath)) {
            throw new RuntimeException('The energy invoice image is not readable.');
        }

        $bytes = file_get_contents($absoluteImagePath);

        if ($bytes === false) {
            throw new RuntimeException('The energy invoice image could not be read.');
        }

        $mimeType = mime_content_type($absoluteImagePath) ?: 'application/octet-stream';
        $provider = $this->providers->driver();
        $analysis = $provider->analyze($bytes, $mimeType);
        $normalized = $this->extractor->extract($analysis, $countryCode);

        return [...$normalized, '_provider_response' => $analysis, '_provider' => $provider->name()];
    }

    /**
     * Analyze a base64 image submitted by a web or mobile client.
     *
     * @return array<string, mixed>
     */
    public function processEncodedImage(string $encodedImage, ?string $countryCode = null): array
    {
        if (str_starts_with($encodedImage, 'data:')) {
            $encodedImage = substr($encodedImage, (int) strpos($encodedImage, ',') + 1);
        }

        $bytes = base64_decode($encodedImage, true);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('The encoded energy invoice image is invalid.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';

        if (! in_array($mimeType, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            throw new RuntimeException('The encoded energy invoice image must be a JPEG, PNG, or PDF.');
        }

        $provider = $this->providers->driver();
        $analysis = $provider->analyze($bytes, $mimeType);
        $normalized = $this->extractor->extract($analysis, $countryCode);

        return [...$normalized, '_provider_response' => $analysis, '_provider' => $provider->name()];
    }
}
