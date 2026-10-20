<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AzureDocumentIntelligenceClient implements ReceiptAnalysisProvider
{
    public function name(): string
    {
        return 'azure';
    }

    /**
     * Submit a document and poll Azure until the analysis operation completes.
     *
     * @return array<string, mixed>
     */
    public function analyze(string $imageBytes, string $contentType): array
    {
        $endpoint = rtrim((string) config('ocr.azure.endpoint'), '/');
        $key = (string) config('ocr.azure.key');

        if ($endpoint === '' || $key === '') {
            throw new RuntimeException('Azure Document Intelligence is not configured.');
        }

        $model = (string) config('ocr.azure.model', 'prebuilt-receipt');
        $apiVersion = (string) config('ocr.azure.api_version', '2024-11-30');
        $url = "{$endpoint}/documentintelligence/documentModels/{$model}:analyze";
        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $key,
            'Content-Type' => $contentType,
        ])->timeout((int) config('ocr.azure.timeout_seconds', 60))
            ->withQueryParameters(['api-version' => $apiVersion])
            ->withBody($imageBytes, $contentType)
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException('Azure Document Intelligence rejected the receipt image.');
        }

        $operationUrl = $response->header('Operation-Location');

        if ($operationUrl === '') {
            throw new RuntimeException('Azure Document Intelligence did not return an operation URL.');
        }

        $deadline = microtime(true) + (int) config('ocr.azure.timeout_seconds', 60);

        do {
            usleep(max(100, (int) config('ocr.azure.poll_interval_ms', 500)) * 1000);
            $result = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $key])
                ->timeout((int) config('ocr.azure.timeout_seconds', 60))
                ->get($operationUrl);

            if (! $result->successful()) {
                throw new RuntimeException('Azure Document Intelligence analysis polling failed.');
            }

            /** @var array<string, mixed> $payload */
            $payload = $result->json() ?? [];
            $status = strtolower((string) ($payload['status'] ?? ''));

            if ($status === 'succeeded') {
                return $payload;
            }

            if (in_array($status, ['failed', 'canceled'], true)) {
                throw new RuntimeException('Azure Document Intelligence could not analyze the receipt.');
            }
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Azure Document Intelligence analysis timed out.');
    }
}
