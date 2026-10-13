<?php

namespace App\Services;

use App\Models\Country;
use App\Models\EnergyProduct;
use Illuminate\Support\Str;

class EnergyProductResolver
{
    /**
     * Match OCR text only against products configured for the invoice country.
     */
    public function resolve(?Country $country, string $rawText): ?EnergyProduct
    {
        if ($country === null || trim($rawText) === '') {
            return null;
        }

        $normalizedText = Str::ascii(mb_strtolower($rawText));
        $products = EnergyProduct::query()
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->get();

        $matches = [];

        foreach ($products as $product) {
            foreach ([$product->name, ...$product->aliases] as $alias) {
                $normalizedAlias = trim(Str::ascii(mb_strtolower($alias)));

                if ($normalizedAlias === '') {
                    continue;
                }

                $pattern = '/(?<![\pL\pN])'.preg_quote($normalizedAlias, '/').'(?![\pL\pN])/u';

                if (preg_match($pattern, $normalizedText)) {
                    $matches[] = ['product' => $product, 'length' => strlen($normalizedAlias)];
                }
            }
        }

        usort($matches, static fn (array $left, array $right): int => $right['length'] <=> $left['length']);

        return $matches[0]['product'] ?? null;
    }
}
