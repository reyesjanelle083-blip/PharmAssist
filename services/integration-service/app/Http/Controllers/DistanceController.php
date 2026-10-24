<?php

namespace App\Http\Controllers;

use App\Http\Requests\DistanceRequest;
use App\Services\Distance\DistanceService;
use Illuminate\Http\JsonResponse;
use PharmAssist\Common\Http\Envelope;

/** Service-to-service only (the gateway's availability aggregator). Protected by InternalKey; no public route. */
class DistanceController extends Controller
{
    public function __construct(private readonly DistanceService $distance) {}

    public function calculate(DistanceRequest $request): JsonResponse
    {
        $origin = $request->validated('origin');
        $destinations = array_map(fn (array $d) => [
            'branch_id' => (int) $d['branch_id'],
            'lat' => (float) $d['lat'],
            'lng' => (float) $d['lng'],
        ], $request->validated('destinations'));

        $result = $this->distance->distances(
            ['lat' => (float) $origin['lat'], 'lng' => (float) $origin['lng']],
            $destinations,
            $request->attributes->get('request_id'),
        );

        // The response never echoes coordinates back.
        return Envelope::success(
            ['items' => $result['items']],
            200,
            $result['fallback'] ? ['DISTANCE_FALLBACK'] : [],
        );
    }
}
