<?php

namespace App\Models;

use Database\Factories\DailyEarningFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $daily_shift_id
 * @property string $provider_name
 * @property float $gross_amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'daily_shift_id',
    'provider_name',
    'gross_amount',
])]
class DailyEarning extends Model
{
    /** @use HasFactory<DailyEarningFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
        ];
    }

    /**
     * Get the daily shift associated with these earnings.
     *
     * @return BelongsTo<DailyShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(DailyShift::class, 'daily_shift_id');
    }
}
