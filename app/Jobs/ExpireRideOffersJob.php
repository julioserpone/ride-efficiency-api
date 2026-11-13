<?php

namespace App\Jobs;

use App\Models\RideOffer;
use App\Models\RideOfferEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ExpireRideOffersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $now = Carbon::now();

        $offers = RideOffer::query()
            ->whereIn('status', ['received', 'evaluated'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->get();

        foreach ($offers as $offer) {
            if (! $offer->canTransitionTo('expired')) {
                continue;
            }

            $offer->update([
                'status' => 'expired',
                'decided_at' => $now,
            ]);

            $alreadyExists = RideOfferEvent::query()
                ->where('ride_offer_id', $offer->id)
                ->where('event_type', 'expired')
                ->exists();

            if (! $alreadyExists) {
                $offer->events()->create([
                    'event_type' => 'expired',
                    'occurred_at' => $now,
                    'metadata' => null,
                ]);
            }
        }
    }
}
