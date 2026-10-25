<?php

namespace App\Http\Controllers;

use App\Services\HaversineEstimator;
use App\Services\ServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PharmAssist\Common\Http\Envelope;

/**
 * Aggregator for GET /availability (plan F2, row I-3): branches that have free stock for a medicine, ranked by travel time.
 *
 *   1. inventory-service says who has stock (this is the only dependency that can make the request fail);
 *   2. with a location, integration-service says how far each branch is (OSRM, or its own haversine fallback);
 *   3. if integration-service cannot answer, the gateway estimates the distances itself and the answer is still 200.
 *
 * Privacy: the location is used for this one request only. It is sent to integration-service in a POST body and nowhere
 * else: not to inventory-service, not in any log line, cache key or response (the origin is never echoed back).
 */
class AvailabilityController extends Controller
{
    private const DISTANCE_SOURCES = ['osrm', 'haversine', 'stub'];

    public function __construct(private readonly ServiceClient $services) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'medicine_id' => ['nullable', 'integer', 'min:1', 'max:2147483647', 'required_without:q'],
            'q' => ['nullable', 'string', 'max:100', 'required_without:medicine_id'],
            // a plain decimal number inside the Philippines (latitude 4.5 to 21.5, longitude 116 to 127)
            'lat' => ['nullable', 'regex:/^-?\d{1,3}(\.\d{1,10})?$/', 'numeric', 'between:4.5,21.5', 'required_with:lng'],
            'lng' => ['nullable', 'regex:/^-?\d{1,3}(\.\d{1,10})?$/', 'numeric', 'between:116,127', 'required_with:lat'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        if ($validator->fails()) {
            return Envelope::error('VALIDATION_FAILED', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }
        $input = $validator->validated();

        // 1. Stock. The coordinates are not part of this call.
        $query = array_filter([
            'medicine_id' => $input['medicine_id'] ?? null,
            'q' => isset($input['q']) ? trim($input['q']) : null,
            'limit' => $input['limit'] ?? 10,
        ], fn ($v) => $v !== null && $v !== '');
        $inventory = $this->services->get('inventory', '/internal/v1/inventory/availability', $query);
        if ($inventory->getStatusCode() !== 200) {
            return $inventory; // 404, 422, 503, 504, 502 ... exactly as ServiceClient produced or relayed them
        }

        $rows = $inventory->getData(true)['data']['items'] ?? null;
        if (! is_array($rows)) {
            return Envelope::error('BAD_UPSTREAM_RESPONSE', 'The service returned an unexpected response.', 502);
        }

        $hasOrigin = isset($input['lat'], $input['lng']);
        $warnings = [];

        if ($rows === []) {
            $warnings[] = 'NO_STOCK_FOUND';
        }
        if (! $hasOrigin) {
            $warnings[] = 'ORIGIN_NOT_PROVIDED';
        }

        // 2. + 3. Distances (only when there is something to rank and somewhere to measure from).
        if ($hasOrigin && $rows !== []) {
            [$distances, $fellBack] = $this->distances((float) $input['lat'], (float) $input['lng'], $rows);
            if ($fellBack) {
                $warnings[] = 'DISTANCE_FALLBACK';
            }
            foreach ($rows as &$row) {
                $row += $distances[$row['branch_id']];
            }
            unset($row);
        } else {
            foreach ($rows as &$row) {
                $row += ['distance_km' => null, 'travel_min' => null, 'distance_source' => null];
            }
            unset($row);
        }

        $rows = $this->rank($rows, $hasOrigin);

        return Envelope::success(
            ['items' => $rows, 'meta' => ['count' => count($rows), 'origin_provided' => $hasOrigin]],
            200,
            $warnings,
        );
    }

    /**
     * One call to integration-service for all distinct branches. Anything unusable falls back to the gateway's own estimate.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: array<int, array{distance_km: float, travel_min: int, distance_source: string}>, 1: bool} by branch_id, and whether a fallback was involved
     */
    private function distances(float $lat, float $lng, array $rows): array
    {
        $destinations = [];
        foreach ($rows as $row) {
            $destinations[$row['branch_id']] ??= ['branch_id' => $row['branch_id'], 'lat' => (float) $row['latitude'], 'lng' => (float) $row['longitude']];
        }
        $destinations = array_values($destinations);

        $answer = $this->services->postJson(
            'integration',
            '/internal/v1/distance',
            ['origin' => ['lat' => $lat, 'lng' => $lng], 'destinations' => $destinations],
            (float) config('services.integration.distance_timeout'),
        );

        $usable = $this->usable($answer, $destinations);
        if ($usable !== null) {
            $integrationFallback = in_array('DISTANCE_FALLBACK', $answer->getData(true)['warnings'] ?? [], true);

            return [$usable, $integrationFallback];
        }

        // Down, too slow, or garbage: estimate here. Degrade, never fail.
        $estimator = HaversineEstimator::fromConfig();
        $estimates = [];
        foreach ($destinations as $d) {
            $estimates[$d['branch_id']] = $estimator->estimate($lat, $lng, $d['lat'], $d['lng']) + ['distance_source' => 'haversine'];
        }

        return [$estimates, true];
    }

    /**
     * The integration answer, if it is a complete and well-formed 200 for exactly the branches asked about; otherwise null.
     *
     * @param list<array{branch_id: int, lat: float, lng: float}> $destinations
     * @return array<int, array{distance_km: float, travel_min: int, distance_source: string}>|null
     */
    private function usable(JsonResponse $answer, array $destinations): ?array
    {
        if ($answer->getStatusCode() !== 200) {
            return null;
        }

        $items = $answer->getData(true)['data']['items'] ?? null;
        if (! is_array($items)) {
            return null;
        }

        $byBranch = [];
        foreach ($items as $item) {
            if (! is_array($item)
                || ! is_int($item['branch_id'] ?? null)
                || ! is_numeric($item['distance_km'] ?? null) || (float) $item['distance_km'] < 0
                || ! is_numeric($item['travel_min'] ?? null) || (float) $item['travel_min'] < 0
                || ! in_array($item['source'] ?? null, self::DISTANCE_SOURCES, true)) {
                return null;
            }
            $byBranch[$item['branch_id']] = [
                'distance_km' => round((float) $item['distance_km'], 2),
                'travel_min' => (int) ceil((float) $item['travel_min']),
                'distance_source' => $item['source'],
            ];
        }

        foreach ($destinations as $d) {
            if (! isset($byBranch[$d['branch_id']])) {
                return null;
            }
        }

        return $byBranch;
    }

    /**
     * With a location: travel time, then price, then medicine name, then branch id.
     * Without: price, then branch name, then medicine name, then branch id.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function rank(array $rows, bool $hasOrigin): array
    {
        usort($rows, function (array $a, array $b) use ($hasOrigin) {
            $order = $hasOrigin
                ? [$a['travel_min'] <=> $b['travel_min'], (float) $a['price'] <=> (float) $b['price'], strcmp($a['medicine_name'], $b['medicine_name']), $a['branch_id'] <=> $b['branch_id']]
                : [(float) $a['price'] <=> (float) $b['price'], strcmp($a['branch_name'], $b['branch_name']), strcmp($a['medicine_name'], $b['medicine_name']), $a['branch_id'] <=> $b['branch_id']];

            foreach ($order as $result) {
                if ($result !== 0) {
                    return $result;
                }
            }

            return 0;
        });

        return $rows;
    }
}
