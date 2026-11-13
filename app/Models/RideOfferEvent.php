<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ride_offer_id', 'event_type', 'occurred_at', 'metadata'])]
class RideOfferEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RideOffer, $this>
     */
    public function rideOffer(): BelongsTo
    {
        return $this->belongsTo(RideOffer::class);
    }
}
