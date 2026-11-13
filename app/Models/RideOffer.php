<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $config_snapshot
 * @property array<string, mixed>|null $raw_payload
 */
#[Fillable([
    'client_uuid', 'user_id', 'vehicle_id', 'daily_shift_id', 'platform', 'external_offer_id',
    'currency_code', 'offered_fare', 'surge_amount', 'tip_amount', 'toll_amount',
    'estimated_distance_km', 'estimated_duration_minutes', 'pickup_distance_km',
    'actual_distance_km', 'actual_fare', 'actual_duration_minutes', 'source', 'ocr_confidence',
    'raw_payload', 'status', 'expires_at', 'received_at', 'decided_at', 'completed_at',
    'cancelled_at', 'cancellation_reason', 'client_verdict', 'client_net_profit_estimate',
    'client_break_even_fare', 'server_verdict', 'server_net_profit_estimate',
    'server_break_even_fare', 'calculation_version', 'config_snapshot', 'ride_trip_id',
])]
class RideOffer extends Model
{
    public const STATUSES = ['received', 'evaluated', 'accepted', 'rejected', 'expired', 'completed', 'cancelled'];

    public const TRANSITIONS = [
        'received' => ['evaluated', 'accepted', 'rejected', 'expired'],
        'evaluated' => ['accepted', 'rejected', 'expired'],
        'accepted' => ['completed', 'cancelled'],
        'rejected' => [],
        'expired' => [],
        'completed' => [],
        'cancelled' => [],
    ];

    public function canTransitionTo(string $status): bool
    {
        if ($status === $this->status) {
            return true;
        }

        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offered_fare' => 'decimal:2',
            'surge_amount' => 'decimal:2',
            'tip_amount' => 'decimal:2',
            'toll_amount' => 'decimal:2',
            'estimated_distance_km' => 'decimal:3',
            'pickup_distance_km' => 'decimal:3',
            'actual_distance_km' => 'decimal:3',
            'actual_fare' => 'decimal:2',
            'ocr_confidence' => 'decimal:4',
            'client_net_profit_estimate' => 'decimal:2',
            'client_break_even_fare' => 'decimal:2',
            'server_net_profit_estimate' => 'decimal:2',
            'server_break_even_fare' => 'decimal:2',
            'raw_payload' => 'array',
            'config_snapshot' => 'array',
            'expires_at' => 'datetime',
            'received_at' => 'datetime',
            'decided_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
    public function dailyShift(): BelongsTo
    {
        return $this->belongsTo(DailyShift::class);
    }

    /**
     * @return BelongsTo<RideTrip, $this>
     */
    public function rideTrip(): BelongsTo
    {
        return $this->belongsTo(RideTrip::class);
    }

    /**
     * @return HasMany<RideOfferEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RideOfferEvent::class);
    }
}
