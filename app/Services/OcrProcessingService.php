<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use thiagoalessio\TesseractOCR\Option;
use thiagoalessio\TesseractOCR\TesseractOCR;

class OcrProcessingService
{
    /**
     * Tesseract PSM values 3 and above are only understood from Tesseract 4 onwards.
     */
    private const PSM_MINIMUM_VERSION = '4.0.0';

    /**
     * Language pack used when none of the configured languages is installed.
     */
    private const FALLBACK_LANGUAGE = 'eng';

    /**
     * Process an image file and extract fuel invoice data.
     *
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
     *
     * Returns an empty string when the file cannot be read or Tesseract fails,
     * so the caller degrades gracefully instead of aborting the queue job.
     */
    public function extractTextFromImage(string $imagePath): string
    {
        try {
            if (! is_file($imagePath) || ! is_readable($imagePath)) {
                Log::warning('OCR skipped because the image is not readable.', [
                    'image' => $imagePath,
                ]);

                return '';
            }

            return trim($this->runTesseract($imagePath), " \t\n\r\0\x0B\x0C");
        } catch (\Exception $e) {
            Log::warning('Tesseract OCR failed, falling back to empty text.', [
                'error' => $e->getMessage(),
                'image' => $imagePath,
            ]);

            return '';
        }
    }

    /**
     * Run Tesseract with the configured segmentation mode and languages.
     *
     * The flags are built with the Option helpers instead of the fluent
     * setters of the library: those are exposed through TesseractOCR::__call(),
     * which static analysis cannot resolve, and they skip the version check of
     * this quiet library. They are pushed onto Command::$options (the property
     * the library actually reads) and NOT onto TesseractOCR::$options, which
     * does not exist and would silently create a deprecated dynamic property.
     */
    private function runTesseract(string $imagePath): string
    {
        $ocr = new TesseractOCR($imagePath);
        $ocr->configFile('quiet');

        $languages = $this->resolveLanguages($ocr);

        if ($languages !== []) {
            $ocr->command->options[] = Option::lang(...$languages);
        }

        if (version_compare($this->normalizedTesseractVersion($ocr), self::PSM_MINIMUM_VERSION, '>=')) {
            $ocr->command->options[] = Option::psm($this->configuredPsm());
        }

        return (string) $ocr->run();
    }

    /**
     * Page segmentation mode requested from Tesseract, clamped to the values
     * the binary accepts.
     */
    private function configuredPsm(): int
    {
        return max(0, min(13, (int) config('ocr.psm', 6)));
    }

    /**
     * Keep only the installed languages, preferring the configured order and
     * falling back to English when the local tessdata has none of them.
     *
     * @return list<string>
     */
    private function resolveLanguages(TesseractOCR $ocr): array
    {
        /** @var list<string> $configured */
        $configured = config('ocr.languages', ['spa', 'eng']);

        try {
            $installed = array_map('strtolower', $ocr->availableLanguages());
        } catch (\Exception $e) {
            Log::info('Could not list the installed Tesseract languages.', [
                'error' => $e->getMessage(),
            ]);

            return $configured;
        }

        if ($installed === []) {
            return $configured;
        }

        $available = array_values(array_filter(
            $configured,
            static fn (string $language): bool => in_array(strtolower($language), $installed, true),
        ));

        if ($available !== []) {
            return $available;
        }

        return in_array(self::FALLBACK_LANGUAGE, $installed, true) ? [self::FALLBACK_LANGUAGE] : [];
    }

    /**
     * Read the Tesseract version, returning a pessimistic default when it
     * cannot be detected (the executable is missing, for instance).
     */
    private function normalizedTesseractVersion(TesseractOCR $ocr): string
    {
        try {
            return (string) preg_replace('/^v/', '', $ocr->version());
        } catch (\Exception $e) {
            Log::info('Could not detect the Tesseract version; assuming legacy flags.', [
                'error' => $e->getMessage(),
            ]);

            return '0.0.0';
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
        // Year-first ("2026-08-01") comes first because it is unambiguous, so
        // it wins over any ambiguous "dd/mm/yyyy" fragment found earlier.
        // The lookbehind anchoring is used when there is no leading \b because
        // the year may be glued to other digits, e.g. a folio number.
        $patterns = [
            '/\b(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})\b/',
            '/(?<!\d)(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/',
            '/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            [$full, $first, $second, $third] = $matches;

            // The date is built explicitly instead of relying on the ambiguous
            // formats of the parser: Carbon reads "01/08/2026" as January 8th
            // (US) but "01-08-2026" as August 1st (day first).
            $isYearFirst = strlen($first) === 4;
            $year = (int) ($isYearFirst ? $first : $third);
            $month = (int) ($isYearFirst ? $second : $second);
            $day = (int) ($isYearFirst ? $third : $first);

            if (! checkdate($month, $day, $year)) {
                continue;
            }

            $date = Carbon::create($year, $month, $day)->startOfDay();

            if ($date->year >= 2020 && $date->lte(Carbon::now())) {
                return $date->toDateString();
            }

            // A "dd/mm/yyyy" invoice read as month-first may end up in the
            // future, so retry the swapped day and month before giving up.
            if (! $isYearFirst && checkdate($day, $month, $year)) {
                $swapped = Carbon::create($year, $day, $month)->startOfDay();

                if ($swapped->year >= 2020 && $swapped->lte(Carbon::now())) {
                    return $swapped->toDateString();
                }
            }

            unset($full);
        }

        return null;
    }
}
