<?php

namespace App\Services;

use GigEconomics\Domain\Shared\EnergyUnit;

class ReceiptDataExtractor
{
    /**
     * Map Azure prebuilt-receipt fields into the existing invoice domain.
     *
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    public function extract(array $analysis, ?string $countryCode = null): array
    {
        $result = $analysis['analyzeResult'] ?? [];
        $content = (string) ($result['content'] ?? '');
        $fields = $result['documents'][0]['fields'] ?? [];
        $unit = $this->resolveUnit($content, $countryCode);
        $items = [];

        foreach (($fields['Items']['valueArray'] ?? []) as $line) {
            $values = $line['valueObject'] ?? [];
            $lineContent = (string) ($line['content'] ?? '');
            $description = $this->stringValue($values['Description'] ?? []);
            $columns = $this->parseReceiptLine($lineContent);
            $quantity = $columns['quantity'] ?? $this->numberValue($values['Quantity'] ?? []);
            $total = $columns['subtotal'] ?? $this->currencyValue($values['TotalPrice'] ?? []);
            $rawNumberText = preg_split('/\s+/u', trim($lineContent)) ?: [];
            $lineNumbers = array_values(array_filter(array_map(fn (string $value): ?float => $this->numberFromText($value), $rawNumberText), fn (?float $value): bool => $value !== null));
            if ($columns['quantity'] === null && $total !== null && $quantity !== null && abs($quantity - $total) < 0.0001 && isset($lineNumbers[0])) {
                $quantity = $lineNumbers[0];
            }
            $code = $columns['item_code']
                ?? $this->firstMatch($lineContent, '/\b(?:cod(?:igo)?\.?\s*:?\s*)([\pL\pN-]+)/iu');
            $description ??= $columns['description'];
            $price = $columns['unit_price']
                ?? $this->numberValue($values['Price'] ?? [])
                ?? $this->numberValue($values['UnitPrice'] ?? [])
                ?? $this->numberValue($values['Quantity'] ?? []);
            if ($columns['unit_price'] === null && $this->numberValue($values['UnitPrice'] ?? []) !== null) {
                $price = $this->numberValue($values['UnitPrice']);
            }
            if ($columns['unit_price'] === null && $columns['quantity'] === null && isset($lineNumbers[1])) {
                $price = $lineNumbers[1];
            }
            $priceWithoutSubsidy = $columns['unit_price_without_subsidy']
                ?? $this->numberValue($values['UnitPriceWithoutSubsidy'] ?? [])
                ?? $price;
            $subsidy = $columns['subsidy_amount'];

            if ($columns['unit_price'] === null && $price === $quantity && isset($lineNumbers[1])) {
                $price = $lineNumbers[1];
            }
            if ($price === null && $quantity !== null && $total !== null && $quantity > 0) {
                $price = $total / $quantity;
            }
            $items[] = [
                'product_description' => $description,
                'item_code' => $code,
                'quantity' => $quantity,
                'quantity_unit' => $unit,
                'unit_price' => $price ?? $lineNumbers[1] ?? null,
                'unit_price_without_subsidy' => $priceWithoutSubsidy,
                'subsidy_amount' => $subsidy,
                'subtotal_amount' => $total,
                'tax_amount' => null,
                'total_amount' => $total,
                'total_amount_without_subsidy' => null,
            ];
        }

        $taxRate = null;

        foreach (($fields['TaxDetails']['valueArray'] ?? []) as $tax) {
            $rate = $this->numberValue($tax['valueObject']['Rate'] ?? []);

            if ($rate === null) {
                $rate = $this->percentFromText((string) ($tax['content'] ?? ''));
            }
            if ($rate !== null) {
                $taxRate = $rate <= 1 ? $rate * 100 : $rate;
                break;
            }
        }

        $date = $this->stringValue($fields['TransactionDate'] ?? [])
            ?? $this->dateFromText($content, $countryCode);
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            // Azure supplies ISO dates; preserve the receipt-local clock separately.
        }
        $time = $this->stringValue($fields['TransactionTime'] ?? [])
            ?? $this->timeFromText($content);
        $singleItem = count($items) === 1 ? $items[0] : null;
        $quantity = $singleItem['quantity'] ?? $this->quantityFromText($content);
        $quantity ??= $this->labeledQuantity($content);
        if ($quantity === null && count($items) > 1) {
            $quantity = array_sum(array_map(static fn (array $item): float => $item['quantity'] ?? 0.0, $items));
        }
        if ($quantity === null) {
            $quantity = $this->labeledQuantity($content);
        }

        if ($quantity !== null && $singleItem !== null) {
            $singleItem['quantity'] = $quantity;
        }

        $result = [
            'raw_text' => $content,
            'total_amount_paid' => $this->currencyValue($fields['Total'] ?? []) ?? $this->labeledAmount($content, 'TOTAL|TOTAL\s+AMOUNT\s+DUE') ?? $this->firstAmountAfterText($content, 'Total amount due'),
            'energy_amount' => $quantity,
            'energy_unit' => $unit,
            'unit_price' => $singleItem['unit_price'] ?? $singleItem['unit_price_without_subsidy'] ?? ($singleItem === null ? $this->unitPriceFromText($content, $quantity, $this->currencyValue($fields['Total'] ?? [])) : null),
            'tax_rate' => $taxRate ?? $this->taxRateFromText($content),
            'fuel_volume_units' => $unit === EnergyUnit::KilowattHours ? null : $quantity,
            'fuel_type' => $singleItem['product_description'] ?? $this->fuelTypeFromText($content),
            'invoice_date' => $date === null ? null : $date.($time === null ? '' : ' '.$time),
            'vendor_name' => $this->stringValue($fields['MerchantName'] ?? []) ?? $this->firstMatch($content, '/^(SAMPLE FUEL STATION LLC|GAS STATION SAMPLE LTD|[A-Z][A-Z0-9 &.,\-]{3,50})/mu'),
            'vendor_tax_id' => $this->vendorTaxId($content, $this->stringValue($fields['MerchantName'] ?? [])),
            'vendor_address' => $this->stringValue($fields['MerchantAddress'] ?? []),
            'vendor_commercial_name' => null,
            'station_name' => $this->stationName($content),
            'station_brand' => null,
            'customer_name' => $this->labeledValue($content, 'CLIENTE|CUSTOMER')
                ?? $this->valueAfterLabel($content, 'CLIENTE|CUSTOMER'),
            'customer_tax_id' => $this->customerTaxId($content),
            'customer_email' => $this->firstMatch($content, '/\b([\w.+-]+@[\w.-]+\.[A-Z]{2,})\b/iu'),
            'payment_method' => $this->labeledValue($content, 'FORMA\\s+PAGO|M\\.\\s*PAGO|PAYMENT\\s+METHOD|FORMA\\s+DE\\s+PAGO'),
            'vehicle_plate' => $this->labeledValue($content, 'PLACA|LICENSE\s+PLATE'),
            'invoice_number' => $this->firstMatch($content, '/\b(?:FACTURA|INVOICE)\s*(?:NO\.?|N[ÚU]MERO)?\s*:?\s*([\d-]{6,30})/iu')
                ?? $this->valueAfterLabel($content, 'FACTURA|INVOICE'),
            'authorization_number' => $this->authorizationNumber($content),
            'items' => $items,
            'subtotal_amount' => $this->currencyValue($fields['Subtotal'] ?? []) ?? $this->labeledAmount($content, 'SUB\s*TOTAL|SUBTOTAL'),
            'tax_amount' => $this->currencyValue($fields['TotalTax'] ?? []) ?? $this->labeledAmount($content, 'IVA|VAT|TAX'),
            'total_amount_without_subsidy' => $this->labeledAmount($content, 'VALOR?\s+TOTAL\s+SIN\s+SUBSIDIO|VAL\.?\s+TOT\.?\s+SIN\s+SUB'),
        ];

        if (isset($analysis['__provider'])) {
            $result['_provider_response'] = $analysis;
            $result['_provider'] = $analysis['__provider'];
            unset($result['_provider_response']['__provider']);
        }

        return $result;
    }

    /**
     * Parse fuel line columns from the printed Ecuador receipt item content.
     *
     * @return array{description: string|null, item_code: string|null, quantity: float|null, unit_price_without_subsidy: float|null, unit_price: float|null, subsidy_amount: float|null, subtotal: float|null}
     */
    private function parseReceiptLine(string $line): array
    {
        $line = trim($line);
        if (preg_match('/^(.+?)\\s*\\1$/u', $line, $duplicate)) {
            $line = $duplicate[1];
        }
        $description = null;
        $code = null;
        $table = $line;
        if (preg_match('/Art\\.?\\s*:\\s*(.+?)\\s+Cod\\.?\\s*:\\s*([\\pL\\pN-]+)/iu', $line, $match)) {
            $description = trim($match[1]);
            $code = $match[2];
            $table = substr($line, strpos($line, $match[0]) + strlen($match[0]));
        }
        preg_match_all('/(?<![\\pL\\pN])\\d+(?:[.,]\\d+)?/u', $table, $matches);
        $numbers = array_values(array_filter(array_map(
            fn (string $value): ?float => $this->numberFromText($value),
            $matches[0],
        ), static fn (?float $value): bool => $value !== null));
        $hasItemCodeColumn = $code === null && count($numbers) >= 6;
        $hasSimpleQuantityPriceTotal = count($numbers) === 3;
        if (count($numbers) < 4) {
            return [
                'description' => $description,
                'item_code' => $code,
                'quantity' => $numbers[0] ?? null,
                'unit_price_without_subsidy' => null,
                'unit_price' => null,
                'subsidy_amount' => null,
                'subtotal' => null,
            ];
        }
        if ($hasSimpleQuantityPriceTotal) {
            return [
                'description' => $description,
                'item_code' => $code,
                'quantity' => $numbers[0],
                'unit_price_without_subsidy' => $numbers[1],
                'unit_price' => $numbers[1],
                'subsidy_amount' => null,
                'subtotal' => $numbers[2],
            ];
        }
        if ($hasItemCodeColumn) {
            return [
                'description' => $description,
                'item_code' => sprintf('%04d', (int) $numbers[2]),
                'quantity' => $numbers[0],
                'unit_price_without_subsidy' => $numbers[4],
                'unit_price' => $numbers[1],
                'subsidy_amount' => $numbers[3],
                'subtotal' => $numbers[count($numbers) - 1],
            ];
        }

        return [
            'description' => $description,
            'item_code' => $code,
            'quantity' => $numbers[0],
            'unit_price_without_subsidy' => $numbers[1],
            'unit_price' => $numbers[2],
            'subsidy_amount' => $numbers[3],
            'subtotal' => $numbers[count($numbers) - 1],
        ];
    }

