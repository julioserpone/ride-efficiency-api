<?php

namespace App\Models;

use Database\Factories\VehicleFactory;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $country_id
 * @property string $name
 * @property EnergyType $energy_type
 * @property EnergyUnit $energy_unit
 * @property string $energy_economy
 * @property string|null $commission_rate
 * @property bool $is_active
 */
#[Fillable([
    'user_id',
    'country_id',
    'name',
    'energy_type',
    'energy_unit',
    'energy_economy',
    'commission_rate',
    'is_active',
])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'energy_type' => EnergyType::class,
            'energy_unit' => EnergyUnit::class,
            'energy_economy' => 'decimal:4',
            'commission_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return HasMany<RideTrip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(RideTrip::class);
    }
}
