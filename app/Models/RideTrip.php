<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $vehicle_id
 * @property int|null $daily_shift_id
 * @property string $platform_name
 * @property string $currency_code
 * @property string $gross_fare
 * @property string $distance_km
 * @property string $deadhead_distance_km
 * @property int|null $duration_minutes
 * @property string $commission_rate
 * @property string $energy_price_per_unit
 * @property string $energy_cost
 * @property string $commission_cost
 * @property string $depreciation_cost
 * @property string $other_costs
 * @property string $net_profit
 * @property string $break_even_fare
 * @property string $verdict
 */
#[Fillable([
    'user_id',
    'vehicle_id',
    'daily_shift_id',
    'platform_name',
    'currency_code',
    'gross_fare',
    'distance_km',
    'deadhead_distance_km',
    'duration_minutes',
    'commission_rate',
    'energy_price_per_unit',
    'energy_cost',
    'commission_cost',
    'depreciation_cost',
    'other_costs',
    'net_profit',
    'break_even_fare',
    'verdict',
])]
class RideTrip extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_fare' => 'decimal:2',
            'distance_km' => 'decimal:3',
            'deadhead_distance_km' => 'decimal:3',
            'duration_minutes' => 'integer',
            'commission_rate' => 'decimal:2',
            'energy_price_per_unit' => 'decimal:4',
            'energy_cost' => 'decimal:2',
            'commission_cost' => 'decimal:2',
            'depreciation_cost' => 'decimal:2',
            'other_costs' => 'decimal:2',
            'net_profit' => 'decimal:2',
            'break_even_fare' => 'decimal:2',
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
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<DailyShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(DailyShift::class, 'daily_shift_id');
    }
}
