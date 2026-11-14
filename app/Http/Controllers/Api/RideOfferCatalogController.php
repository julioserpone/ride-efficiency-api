<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NormalizeRideOfferLabelsRequest;
use App\Services\Offers\ServiceTypeCatalog;
use Illuminate\Http\JsonResponse;

class RideOfferCatalogController extends Controller
{
    /**
     * Publish the canonical platform, service type and availability codes so the
     * web and mobile clients do not duplicate the taxonomy.
     */
    public function index(): JsonResponse
    {
        /** @var array<string, mixed> $availabilityTypes */
        $availabilityTypes = config('ride_offer_platforms.availability_types', []);
        $labels = [];

        foreach ($availabilityTypes as $code => $definition) {
            $labels[$code] = (string) (\is_array($definition) ? ($definition['label'] ?? '') : '');
        }

        return response()->json([
            'catalog' => config('ride_offer_platforms.platforms', []),
            'availability_types' => $labels,
            'version' => (string) config('ride_offer_platforms.version', '1'),
        ]);
    }

    /**
     * Resolve free text (for example OCR output) to canonical codes.
     */
    public function normalize(
        NormalizeRideOfferLabelsRequest $request,
        ServiceTypeCatalog $catalog,
    ): JsonResponse {
        $platform = $catalog->normalizePlatform($request->validated('platform'));

        return response()->json([
            'platform' => $platform,
            'service_type' => $catalog->normalizeServiceType(
                $request->validated('service_type'),
                $platform,
            ),
            'availability_type' => $catalog->normalizeAvailabilityType(
                $request->validated('availability_type'),
            ),
        ]);
    }
}
