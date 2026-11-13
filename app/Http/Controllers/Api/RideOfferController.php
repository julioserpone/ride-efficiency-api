<?php

namespace App\Http\Controllers\Api;

use App\Actions\Offers\RecomputeRideOfferProfitabilityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SyncRideOffersRequest;
use App\Models\RideOffer;
use App\Models\RideOfferEvent;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RideOfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = RideOffer::query()
            ->where('user_id', $user->id)
            ->orderByDesc('received_at');

        if ($request->filled('platform')) {
            $query->where('platform', $request->string('platform')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('from')) {
            $query->where('received_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('received_at', '<=', $request->date('to'));
        }

        $offers = $query->paginate($request->integer('per_page', 15));

        return response()->json(['offers' => $offers]);
    }

    public function show(Request $request, RideOffer $rideOffer): JsonResponse
    {
        if ($rideOffer->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json(['offer' => $rideOffer->load('vehicle', 'events')]);
    }

    public function update(Request $request, RideOffer $rideOffer): JsonResponse
    {
        if ($rideOffer->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', RideOffer::STATUSES)],
            'cancellation_reason' => ['nullable', 'string', 'max:128'],
        ]);

        if (! $rideOffer->canTransitionTo($validated['status'])) {
            return response()->json([
                'message' => "Invalid status transition: {$rideOffer->status} -> {$validated['status']}.",
            ], 422);
        }

        $attributes = ['status' => $validated['status']];

        if (isset($validated['cancellation_reason'])) {
            $attributes['cancellation_reason'] = $validated['cancellation_reason'];
        }

        if ($validated['status'] === 'accepted') {
            $attributes['decided_at'] = now();
        }

        if (in_array($validated['status'], ['completed', 'cancelled'], true)) {
            $attributes['completed_at'] = $validated['status'] === 'completed' ? now() : $rideOffer->completed_at;
            $attributes['cancelled_at'] = $validated['status'] === 'cancelled' ? now() : $rideOffer->cancelled_at;
        }

        if ($validated['status'] === 'rejected' || $validated['status'] === 'expired') {
            $attributes['decided_at'] = now();
        }

        $rideOffer->update($attributes);

        return response()->json(['offer' => $rideOffer->fresh()]);
    }

    public function evaluate(Request $request, RecomputeRideOfferProfitabilityAction $recompute): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'platform' => ['required', 'string', 'max:32'],
            'currency_code' => ['required', 'string', 'size:3'],
            'offered_fare' => ['required', 'numeric'],
            'surge_amount' => ['nullable', 'numeric'],
            'tip_amount' => ['nullable', 'numeric'],
            'toll_amount' => ['nullable', 'numeric'],
            'estimated_distance_km' => ['required', 'numeric', 'gt:0'],
            'pickup_distance_km' => ['nullable', 'numeric'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'vehicle_id' => ['nullable', 'integer'],
            'client_verdict' => ['nullable', 'string', 'max:16'],
            'client_net_profit_estimate' => ['nullable', 'numeric'],
            'client_break_even_fare' => ['nullable', 'numeric'],
            'calculation_version' => ['nullable', 'string', 'max:16'],
            'config_snapshot' => ['nullable', 'array'],
        ]);

        $offer = new RideOffer;
        $offer->forceFill([
            'user_id' => $user->id,
            'platform' => $validated['platform'],
            'currency_code' => $validated['currency_code'],
            'offered_fare' => (string) $validated['offered_fare'],
            'surge_amount' => isset($validated['surge_amount']) ? (string) $validated['surge_amount'] : '0',
            'tip_amount' => isset($validated['tip_amount']) ? (string) $validated['tip_amount'] : '0',
            'toll_amount' => isset($validated['toll_amount']) ? (string) $validated['toll_amount'] : '0',
            'estimated_distance_km' => (string) $validated['estimated_distance_km'],
            'pickup_distance_km' => isset($validated['pickup_distance_km']) ? (string) $validated['pickup_distance_km'] : null,
            'estimated_duration_minutes' => $validated['estimated_duration_minutes'] ?? null,
            'client_verdict' => $validated['client_verdict'] ?? null,
            'client_net_profit_estimate' => isset($validated['client_net_profit_estimate']) ? (string) $validated['client_net_profit_estimate'] : null,
            'client_break_even_fare' => isset($validated['client_break_even_fare']) ? (string) $validated['client_break_even_fare'] : null,
            'calculation_version' => $validated['calculation_version'] ?? null,
            'config_snapshot' => $validated['config_snapshot'] ?? null,
            'received_at' => now(),
            'status' => 'received',
        ]);
        $offer->setRelation('user', $user);

        $vehicle = null;
        if (isset($validated['vehicle_id'])) {
            if (! $user->vehicles()->where('id', (int) $validated['vehicle_id'])->exists()) {
                return response()->json(['message' => 'Vehicle does not belong to the authenticated user.'], 422);
            }
            $vehicle = Vehicle::query()->with('country')->find((int) $validated['vehicle_id']);
        }

        $server = $recompute->execute($offer, $vehicle);

        if ($server === null) {
            return response()->json(['message' => 'Unable to compute server verdict (missing vehicle or energy price).'], 422);
        }

        return response()->json(['result' => $server]);
    }

    public function dailySummary(Request $request): JsonResponse
    {
        $user = $request->user();

        $base = RideOffer::query()->where('user_id', $user->id);

        if ($request->filled('from')) {
            $base->where('received_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $base->where('received_at', '<=', $request->date('to'));
        }

        $offered = (clone $base)->count();
        $evaluated = (clone $base)->whereNotNull('client_verdict')->count();
        $accepted = (clone $base)->where('status', 'accepted')->count();
        $rejected = (clone $base)->where('status', 'rejected')->count();
        $expired = (clone $base)->where('status', 'expired')->count();
        $completed = (clone $base)->where('status', 'completed')->count();
        $cancelled = (clone $base)->where('status', 'cancelled')->count();

        $acceptanceRate = $offered > 0 ? round($accepted / $offered, 4) : 0;
        $completionRate = $accepted > 0 ? round($completed / $accepted, 4) : 0;

        $avgFare = (clone $base)->avg('offered_fare');
        $avgProfit = (clone $base)->avg('client_net_profit_estimate');

        return response()->json([
            'summary' => [
                'offered' => $offered,
                'evaluated' => $evaluated,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'expired' => $expired,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'acceptance_rate' => $acceptanceRate,
                'completion_rate' => $completionRate,
                'avg_offered_fare' => $avgFare === null ? null : number_format((float) $avgFare, 2, '.', ''),
                'avg_net_profit' => $avgProfit === null ? null : number_format((float) $avgProfit, 2, '.', ''),
            ],
        ]);
    }

    public function sync(SyncRideOffersRequest $request, RecomputeRideOfferProfitabilityAction $recompute): JsonResponse
    {
        $user = $request->user();
        $synced = [];
        $errors = [];

        foreach ($request->validated('offers') as $offerData) {
            $clientUuid = $offerData['client_uuid'];

            try {
                $offer = DB::transaction(function () use ($user, $offerData, $recompute): RideOffer {
                    $vehicleId = isset($offerData['vehicle_id']) ? (int) $offerData['vehicle_id'] : null;

                    if ($vehicleId !== null && ! $user->vehicles()->where('id', $vehicleId)->exists()) {
                        throw new \RuntimeException('Vehicle does not belong to the authenticated user.');
                    }

                    $attributes = [
                        'vehicle_id' => $vehicleId,
                        'daily_shift_id' => isset($offerData['daily_shift_id']) ? (int) $offerData['daily_shift_id'] : null,
                        'platform' => $offerData['platform'],
                        'service_type' => $offerData['service_type'] ?? null,
                        'availability_type' => $offerData['availability_type'] ?? null,
                        'rider_rating' => isset($offerData['rider_rating']) ? (string) $offerData['rider_rating'] : null,
                        'rider_trip_count' => $offerData['rider_trip_count'] ?? null,
                        'rider_verified' => array_key_exists('rider_verified', $offerData) ? (bool) $offerData['rider_verified'] : null,
                        'external_offer_id' => $offerData['external_offer_id'] ?? null,
                        'currency_code' => $offerData['currency_code'],
                        'offered_fare' => (string) $offerData['offered_fare'],
                        'surge_amount' => isset($offerData['surge_amount']) ? (string) $offerData['surge_amount'] : '0',
                        'tip_amount' => isset($offerData['tip_amount']) ? (string) $offerData['tip_amount'] : '0',
                        'toll_amount' => isset($offerData['toll_amount']) ? (string) $offerData['toll_amount'] : '0',
                        'payment_method' => $offerData['payment_method'] ?? null,
                        'estimated_distance_km' => (string) $offerData['estimated_distance_km'],
                        'destination_address' => $offerData['destination_address'] ?? null,
                        'estimated_duration_minutes' => $offerData['estimated_duration_minutes'] ?? null,
                        'pickup_distance_km' => isset($offerData['pickup_distance_km']) ? (string) $offerData['pickup_distance_km'] : null,
                        'pickup_address' => $offerData['pickup_address'] ?? null,
                        'estimated_pickup_duration_minutes' => $offerData['estimated_pickup_duration_minutes'] ?? null,
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
                        $newStatus = $attributes['status'];

                        if (! $existing->canTransitionTo($newStatus)) {
                            throw new \RuntimeException("Invalid status transition: {$existing->status} -> {$newStatus}.");
                        }

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

                    $vehicle = $vehicleId !== null
                        ? Vehicle::query()->with('country')->find($vehicleId)
                        : null;

                    $server = $recompute->execute($offer, $vehicle);

                    if ($server !== null) {
                        $offer->update($server);
                        $offer->refresh();
                    }

                    return $offer;
                });

                $synced[] = [
                    'client_uuid' => $clientUuid,
                    'id' => $offer->id,
                    'status' => $offer->status,
                    'server_verdict' => $offer->server_verdict,
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
