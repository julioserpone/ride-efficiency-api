<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncRideOffersRequest;
use App\Models\RideOffer;
use App\Models\RideOfferEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RideOfferController extends Controller
{
    public function sync(SyncRideOffersRequest $request): JsonResponse
    {
        $user = $request->user();
        $synced = [];
        $errors = [];

        foreach ($request->validated('offers') as $offerData) {
            $clientUuid = $offerData['client_uuid'];

            try {
                $offer = DB::transaction(function () use ($user, $offerData): RideOffer {
                    $vehicleId = isset($offerData['vehicle_id']) ? (int) $offerData['vehicle_id'] : null;

                    if ($vehicleId !== null && ! $user->vehicles()->where('id', $vehicleId)->exists()) {
                        throw new \RuntimeException('Vehicle does not belong to the authenticated user.');
                    }

                    $attributes = [
                        'vehicle_id' => $vehicleId,
                        'daily_shift_id' => isset($offerData['daily_shift_id']) ? (int) $offerData['daily_shift_id'] : null,
                        'platform' => $offerData['platform'],
                        'external_offer_id' => $offerData['external_offer_id'] ?? null,
                        'currency_code' => $offerData['currency_code'],
                        'offered_fare' => (string) $offerData['offered_fare'],
                        'surge_amount' => isset($offerData['surge_amount']) ? (string) $offerData['surge_amount'] : '0',
                        'tip_amount' => isset($offerData['tip_amount']) ? (string) $offerData['tip_amount'] : '0',
                        'toll_amount' => isset($offerData['toll_amount']) ? (string) $offerData['toll_amount'] : '0',
                        'estimated_distance_km' => (string) $offerData['estimated_distance_km'],
                        'estimated_duration_minutes' => $offerData['estimated_duration_minutes'] ?? null,
                        'pickup_distance_km' => isset($offerData['pickup_distance_km']) ? (string) $offerData['pickup_distance_km'] : null,
                        'actual_distance_km' => isset($offerData['actual_distance_km']) ? (string) $offerData['actual_distance_km'] : null,
                        'actual_fare' => isset($offerData['actual_fare']) ? (string) $offerData['actual_fare'] : null,
                        'actual_duration_minutes' => $offerData['actual_duration_minutes'] ?? null,
                        'source' => $offerData['source'] ?? 'manual',
                        'ocr_confidence' => isset($offerData['ocr_confidence']) ? (string) $offerData['ocr_confidence'] : null,
                        'raw_payload' => $offerData['raw_payload'] ?? null,
                        'status' => $offerData['status'] ?? 'received',
                        'expires_at' => $offerData['expires_at'] ?? null,
                        'received_at' => $offerData['received_at'],
                        'decided_at' => $offerData['decided_at'] ?? null,
                        'completed_at' => $offerData['completed_at'] ?? null,
                        'cancelled_at' => $offerData['cancelled_at'] ?? null,
                        'cancellation_reason' => $offerData['cancellation_reason'] ?? null,
                        'client_verdict' => $offerData['client_verdict'] ?? null,
                        'client_net_profit_estimate' => isset($offerData['client_net_profit_estimate']) ? (string) $offerData['client_net_profit_estimate'] : null,
                        'client_break_even_fare' => isset($offerData['client_break_even_fare']) ? (string) $offerData['client_break_even_fare'] : null,
                        'calculation_version' => $offerData['calculation_version'] ?? null,
                        'config_snapshot' => $offerData['config_snapshot'] ?? null,
                    ];

                    $existing = RideOffer::query()
                        ->where('user_id', $user->id)
                        ->where('client_uuid', $offerData['client_uuid'])
                        ->first();

                    if ($existing !== null) {
                        $existing->update($attributes);
                        $offer = $existing;
                    } else {
                        $offer = RideOffer::create(array_merge($attributes, [
                            'client_uuid' => $offerData['client_uuid'],
                            'user_id' => $user->id,
                        ]));
                    }

                    $existingEvents = $offer->events()->get(['event_type', 'occurred_at']);

                    foreach ($offerData['events'] ?? [] as $eventData) {
                        $occurredAt = Carbon::parse($eventData['occurred_at']);

                        $duplicate = $existingEvents->first(function (RideOfferEvent $event) use ($eventData, $occurredAt): bool {
                            if ($event->event_type !== $eventData['event_type']) {
                                return false;
                            }

                            $storedRaw = $event->getRawOriginal('occurred_at');

                            if ($storedRaw === null) {
                                return false;
                            }

                            $storedAt = Carbon::parse((string) $storedRaw);

                            return $storedAt->equalTo($occurredAt);
                        });

                        if ($duplicate === null) {
                            $created = $offer->events()->create([
                                'event_type' => $eventData['event_type'],
                                'occurred_at' => $occurredAt,
                                'metadata' => $eventData['metadata'] ?? null,
                            ]);
                            $existingEvents->push($created);
                        }
                    }

                    return $offer;
                });

                $synced[] = [
                    'client_uuid' => $clientUuid,
                    'id' => $offer->id,
                    'status' => $offer->status,
                ];
            } catch (\Throwable $exception) {
                $errors[] = [
                    'client_uuid' => $clientUuid,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'synced' => $synced,
            'errors' => $errors,
        ]);
    }
}
