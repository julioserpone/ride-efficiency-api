<?php

namespace App\Jobs;

use App\Models\DailyOfferMetric;
use App\Models\RideOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class AggregateDailyOfferMetricsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public ?string $date = null) {}

    public function handle(): void
    {
        $date = $this->date ?? Carbon::yesterday()->toDateString();

        $userIds = RideOffer::query()
            ->whereDate('received_at', $date)
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $base = RideOffer::query()
                ->where('user_id', $userId)
                ->whereDate('received_at', $date);

            DailyOfferMetric::updateOrCreate(
                ['user_id' => $userId, 'metric_date' => $date],
                [
                    'offered' => (clone $base)->count(),
                    'evaluated' => (clone $base)->whereNotNull('client_verdict')->count(),
                    'accepted' => (clone $base)->where('status', 'accepted')->count(),
                    'rejected' => (clone $base)->where('status', 'rejected')->count(),
                    'expired' => (clone $base)->where('status', 'expired')->count(),
                    'completed' => (clone $base)->where('status', 'completed')->count(),
                    'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
                    'avg_offered_fare' => (clone $base)->avg('offered_fare'),
                    'avg_net_profit' => (clone $base)->avg('client_net_profit_estimate'),
                ],
            );
        }
    }
}
