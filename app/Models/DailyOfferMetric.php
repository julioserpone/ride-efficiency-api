<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'metric_date', 'offered', 'evaluated', 'accepted', 'rejected',
    'expired', 'completed', 'cancelled', 'avg_offered_fare', 'avg_net_profit',
])]
class DailyOfferMetric extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'avg_offered_fare' => 'decimal:2',
            'avg_net_profit' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