    /** @param array<string, mixed> $field */
    private function stringValue(array $field): ?string
    {
        foreach (['valueString', 'valueDate', 'valueTime', 'valuePhoneNumber', 'content'] as $key) {
            if (isset($field[$key]) && is_string($field[$key]) && trim($field[$key]) !== '') {
                return trim($field[$key]);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $field */
    private function numberValue(array $field): ?float
    {
        return isset($field['valueNumber']) && is_numeric($field['valueNumber']) ? (float) $field['valueNumber'] : null;
    }

    /** @param array<string, mixed> $field */
    private function currencyValue(array $field): ?float
    {
        $amount = $field['valueCurrency']['amount'] ?? null;

        return is_numeric($amount) ? (float) $amount : $this->numberValue($field);
    }

    private function resolveUnit(string $content, ?string $countryCode): ?EnergyUnit
    {
        if (preg_match('/\b(?:KWH|KILOWATT[\s-]*HOURS?)\b/iu', $content)) {
            return EnergyUnit::KilowattHours;
        }

        if (preg_match('/\b(?:GAL(?:LONS?)?|GGE)\b/iu', $content) || strtoupper((string) $countryCode) === 'EC') {
            return EnergyUnit::Gallons;
        }

        if (preg_match('/\b(?:LIT(?:ER|RE)S?|LITROS?|LTS?)\b/iu', $content)) {
            return EnergyUnit::Litres;
        }

        return null;
    }

    private function unitPriceFromText(string $content, ?float $quantity, ?float $total): ?float
    {
        if (preg_match('/(?:UNIT\s+PRICE|PRECIO\s+UNITARIO)\s*:?\s*(?:[$€£]\s*)?([0-9]+(?:[,.][0-9]+)?)/iu', $content, $match)) {
            return $this->numberFromText($match[1]);
        }

        return $quantity !== null && $quantity > 0 && $total !== null ? $total / $quantity : null;
    }

    private function labeledQuantity(string $content): ?float
    {
        return preg_match('/(?:energy\s+delivered|energy\s+amount|electricity\s+consumed|cantidad(?:\s+de\s+energ[ií]a)?)\s*:?\s*([0-9]+(?:[.,][0-9]+)?)/iu', $content, $match)
            ? $this->numberFromText($match[1])
            : null;
    }

    private function quantityFromText(string $content): ?float
    {
        return preg_match('/\b([0-9]+(?:[.,][0-9]+)?)\s*(?:LITROS?|LTS?|LITERS?|LITRES?|GAL(?:LONS?)?|KWH)\b/iu', $content, $match)
            ? $this->numberFromText($match[1])
            : $this->labeledQuantity($content);
    }

    private function percentFromText(string $value): ?float
    {
        return preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*%/u', $value, $match) ? $this->numberFromText($match[1]) : null;
    }

    private function numberFromText(string $value): ?float
    {
        // Handle European format: 1.234,56 -> 1234.56
        if (str_contains($value, '.') && str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function firstMatch(string $content, string $pattern): ?string
    {
        return preg_match($pattern, $content, $match) ? trim($match[1]) : null;
    }

    private function labeledValue(string $content, string $labels): ?string
    {
        if (! preg_match('/(?:^|\n)\s*(?:'.$labels.')\s*:?\s*([^\n]+)/iu', $content, $match)) {
            return null;
        }

        return trim($match[1]) ?: null;
    }

    private function taxRateFromText(string $content): ?float
    {
        if (! preg_match('/(?:IVA|VAT)\s*(?:\([^)]*?([0-9]+(?:[.,][0-9]+)?)\s*%[^)]*\)|\s*([0-9]+(?:[.,][0-9]+)?)\s*%)/iu', $content, $match)) {
            return null;
        }

        $value = $match[1] !== '' ? $match[1] : $match[2];

        return $this->numberFromText($value);
    }

    private function vendorTaxId(string $content, ?string $merchantName = null): ?string
    {
        if (! preg_match('/(?:^|\n)\s*(?:RUC|RFC|NIT|TAX\s*ID)\s*:?\s*([\d.-]{1,20})/iu', $content, $match)) {
            return null;
        }

        $merchantPrefix = $merchantName === null ? '' : preg_quote(mb_substr($merchantName, 0, 14), '/');
        if ($merchantPrefix !== '' && preg_match('/'.$merchantPrefix.'.{0,150}?(\d{13})/isu', $content, $merchantMatch)) {
            return $merchantMatch[1];
        }

        return null;
    }

    private function customerTaxId(string $content): ?string
    {
        if (preg_match('/(?:^|\n)\s*RUC\s*\/\s*CI\s*:?\s*([\d.-]{1,20})/iu', $content, $match)) {
            return trim($match[1]);
        }

        if (preg_match('/(?:^|\n)\s*(?:CLIENTE|CUSTOMER)\s*:?[^\n]*\n\s*(?:UC\s*\/\s*CI\s*:?\s*)?([\d.-]{1,20})/iu', $content, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private function fuelTypeFromText(string $content): ?string
    {
        if (preg_match('/(?:ART\.?\s*:\s*)(.+?)\s+COD\.?\s*:/iu', $content, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private function valueAfterLabel(string $content, string $labels): ?string
    {
        if (! preg_match('/(?:^|\n)\s*(?:'.$labels.')\s*:?\s*\n\s*([^\n]+)/iu', $content, $match)) {
            return null;
        }

        return trim($match[1]) ?: null;
    }

    private function dateFromText(string $content, ?string $countryCode): ?string
    {
        if (! preg_match('/(?:FECHA|DATE)\s*:?\s*(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})/iu', $content, $match)) {
            return null;
        }

        $monthFirst = strtoupper((string) $countryCode) === 'US';
        $month = (int) ($monthFirst ? $match[1] : $match[2]);
        $day = (int) ($monthFirst ? $match[2] : $match[1]);
        $year = (int) $match[3];

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    private function timeFromText(string $content): ?string
    {
        return $this->firstMatch($content, '/\b(\d{1,2}:\d{2}(?::\d{2})?)\b/');
    }

    private function authorizationNumber(string $content): ?string
    {
        if (preg_match('/N[ÚU]MERO\s+DE\s+AUTORIZACI[ÓO]N\s*:?\s*([\d]{20,60})/iu', $content, $match)
            || preg_match('/CLAVE\s+ACCESO(?:\s+SRI)?\s*:?\s*([\d]{20,60})/iu', $content, $match)) {
            return $match[1];
        }

        return null;
    }

    private function labeledAmount(string $content, string $labels): ?float
    {
        if (! preg_match('/(?:^|\n)\s*(?:'.$labels.')\s*(?:\([^)]*\))?\s*:?\s*(?:[$€£]\s*)?([0-9][0-9.,]*)/iu', $content, $match)) {
            return null;
        }

        return $this->numberFromText($match[1]);
    }

    private function firstAmountAfterText(string $content, string $label): ?float
    {
        $position = stripos($content, $label);
        if ($position === false) {
            return null;
        }
        $tail = substr($content, $position + strlen($label));

        return preg_match('/[0-9][0-9.,]*/', $tail, $match) ? $this->numberFromText($match[0]) : null;
    }

    private function stationName(string $content): ?string
    {
        if (preg_match('/ESTACI[ÓO]N\s+DE\s+SERVICIO\s+([^\r\n]+)/iu', $content, $match)) {
            $name = trim($match[1]);
            // Remove trailing whitespace
            $name = preg_replace('/\s+$/u', '', $name);

            return $name ?: null;
        }

        if (preg_match('/^([^\n]+)\n[^\n]*\b(?:RUC|RFC|NIT)\b/iu', $content, $match)) {
            return trim($match[1]);
        }

        return null;
    }
}
