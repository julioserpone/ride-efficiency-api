<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use thiagoalessio\TesseractOCR\TesseractOCR;

class OcrProcessingService
{
    /**
     * Process an image file and extract fuel invoice data.
     *
     * @param  string  $absoluteImagePath
     * @return array{
     *     raw_text: string,
     *     total_amount_paid: float|null,
     *     fuel_volume_units: float|null,
     *     fuel_type: string|null,
     *     invoice_date: string|null
     * }
     */
    public function processImage(string $absoluteImagePath): array
    {
        $rawText = $this->extractTextFromImage($absoluteImagePath);

        return [
            'raw_text' => $rawText,
            'total_amount_paid' => $this->extractTotalAmount($rawText),
            'fuel_volume_units' => $this->extractFuelVolume($rawText),
            'fuel_type' => $this->extractFuelType($rawText),
            'invoice_date' => $this->extractInvoiceDate($rawText),
        ];
    }

    /**
     * Extract raw text from the image using Tesseract OCR.
     */
    private function extractTextFromImage(string $imagePath): string
    {
        try {
            $ocr = new TesseractOCR($imagePath);
            $ocr->lang('spa', 'eng');
            $ocr->psm(6);

            return $ocr->run() ?? '';
        } catch (\Exception $e) {
            Log::warning('Tesseract OCR failed, falling back to empty text.', [
                'error' => $e->getMessage(),
                'image' => $imagePath,
            ]);

            return '';
        }
    }

    /**
     * Extract the total amount paid from the OCR text.
     */
    private function extractTotalAmount(string $text): ?float
    {
        // Patterns: "Total: $450.00", "TOTAL $450", "Total Pago: 450.50", "Importe: 450"
        $patterns = [
            '/(?:total|importe|pago|amount)[\s:$]*\$?\s*([\d,]+\.?\d{0,2})/i',
            '/\$\s*([\d,]+\.\d{2})/i',
            '/(?:mxn|usd|pesos?)\s*([\d,]+\.?\d{0,2})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $amount = (float) str_replace(',', '', $matches[1]);

                if ($amount > 0 && $amount < 10000) {
                    return $amount;
                }
            }
        }

        return null;
    }

    /**
     * Extract fuel volume (liters/gallons) from OCR text.
     */
    private function extractFuelVolume(string $text): ?float
    {
        // Patterns: "25.50 L", "25.50 litros", "5.5 gal", "Volumen: 30.00"
        $patterns = [
            '/([\d]+\.?\d{0,3})\s*(?:litros?|lts?|l\.)\b/i',
            '/([\d]+\.?\d{0,3})\s*(?:galones?|gals?|gal\.)\b/i',
            '/(?:volumen|vol|litros?|cantidad)[\s:]*([0-9]+\.?[0-9]*)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $volume = (float) $matches[1];

                if ($volume > 0 && $volume < 1000) {
                    return $volume;
                }
            }
        }

        return null;
    }

    /**
     * Extract fuel type from OCR text.
     */
    private function extractFuelType(string $text): ?string
    {
        $fuelTypes = [
            'Magna' => ['magna', 'regular'],
            'Premium' => ['premium', 'super', 'plus'],
            'Diesel' => ['diesel', 'diesel s'],
        ];

        $lowerText = strtolower($text);

        foreach ($fuelTypes as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lowerText, $keyword)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * Extract invoice date from OCR text.
     */
    private function extractInvoiceDate(string $text): ?string
    {
        // Patterns: "01/08/2026", "2026-08-01", "01 ago 2026", "1 de agosto de 2026"
        $patterns = [
            '/\b(\d{2})[\/\-](\d{2})[\/\-](\d{4})\b/',
            '/\b(\d{4})[\/\-](\d{2})[\/\-](\d{2})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                try {
                    $dateStr = $matches[0];
                    $date = Carbon::parse($dateStr);

                    if ($date->year >= 2020 && $date->lte(Carbon::now())) {
                        return $date->toDateString();
                    }
                } catch (\Exception) {
                    continue;
                }
            }
        }

        return null;
    }
}
