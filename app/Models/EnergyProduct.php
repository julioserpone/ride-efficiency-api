<?php

namespace App\Models;

use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $country_id
 * @property string $code
 * @property string $name
 * @property EnergyType $energy_type
 * @property EnergyUnit $energy_unit
 * @property list<string> $aliases
 * @property bool $is_active
 */
#[Fillable(['country_id', 'code', 'name', 'energy_type', 'energy_unit', 'aliases', 'is_active'])]
class EnergyProduct extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'energy_type' => EnergyType::class,
            'energy_unit' => EnergyUnit::class,
            'aliases' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
