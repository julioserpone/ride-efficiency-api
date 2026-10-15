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
     *     invoice_date: string|null,
     *     vendor_name: string|null,
     *     vendor_tax_id: string|null,
     *     vendor_address: string|null,
     *     vendor_commercial_name: string|null,
     *     station_name: string|null,
     *     station_brand: string|null,
     *     customer_name: string|null,
     *     customer_tax_id: string|null,
     *     customer_email: string|null,
     *     payment_method: string|null,
     *     vehicle_plate: string|null,
     *     invoice_number: string|null,
     *     authorization_number: string|null,
     *     items: list<array{
     *         product_description: string|null,
     *         item_code: string|null,
     *         quantity: float|null,
     *         quantity_unit: EnergyUnit|null,
     *         unit_price: float|null,
     *         unit_price_without_subsidy: float|null,
     *         subsidy_amount: float|null,
     *         subtotal_amount: float|null,
     *         tax_amount: float|null,
     *         total_amount: float|null,
     *         total_amount_without_subsidy: float|null
     *     }>,
     *     subtotal_amount: float|null,
     *     tax_amount: float|null,
     *     total_amount_without_subsidy: float|null
     * }
     */
    public function processImage(string $absoluteImagePath, ?string $countryCode = null): array
    {
        $rawText = $this->extractTextFromImage($absoluteImagePath);
        $energyAmount = $this->extractEnergyAmount($rawText);
        $totalAmount = $this->extractTotalAmount($rawText);
        $unitPrice = $this->extractUnitPrice($rawText);
        $taxRate = $this->extractTaxRate($rawText);
        $items = $this->extractInvoiceItems($rawText, $energyAmount['unit']);

        if (count($items) === 1) {
            $energyAmount['amount'] = $items[0]['quantity'] ?? $energyAmount['amount'];
            $energyAmount['unit'] = $items[0]['quantity_unit'] ?? $energyAmount['unit'];
            $unitPrice = $items[0]['unit_price'] ?? $unitPrice;
        } elseif (count($items) > 1) {
            $energyAmount = ['amount' => null, 'unit' => null];
            $unitPrice = null;
        }

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
            'fuel_type' => count($items) === 1
                ? $this->extractFuelType($items[0]['product_description'] ?? $rawText)
                : null,
            'invoice_date' => $this->extractInvoiceDate($rawText, $countryCode),
            'vendor_name' => $this->extractVendorName($rawText),
            'vendor_tax_id' => $this->extractVendorTaxId($rawText),
            'vendor_address' => $this->extractVendorAddress($rawText),
            'vendor_commercial_name' => $this->extractLabeledValue(
                $rawText,
                'commercial\\s+name|trade\\s+name|nombre\\s+comercial|raz[oó]n\\s+comercial',
            ),
            'station_name' => $this->extractStationName($rawText),
            'station_brand' => $this->extractLabeledValue(
                $rawText,
                'station\\s+brand|marca\\s+(?:de\\s+la\\s+)?estaci[oó]n',
            ),
            'customer_name' => $this->extractLabeledValue(
                $rawText,
                'customer(?:\\s+name)?|client(?:e)?|bill\\s+to|sold\\s+to|receptor',
            ),
            'customer_tax_id' => $this->extractCustomerTaxId($rawText),
            'customer_email' => $this->extractEmail($rawText),
            'payment_method' => $this->extractLabeledValue(
                $rawText,
                'payment\\s+method|forma\\s+(?:de\\s+)?pago|medio\\s+de\\s+pago|paid\\s+by',
            ),
            'vehicle_plate' => $this->extractLabeledValue(
                $rawText,
                'license\\s+plate|vehicle\\s+plate|placa|patente',
            ),
            'invoice_number' => $this->extractLabeledValue(
                $rawText,
                'invoice\\s*(?:no\\.?|number|#)|factura\\s*(?:no\\.?|n[uú]mero|#)|folio',
            ),
            'authorization_number' => $this->extractLabeledValue(
                $rawText,
                'access\\s+key|authorization\\s*(?:number|no\\.?|#)?|clave\\s+(?:de\\s+)?acceso(?:\\s*\\/\\s*no\\.?\\s*autorizaci[oó]n)?|no\\.?\\s*autorizaci[oó]n',
            ),
            'items' => $items,
            'subtotal_amount' => $this->extractLabeledAmount(
                $rawText,
                'sub\\s*total|subtotal\\s+amount|importe\\s+subtotal',
            ),
            'tax_amount' => $this->extractTaxAmount($rawText),
            'total_amount_without_subsidy' => $this->extractLabeledAmount(
                $rawText,
                'val(?:or)?\\.?\\s*tot(?:al)?\\.?\\s*sin\\s+sub(?:sidio)?|total\\s+without\\s+subsidy',
            ),
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
            '/(?:^|\R)\s*(?:grand\s+total|total(?!\s+(?:sin|without|excluding|before|subsidy))(?:\s+(?:amount\s+due|paid|amount|due|a\s+pagar|pagado))?|importe(?:\s+total)?|monto(?:\s+total)?|amount(?:\s+(?:paid|due))?|balance\s+due)\s*[:：]?\s*(?:usd|mxn|cop|pen|clp|eur|cad|aud|gbp)?\s*[$€£]?\s*([0-9][0-9.,]*)/iu',
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

        if (preg_match(
            '/(?:^|\R)\s*(?:art(?:icle)?\\.?|item|product)\\s*[:#-]?.*\\R\\s*([0-9][0-9.,]*)\\s+[0-9][0-9.,]*\\s+[0-9][0-9.,]*/iu',
            $text,
            $matches,
        )) {
            $amount = $this->parseLocalizedNumber($matches[1], 3);

            if ($amount !== null && $amount > 0 && $amount < 1000000) {
                return ['amount' => $amount, 'unit' => null];
            }
        }

        $quantityPattern = '/(?:energy\s+(?:delivered|amount|quantity)|electricity\s+consumed|cantidad\s+de\s+energ[ií]a|cantidad|cant\\.?|volumen|volume|quantity|energy)\s*[:：]?\s*([0-9][0-9.,]*)/iu';

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
        $pattern = '/(?:unit\s+price|price\s+per\s+unit|precio\s+unitario|precio\s+por\s+unidad|prec\\.?\\s*unit(?:ario)?\\.?|rate)\s*[:：]?\s*(?:usd|mxn|cop|pen|clp|eur|cad|aud|gbp)?\s*[$€£]?\s*([0-9][0-9.,]*)/iu';

        if (preg_match($pattern, $text, $matches)) {
            $price = $this->parseLocalizedNumber($matches[1], 4);

            return $price !== null && $price > 0 ? $price : null;
        }

        if (! preg_match(
            '/(?:^|\\R)\\s*(?:art(?:icle)?\\.?|item|product)\\s*[:#-]?.*\\R\\s*[0-9][0-9.,]*\\s+[0-9][0-9.,]*\\s+([0-9][0-9.,]*)\\s+[0-9][0-9.,]*\\s+[0-9][0-9.,]*/iu',
            $text,
            $matches,
        )) {
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
        $pattern = '/(?:tax(?:\s+rate)?|vat|iva|impuesto|tasa)\s*[:：]?\s*(?:\\(\\s*)?([0-9]+(?:[.,][0-9]{1,2})?)\s*%/iu';

        if (! preg_match($pattern, $text, $matches)) {
            return null;
        }

        return $this->parseLocalizedNumber($matches[1], 2);
    }

    private function extractLabeledAmount(string $text, string $labels, int $maximumDecimalPlaces = 2): ?float
    {
        $pattern = '/(?:^|\\R)\\s*(?:'.$labels.')\\s*[:：]?\\s*(?:usd|mxn|cop|pen|clp|eur|cad|aud|gbp)?\\s*[$€£]?\\s*([0-9][0-9.,]*)/iu';

        if (! preg_match($pattern, $text, $matches)) {
            return null;
        }

        return $this->parseLocalizedNumber($matches[1], $maximumDecimalPlaces);
    }

    private function extractTaxAmount(string $text): ?float
    {
        $pattern = '/(?:^|\\R)\\s*(?:tax|vat|iva|impuesto)\\s*(?:\\(\\s*[0-9]+(?:[.,][0-9]{1,2})?\\s*%\\s*\\))?\\s*[:：]?\\s*(?:usd|mxn|cop|pen|clp|eur|cad|aud|gbp)?\\s*[$€£]?\\s*([0-9][0-9.,]*)/iu';

        if (! preg_match($pattern, $text, $matches)) {
            return null;
        }

        return $this->parseLocalizedNumber($matches[1], 2);
    }

    private function extractVendorName(string $text): ?string
    {
        $name = $this->extractLabeledValue(
            $text,
            'supplier|provider|vendor|merchant|proveedor|emisor|legal\\s+name|business\\s+name|raz[oó]n\\s+social',
        );

        if ($name !== null) {
            return $name;
        }

        $lines = $this->nonEmptyLines($text);
        $name = $lines[0] ?? null;

        return $name !== null && preg_match('/^[\\pL\\pN&.,()\\-\\s]+$/u', $name) ? $name : null;
    }

    private function extractVendorTaxId(string $text): ?string
    {
        $customerPosition = preg_match(
            '/(?:customer(?:\\s+name)?|client(?:e)?|bill\\s+to|sold\\s+to|receptor)\\s*[:：]?/iu',
            $text,
            $customerMatch,
            PREG_OFFSET_CAPTURE,
        ) ? $customerMatch[0][1] : strlen($text);
        $stationPosition = preg_match(
            '/(?:estaci[oó]n\\s+de\\s+servicio|service\\s+station)\\b/iu',
            $text,
            $stationMatch,
            PREG_OFFSET_CAPTURE,
        ) ? $stationMatch[0][1] : $customerPosition;
        $vendorSection = substr($text, 0, min($customerPosition, $stationPosition));
        $identified = $this->extractLabeledValue(
            $vendorSection,
            'tax\\s*(?:id|identification|number)|vat\\s*(?:id|number)|ruc|rfc|nit|rut|gst\\s*(?:id|number)',
        );

        if ($identified !== null && preg_match('/[\\pL\\pN][\\pL\\pN.\\/-]{7,24}/u', $identified, $match)) {
            return $match[0];
        }

        $lines = $this->nonEmptyLines($vendorSection);

        foreach (array_slice($lines, 1) as $line) {
            if (preg_match('/^\\s*(\\d[\\d\\s.-]{8,24})\\s*$/u', $line, $matches)) {
                $identifier = preg_replace('/\\D/', '', $matches[1]) ?? '';

                if (mb_strlen($identifier) >= 9 && mb_strlen($identifier) <= 20) {
                    return $identifier;
                }
            }
        }

        return null;
    }

    private function extractVendorAddress(string $text): ?string
    {
        $address = $this->extractLabeledValue($text, 'address|direcci[oó]n|domicilio');

        if ($address !== null) {
            return $address;
        }

        $stationPosition = preg_match(
            '/(?:estaci[oó]n\\s+de\\s+servicio|service\\s+station|gas\\s+station)\\b/iu',
            $text,
            $stationMatch,
            PREG_OFFSET_CAPTURE,
        ) ? $stationMatch[0][1] : strlen($text);

        $vendorSection = substr($text, 0, $stationPosition);

        foreach ($this->nonEmptyLines($vendorSection) as $line) {
            if (preg_match('/^\\s*(?:av(?:enida)?\\.?|calle|cl\\.?|street|st\\.?|road|rd\\.?|blvd\\.?|boulevard)\\b/iu', $line)) {
                return trim($line);
            }
        }

        return null;
    }

    private function extractStationName(string $text): ?string
    {
        $station = $this->extractLabeledValue(
            $text,
            'estaci[oó]n\\s+de\\s+servicio|service\\s+station|gas\\s+station|station\\s+name',
        );

        return $station === null
            ? null
            : trim((string) preg_replace('/\\s+(?:address|direcci[oó]n)\\s*[:：].*$/iu', '', $station));
    }

    private function extractCustomerTaxId(string $text): ?string
    {
        $customerPosition = preg_match(
            '/(?:customer(?:\\s+name)?|client(?:e)?|bill\\s+to|sold\\s+to|receptor)\\s*[:：]?/iu',
            $text,
            $customerMatch,
            PREG_OFFSET_CAPTURE,
        ) ? $customerMatch[0][1] : null;

        if ($customerPosition === null) {
            return null;
        }

        $customerSection = substr($text, $customerPosition);
        $identifier = $this->extractLabeledValue(
            $customerSection,
            'tax\\s*(?:id|identification|number)|vat\\s*(?:id|number)|ruc(?:\\s*\\/\\s*(?:ci|ct))?|rfc|nit|rut|dni|ci',
        );

        return $identifier === null ? null : trim($identifier);
    }

    private function extractEmail(string $text): ?string
    {
        return preg_match('/\\b([\\w.+-]+@[\\w.-]+\\.[A-Z]{2,})\\b/iu', $text, $matches)
            ? $matches[1]
            : null;
    }

    private function extractProductDescription(string $text): ?string
    {
        if (! preg_match(
            '/(?:^|\\R)\\s*(?:art(?:icle)?\\.?|item|product)\\s*[:#-]?\\s*(.+?)(?:\\s+cod(?:igo)?\\.?\\s*[:#-]?.*)?\\s*$/imu',
            $text,
            $matches,
        )) {
            return null;
        }

        $description = trim($matches[1]);

        return $description === '' ? null : $description;
    }

    /**
     * @return list<array{
     *     product_description: string|null,
     *     item_code: string|null,
     *     quantity: float|null,
     *     quantity_unit: EnergyUnit|null,
     *     unit_price: float|null,
     *     unit_price_without_subsidy: float|null,
     *     subsidy_amount: float|null,
     *     subtotal_amount: float|null,
     *     tax_amount: float|null,
     *     total_amount: float|null,
     *     total_amount_without_subsidy: float|null
     * }>
     */
    private function extractInvoiceItems(string $text, ?EnergyUnit $quantityUnit): array
    {
        $items = $this->extractTabularInvoiceItems($text, $quantityUnit);

        if ($items !== []) {
            return $items;
        }

        $lines = $this->nonEmptyLines($text);

        foreach ($lines as $index => $line) {
            if (! preg_match('/^\\s*(?:art(?:icle)?\\.?|item|product)\\s*[:#-]?\\s*(.*)$/iu', $line, $matches)) {
                continue;
            }

            $description = trim((string) preg_replace(
                '/\\s+(?:cod(?:igo)?\\.?|item\\s*(?:code|#)|sku)\\s*[:#-]?.*$/iu',
                '',
                $matches[1],
            ));
            $itemCode = $this->extractItemCode($line);
            $numbers = [];

            foreach (array_slice($lines, $index + 1, 3) as $followingLine) {
                if (preg_match('/^\\s*(?:art(?:icle)?\\.?|item|product)\\b/iu', $followingLine)) {
                    break;
                }

                $numbers = $this->extractNumbers($followingLine);

                if (count($numbers) >= 5) {
                    break;
                }
            }

            if ($description !== '' && count($numbers) >= 5) {
                $items[] = $this->makeInvoiceItem($description, $itemCode, $numbers, $quantityUnit);
            }
        }

        if ($items !== []) {
            return $items;
        }

        $quantity = $this->extractEnergyAmount($text);

        if ($quantity['amount'] === null) {
            return [];
        }

        return [[
            'product_description' => $this->extractProductDescription($text) ?? $this->extractFuelType($text),
            'item_code' => null,
            'quantity' => $quantity['amount'],
            'quantity_unit' => $quantity['unit'],
            'unit_price' => $this->extractUnitPrice($text),
            'unit_price_without_subsidy' => null,
            'subsidy_amount' => null,
            'subtotal_amount' => $this->extractLabeledAmount($text, 'sub\\s*total|subtotal\\s+amount|importe\\s+subtotal'),
            'tax_amount' => null,
            'total_amount' => $this->extractTotalAmount($text),
            'total_amount_without_subsidy' => null,
        ]];
    }

    /**
     * @return list<array{
     *     product_description: string|null,
     *     item_code: string|null,
     *     quantity: float|null,
     *     quantity_unit: EnergyUnit|null,
     *     unit_price: float|null,
     *     unit_price_without_subsidy: float|null,
     *     subsidy_amount: float|null,
     *     subtotal_amount: float|null,
     *     tax_amount: float|null,
     *     total_amount: float|null,
     *     total_amount_without_subsidy: float|null
     * }>
     */
    private function extractTabularInvoiceItems(string $text, ?EnergyUnit $quantityUnit): array
    {
        $lines = $this->nonEmptyLines($text);
        $headerIndex = null;

        foreach ($lines as $index => $line) {
            if (preg_match('/\\b(?:cant(?:idad)?|quantity)\\b/iu', $line)
                && preg_match('/\\b(?:descrip(?:ci[oó]n)?|description|product)\\b/iu', $line)) {
                $headerIndex = $index;

                break;
            }
        }

        if ($headerIndex === null) {
            return [];
        }

        $items = [];
        $pendingDescription = null;
        $pendingCode = null;

        foreach (array_slice($lines, $headerIndex + 1) as $line) {
            if (preg_match('/^\\s*(?:sub\\s*total|iva|vat|tax|total|valor\\s+total|grand\\s+total)\\b/iu', $line)) {
                break;
            }

            if (preg_match('/^\\s*(?:art(?:icle)?\\.?|item|product)\\s*[:#-]?\\s*(.*)$/iu', $line, $matches)) {
                $pendingDescription = trim((string) preg_replace(
                    '/\\s+(?:cod(?:igo)?\\.?|item\\s*(?:code|#)|sku)\\s*[:#-]?.*$/iu',
                    '',
                    $matches[1],
                ));
                $pendingCode = $this->extractItemCode($line);

                continue;
            }

            $numberMatches = [];
            preg_match_all('/(?<![\\pL\\pN])[0-9]+(?:[.,][0-9]+)?/u', $line, $numberMatches, PREG_OFFSET_CAPTURE);
            $numbers = array_column($numberMatches[0], 0);

            if (count($numbers) < 5) {
                continue;
            }

            $description = $pendingDescription;
            $itemCode = $pendingCode;

            if ($description === null) {
                $firstNumber = $numberMatches[0][0];
                $secondNumber = $numberMatches[0][1] ?? null;

                if ($secondNumber === null) {
                    continue;
                }

                $descriptionStart = $firstNumber[1] + mb_strlen($firstNumber[0]);
                $descriptionLength = max(0, $secondNumber[1] - $descriptionStart);
                $description = trim(substr($line, $descriptionStart, $descriptionLength));

                if (preg_match('/(?<!\\d)(\\d{3,})(?!\\d)/', $description, $codeMatch)) {
                    $itemCode = $codeMatch[1];
                    $description = trim(preg_replace('/\\b'.preg_quote($itemCode, '/').'\\b/', '', $description) ?? $description);
                }
            }

            if ($description === '') {
                continue;
            }

            $items[] = $this->makeInvoiceItem($description, $itemCode, $numbers, $quantityUnit);
            $pendingDescription = null;
            $pendingCode = null;
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function extractNumbers(string $text): array
    {
        preg_match_all('/(?<![\\pL\\pN])[0-9]+(?:[.,][0-9]+)?/u', $text, $matches);

        return $matches[0];
    }

    /**
     * @param  list<string>  $numbers
     * @return array{
     *     product_description: string|null,
     *     item_code: string|null,
     *     quantity: float|null,
     *     quantity_unit: EnergyUnit|null,
     *     unit_price: float|null,
     *     unit_price_without_subsidy: float|null,
     *     subsidy_amount: float|null,
     *     subtotal_amount: float|null,
     *     tax_amount: float|null,
     *     total_amount: float|null,
     *     total_amount_without_subsidy: float|null
     * }
     */
    private function makeInvoiceItem(
        string $description,
        ?string $itemCode,
        array $numbers,
        ?EnergyUnit $quantityUnit,
    ): array {
        $amounts = array_map(
            fn (string $number): ?float => $this->parseLocalizedNumber($number, 4),
            $numbers,
        );

        return [
            'product_description' => $description,
            'item_code' => $itemCode,
            'quantity' => $amounts[0] ?? null,
            'quantity_unit' => $quantityUnit,
            'unit_price_without_subsidy' => $amounts[1] ?? null,
            'unit_price' => $amounts[2] ?? $amounts[1] ?? null,
            'subsidy_amount' => $amounts[3] ?? null,
            'subtotal_amount' => $amounts[4] ?? null,
            'tax_amount' => null,
            'total_amount' => $amounts[5] ?? null,
            'total_amount_without_subsidy' => $amounts[6] ?? null,
        ];
    }

    private function extractItemCode(string $text): ?string
    {
        if (! preg_match('/\\b(?:cod(?:igo)?\\.?|item\\s*(?:code|#)|sku)\\s*[:#-]?\\s*([\\pL\\pN-]+)/iu', $text, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function extractLabeledValue(string $text, string $labels): ?string
    {
        if (! preg_match(
            '/(?:^|\\R)\\s*(?:'.$labels.')\\s*[:：#-]?\\s*([^\\r\\n]*)/iu',
            $text,
            $matches,
        )) {
            return null;
        }

        $value = trim($matches[1]);

        if ($value === '') {
            $lines = $this->nonEmptyLines(substr($text, strpos($text, $matches[0]) + strlen($matches[0])));
            $value = $lines[0] ?? '';
        }

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function nonEmptyLines(string $text): array
    {
        $lines = preg_split('/\\R/u', $text) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $line): bool => $line !== '',
        ));
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
            'Premium' => ['premium', 'v-power', 'super'],
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
        $timePattern = '(?:[ T]+(\\d{1,2}):(\\d{2})(?::(\\d{2}))?\\s*(am|pm)?)?';
        $patterns = [
            '/\\b(\\d{4})[\\/.\\-](\\d{1,2})[\\/.\\-](\\d{1,2})'.$timePattern.'\\b/i',
            '/(?<!\\d)(\\d{4})[\\/.\\-](\\d{1,2})[\\/.\\-](\\d{1,2})'.$timePattern.'/i',
            '/\\b(\\d{1,2})[\\/.\\-](\\d{1,2})[\\/.\\-](\\d{2,4})'.$timePattern.'\\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            $first = $matches[1];
            $second = $matches[2];
            $dayOrYear = $matches[3];
            $isYearFirst = strlen($first) === 4;
            $year = (int) ($isYearFirst ? $first : $dayOrYear);

            if (! $isYearFirst && strlen($dayOrYear) === 2) {
                $year += $year >= 70 ? 1900 : 2000;
            }

            $usMonthFirst = strtoupper((string) $countryCode) === 'US';
            $month = (int) ($isYearFirst ? $second : ($usMonthFirst ? $first : $second));
            $day = (int) ($isYearFirst ? $dayOrYear : ($usMonthFirst ? $second : $first));

            if (! checkdate($month, $day, $year)) {
                continue;
            }

            $hasTime = isset($matches[4]) && $matches[4] !== '';
            $hour = isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : 0;
            $minute = isset($matches[5]) && $matches[5] !== '' ? (int) $matches[5] : 0;
            $second = isset($matches[6]) && $matches[6] !== '' ? (int) $matches[6] : 0;
            $meridiem = isset($matches[7]) ? strtolower($matches[7]) : '';

            if ($meridiem !== '') {
                if ($hour < 1 || $hour > 12) {
                    continue;
                }

                $hour = ($hour % 12) + ($meridiem === 'pm' ? 12 : 0);
            }

            if ($hour > 23 || $minute > 59 || $second > 59) {
                continue;
            }

            $date = Carbon::create($year, $month, $day, $hour, $minute, $second);

            if ($date->year >= 2020 && $date->lte(Carbon::now())) {
                return $date->format($hasTime ? 'Y-m-d H:i:s' : 'Y-m-d');
            }
        }

        return null;
    }
}
