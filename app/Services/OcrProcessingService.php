<?php

namespace App\Services;

use GigEconomics\Domain\Shared\EnergyUnit;
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
     *     energy_amount: float|null,
     *     energy_unit: EnergyUnit|null,
     *     unit_price: float|null,
     *     tax_rate: float|null,
     *     fuel_volume_units: float|null,
     *     fuel_type: string|null,
     *     invoice_date: string|null
     * }
     */
    public function processImage(string $absoluteImagePath, ?string $countryCode = null): array
    {
        $rawText = $this->extractTextFromImage($absoluteImagePath);
        $energyAmount = $this->extractEnergyAmount($rawText);
        $totalAmount = $this->extractTotalAmount($rawText);
        $unitPrice = $this->extractUnitPrice($rawText);
        $taxRate = $this->extractTaxRate($rawText);

        if ($unitPrice === null && $energyAmount['amount'] !== null && $totalAmount !== null) {
            $unitPrice = $totalAmount / $energyAmount['amount'];
            $taxRate = 0.0;
        }

        return [
            'raw_text' => $rawText,
            'total_amount_paid' => $totalAmount,
            'energy_amount' => $energyAmount['amount'],
            'energy_unit' => $energyAmount['unit'],
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'fuel_volume_units' => $energyAmount['amount'],
            'fuel_type' => $this->extractFuelType($rawText),
            'invoice_date' => $this->extractInvoiceDate($rawText, $countryCode),
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
        $patterns = [
            '/(?:grand\s+total|total(?:\s+(?:amount\s+due|paid|amount|due|a\s+pagar|pagado))?|importe(?:\s+total)?|monto(?:\s+total)?|amount(?:\s+(?:paid|due))?|balance\s+due)\s*[:：]?\s*[$€£]?\s*([0-9][0-9.,]*)/iu',
            '/(?:mxn|usd|cop|pen|clp|pesos?|dollars?)\s*[$€£]?\s*([0-9][0-9.,]*)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $amount = $this->parseLocalizedNumber($matches[1], 2);

                if ($amount !== null && $amount > 0 && $amount < 1000000000) {
                    return $amount;
                }
            }
        }

        return null;
    }

    /**
     * Extract energy quantity and its explicit invoice unit.
     *
     * @return array{amount: float|null, unit: EnergyUnit|null}
     */
    private function extractEnergyAmount(string $text): array
    {
        $patterns = [
            '/([0-9][0-9.,]*)\s*(?:kwh|kw\s*h|kilowatt[\s-]*hours?|kilovatios?\s*hora?s?)(?![a-z])/iu' => EnergyUnit::KilowattHours,
            '/([0-9][0-9.,]*)\s*(?:lit(?:er|re)s?|litros?|lts?|l)(?![a-z])/iu' => EnergyUnit::Litres,
            '/([0-9][0-9.,]*)\s*(?:gallons?\s+(?:gasoline\s+)?equivalent|gge|(?:us\s*)?gallons?|galones?|gals?|gal)(?![a-z])/iu' => EnergyUnit::Gallons,
        ];

        foreach ($patterns as $pattern => $unit) {
            if (preg_match($pattern, $text, $matches)) {
                $amount = $this->parseLocalizedNumber($matches[1], 3);

                if ($amount !== null && $amount > 0 && $amount < 1000000) {
                    return ['amount' => $amount, 'unit' => $unit];
                }
            }
        }

        $quantityPattern = '/(?:energy\s+(?:delivered|amount|quantity)|electricity\s+consumed|cantidad\s+de\s+energ[ií]a|cantidad|volumen|volume|quantity|energy)\s*[:：]?\s*([0-9][0-9.,]*)/iu';

        if (preg_match($quantityPattern, $text, $matches)) {
            $amount = $this->parseLocalizedNumber($matches[1], 3);

            if ($amount !== null && $amount > 0 && $amount < 1000000) {
                return ['amount' => $amount, 'unit' => null];
            }
        }

        return ['amount' => null, 'unit' => null];
    }

    /**
     * Extract an explicitly printed price per energy unit.
     */
    private function extractUnitPrice(string $text): ?float
    {
        $pattern = '/(?:unit\s+price|price\s+per\s+unit|precio\s+unitario|precio\s+por\s+unidad|rate)\s*[:：]?\s*[$€£]?\s*([0-9][0-9.,]*)/iu';

        if (! preg_match($pattern, $text, $matches)) {
            return null;
        }

        $price = $this->parseLocalizedNumber($matches[1], 4);

        return $price !== null && $price > 0 ? $price : null;
    }

    /**
     * Extract a printed tax percentage when present.
     */
    private function extractTaxRate(string $text): ?float
    {
        $pattern = '/(?:tax(?:\s+rate)?|vat|iva|impuesto|tasa)\s*[:：]?\s*([0-9]+(?:[.,][0-9]{1,2})?)\s*%/iu';

        if (! preg_match($pattern, $text, $matches)) {
            return null;
        }

        return $this->parseLocalizedNumber($matches[1], 2);
    }

    /**
     * Convert common English and Spanish decimal/thousands separators.
     */
    private function parseLocalizedNumber(string $value, int $maximumDecimalPlaces): ?float
    {
        $value = preg_replace('/[^0-9,.\-]/', '', $value) ?? '';

        if ($value === '' || ! preg_match('/^-?[0-9][0-9.,]*$/', $value)) {
            return null;
        }

        $comma = strrpos($value, ',');
        $period = strrpos($value, '.');
        $decimalSeparator = null;

        if ($comma !== false && $period !== false) {
            $decimalSeparator = $comma > $period ? ',' : '.';
        } elseif ($comma !== false || $period !== false) {
            $separator = $comma !== false ? ',' : '.';
            $lastSeparator = strrpos($value, $separator);
            $fractionLength = strlen($value) - $lastSeparator - 1;

            if (substr_count($value, $separator) === 1 && $fractionLength > 0 && $fractionLength <= $maximumDecimalPlaces) {
                $decimalSeparator = $separator;
            }
        }

        if ($decimalSeparator !== null) {
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $value = str_replace($thousandsSeparator, '', $value);
            $value = str_replace($decimalSeparator, '.', $value);
        } else {
            $value = str_replace([',', '.'], '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Extract fuel type from OCR text.
     */
    private function extractFuelType(string $text): ?string
    {
        $knownNames = [
            'Ecopaís' => ['ecopais', 'ecopaís'],
            'Extra' => ['extra'],
            'Magna' => ['magna'],
            'Premium' => ['premium'],
            'Regular' => ['regular', 'unleaded'],
            'Diesel' => ['diesel', 'acpm'],
            'Electric' => ['kwh', 'kilowatt-hour', 'kilowatt hour'],
        ];

        $lowerText = mb_strtolower($text);

        foreach ($knownNames as $type => $keywords) {
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
    private function extractInvoiceDate(string $text, ?string $countryCode = null): ?string
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
            $usMonthFirst = strtoupper((string) $countryCode) === 'US';
            $month = (int) ($isYearFirst ? $second : ($usMonthFirst ? $first : $second));
            $day = (int) ($isYearFirst ? $third : ($usMonthFirst ? $second : $first));

            if (! checkdate($month, $day, $year)) {
                continue;
            }

            $date = Carbon::create($year, $month, $day)->startOfDay();

            if ($date->year >= 2020 && $date->lte(Carbon::now())) {
                return $date->toDateString();
            }

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
