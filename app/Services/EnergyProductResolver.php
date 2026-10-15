<?php

namespace App\Services;

use App\Models\Country;
use App\Models\EnergyProduct;
use Illuminate\Database\Eloquent\Collection;
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

        $products = EnergyProduct::query()
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->get();

        return $this->match($products, $rawText);
    }

    /**
     * Resolve several item descriptions against one country catalog query.
     *
     * @param  list<string>  $descriptions
     * @return list<EnergyProduct|null>
     */
    public function resolveMany(?Country $country, array $descriptions): array
    {
        if ($country === null) {
            return array_fill(0, count($descriptions), null);
        }

        $products = EnergyProduct::query()
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->get();

        return array_map(
            fn (string $description): ?EnergyProduct => $this->match($products, $description),
            $descriptions,
        );
    }

    /**
     * @param  Collection<int, EnergyProduct>  $products
     */
    private function match(Collection $products, string $rawText): ?EnergyProduct
    {
        if (trim($rawText) === '') {
            return null;
        }

        $normalizedText = Str::ascii(mb_strtolower($rawText));
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
