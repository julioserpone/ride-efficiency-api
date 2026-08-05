<?php

namespace App\Models;

use Database\Factories\DailyShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $shift_date
 * @property float $total_km_gps
 * @property int $total_minutes_connected
 * @property int $total_trips_completed
 * @property int $total_offers_scanned
 * @property float $applied_fuel_cost
 * @property float $estimated_depreciation
 * @property float $real_net_profit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'shift_date',
    'total_km_gps',
    'total_minutes_connected',
    'total_trips_completed',
    'total_offers_scanned',
    'applied_fuel_cost',
    'estimated_depreciation',
    'real_net_profit',
])]
class DailyShift extends Model
{
    /** @use HasFactory<DailyShiftFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shift_date' => 'date:Y-m-d',
            'total_km_gps' => 'decimal:2',
            'total_minutes_connected' => 'integer',
            'total_trips_completed' => 'integer',
            'total_offers_scanned' => 'integer',
            'applied_fuel_cost' => 'decimal:2',
            'estimated_depreciation' => 'decimal:2',
            'real_net_profit' => 'decimal:2',
        ];
    }

    /**
     * Get the user that owns the shift.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the earnings registered for this shift across platforms.
     *
     * @return HasMany<DailyEarning, $this>
     */
    public function earnings(): HasMany
    {
        return $this->hasMany(DailyEarning::class);
    }
}
