<?php

namespace App\Services;

use App\Models\Country;
use App\Models\Vendor;
use Illuminate\Support\Str;

class VendorResolver
{
    /**
     * Match suppliers within a country by their normalized tax identifier.
     *
     * @param  array{tax_id: string|null, legal_name: string|null, commercial_name: string|null, address: string|null}  $attributes
     */
    public function resolve(?Country $country, array $attributes, bool $overwrite = false): ?Vendor
    {
        $taxId = trim((string) $attributes['tax_id']);
        $normalizedTaxId = Str::upper((string) preg_replace('/[^A-Z0-9]/i', '', $taxId));

        if ($country === null || $normalizedTaxId === '') {
            return null;
        }

        $vendor = Vendor::query()->firstOrNew([
            'country_id' => $country->id,
            'tax_id_normalized' => $normalizedTaxId,
        ]);
        $isNew = ! $vendor->exists;
        $vendor->tax_id ??= $taxId;

        foreach ([
            'legal_name' => $attributes['legal_name'],
            'commercial_name' => $attributes['commercial_name'],
            'address' => $attributes['address'],
        ] as $field => $value) {
            if ($value !== null && ($isNew || $overwrite || $vendor->{$field} === null)) {
                $vendor->{$field} = $value;
            }
        }

        if ($vendor->isDirty()) {
            $vendor->save();
        }

        return $vendor;
    }
}
