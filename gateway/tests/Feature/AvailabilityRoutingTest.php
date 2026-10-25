<?php

namespace Tests\Feature;

use App\Services\HaversineEstimator;
use App\Services\ServiceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GatewayTestSetup;
use Tests\TestCase;

class AvailabilityRoutingTest extends TestCase
{
    use GatewayTestSetup;

    private const LAT = '14.5995';

    private const LNG = '120.9842';

    private const QUERY = 'medicine_id=1&lat=14.5995&lng=120.9842';

    /** What inventory-service answers: items, or a ready-made [status, body]. @var list<array<string, mixed>>|null */
    private ?array $stock = null;

    /** @var array{0: int, 1: mixed}|null */
    private ?array $inventoryOverride = null;

    /** ok | down | timeout | garbage_html | garbage_envelope | bad_source | unprocessable | fallback | haversine_source */
    private string $distanceMode = 'ok';

    /** @var list<Request> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGateway();
        $this->fakeServices();
    }

    // ------------------------------------------------------------ fixtures

    /** @return array<string, mixed> */
    private function row(int $branchId, string $branch, string $price, string $medicine = 'Paracetamol (Biogesic)', int $medicineId = 1): array
    {
        return [
            'branch_id' => $branchId, 'branch_name' => $branch, 'address' => "{$branch} St.", 'latitude' => 14.0 + $branchId / 10, 'longitude' => 121.0 + $branchId / 10,
            'medicine_id' => $medicineId, 'medicine_name' => $medicine, 'strength' => '500mg', 'dosage_form' => 'Tablet', 'prescription_required' => false,
            'price' => $price, 'available_quantity' => 5, 'status' => 'available',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function threeBranches(): array
    {
        return [$this->row(1, 'Alpha', '10.00'), $this->row(2, 'Bravo', '8.00'), $this->row(3, 'Charlie', '12.00')];
    }

    private function envelope(string $service, mixed $data, int $status = 200, array $warnings = [], ?array $error = null): array
    {
        return ['status' => $status, 'service' => $service, 'request_id' => 'down-1', 'data' => $error ? null : $data, 'error' => $error, 'warnings' => $warnings];
    }

    private function fakeServices(): void
    {
        Http::fake(function (Request $request) {
            $this->calls[] = $request;

            if (str_contains($request->url(), 'inventory.test')) {
                if ($this->inventoryOverride !== null) {
                    [$status, $body] = $this->inventoryOverride;
                    if ($body instanceof \Throwable) {
                        throw $body;
                    }

                    return Http::response($body, $status);
                }

                return Http::response($this->envelope('inventory-service', ['items' => $this->stock ?? $this->threeBranches()]));
            }

            // integration-service /distance
            $destinations = $request['destinations'] ?? [];
            $minutes = [1 => 5, 2 => 5, 3 => 3];
            $items = array_map(fn ($d) => ['branch_id' => $d['branch_id'], 'distance_km' => $minutes[$d['branch_id']] * 0.5, 'travel_min' => $minutes[$d['branch_id']] ?? 20, 'source' => 'osrm'], $destinations);

            return match ($this->distanceMode) {
                'down' => throw new ConnectionException('cURL error 7: Failed to connect to integration.test port 80'),
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out after 2501 milliseconds'),
                'garbage_html' => Http::response('<html>Fatal error at /var/www/app.php</html>', 500),
                'garbage_envelope' => Http::response($this->envelope('integration-service', ['items' => [['branch_id' => 1, 'distance_km' => 1, 'travel_min' => 2, 'source' => 'osrm']]]), 200), // misses branches
                'bad_source' => Http::response($this->envelope('integration-service', ['items' => array_map(fn ($i) => ['source' => 'magic'] + $i, $items)]), 200),
                'negative' => Http::response($this->envelope('integration-service', ['items' => array_map(fn ($i) => ['travel_min' => -4] + $i, $items)]), 200),
                'unprocessable' => Http::response($this->envelope('integration-service', null, 422, [], ['code' => 'VALIDATION_FAILED', 'message' => 'x', 'details' => null]), 422),
                'fallback' => Http::response($this->envelope('integration-service', ['items' => array_map(fn ($i) => ['source' => 'haversine'] + $i, $items)], 200, ['DISTANCE_FALLBACK']), 200),
                default => Http::response($this->envelope('integration-service', ['items' => $items])),
            };
        });
    }

    private function availability(string $query = self::QUERY, array $headers = [])
    {
        return $this->getJson('/api/v1/availability?'.$query, $headers);
    }

    /** @return list<Request> */
    private function distanceCalls(): array
    {
        return array_values(array_filter($this->calls, fn (Request $r) => str_contains($r->url(), '/internal/v1/distance')));
    }

    /** @return list<Request> */
    private function inventoryCalls(): array
    {
        return array_values(array_filter($this->calls, fn (Request $r) => str_contains($r->url(), 'inventory.test')));
    }

    private function ids(array $items): array
    {
        return array_column($items, 'branch_id');
    }

    // ------------------------------------------------------------ the mashup

    public function test_the_public_route_merges_distances_and_ranks_by_travel_time_then_price(): void
    {
        $response = $this->availability()->assertOk();

        $items = $response->json('data.items');
        // Charlie 3 min; then Bravo and Alpha tie on 5 min, so the cheaper Bravo comes first
        $this->assertSame([3, 2, 1], $this->ids($items));
        $this->assertSame([3, 5, 5], array_column($items, 'travel_min'));
        $this->assertSame([1.5, 2.5, 2.5], array_column($items, 'distance_km'));
        $this->assertSame(['osrm', 'osrm', 'osrm'], array_column($items, 'distance_source'));
        $this->assertSame(['count' => 3, 'origin_provided' => true], $response->json('data.meta'));
        $this->assertSame([], $response->json('warnings'));
        $this->assertSame(
            ['branch_id', 'branch_name', 'address', 'latitude', 'longitude', 'medicine_id', 'medicine_name', 'strength', 'dosage_form', 'prescription_required', 'price', 'available_quantity', 'status', 'distance_km', 'travel_min', 'distance_source'],
            array_keys($items[0])
        );
    }

    public function test_ties_are_broken_by_price_then_medicine_name_then_branch_id(): void
    {
        $this->stock = [
            $this->row(2, 'Bravo', '9.00', 'Zeta'), $this->row(1, 'Alpha', '9.00', 'Zeta'), $this->row(1, 'Alpha', '9.00', 'Alpha Med', 2),
            $this->row(2, 'Bravo', '7.00', 'Zeta'), // same branch (same minutes) but cheaper
        ];
        $this->distanceMode = 'ok';

        $items = $this->availability()->assertOk()->json('data.items');

        // branches 1 and 2 are both 5 minutes away: price 7 first; then at 9.00: "Alpha Med" before "Zeta", then branch 1 before 2
        $this->assertSame([[2, '7.00', 'Zeta'], [1, '9.00', 'Alpha Med'], [1, '9.00', 'Zeta'], [2, '9.00', 'Zeta']], array_map(fn ($i) => [$i['branch_id'], $i['price'], $i['medicine_name']], $items));
    }

    public function test_exactly_one_distance_call_with_each_branch_once(): void
    {
        $this->stock = [$this->row(1, 'Alpha', '10.00'), $this->row(1, 'Alpha', '5.00', 'Other', 2), $this->row(2, 'Bravo', '8.00')];

        $this->availability()->assertOk();

        $this->assertCount(1, $this->distanceCalls());
        $call = $this->distanceCalls()[0];
        $this->assertSame('POST', $call->method());
        $this->assertSame('http://integration.test/internal/v1/distance', $call->url());
        $this->assertSame([1, 2], array_column($call['destinations'], 'branch_id'));
        $this->assertEquals(['lat' => 14.5995, 'lng' => 120.9842], $call['origin']);
        $this->assertEquals(14.1, $call['destinations'][0]['lat']);
    }

    public function test_the_distance_fallback_warning_from_integration_is_passed_on(): void
    {
        $this->distanceMode = 'fallback';

        $response = $this->availability()->assertOk();

        $this->assertSame(['DISTANCE_FALLBACK'], $response->json('warnings'));
        $this->assertSame(['haversine'], array_unique(array_column($response->json('data.items'), 'distance_source')));
    }

    // ------------------------------------------------------------ degrade, never fail (T12)

    public function test_integration_down_gives_200_with_the_gateways_haversine_and_the_warning(): void
    {
        $this->distanceMode = 'down';

        $response = $this->availability()->assertOk();

        $items = $response->json('data.items');
        $this->assertSame(['DISTANCE_FALLBACK'], $response->json('warnings'));
        $this->assertSame(['haversine'], array_unique(array_column($items, 'distance_source')));

        // computed here, with the same formula and rounding as integration-service
        $estimator = new HaversineEstimator(1.3, 25.0);
        foreach ($items as $item) {
            $expected = $estimator->estimate(14.5995, 120.9842, $item['latitude'], $item['longitude']);
            $this->assertEqualsWithDelta($expected['distance_km'], $item['distance_km'], 0.001);
            $this->assertSame($expected['travel_min'], $item['travel_min']);
            $this->assertIsInt($item['travel_min']);
        }
        $this->assertSame(array_column($items, 'travel_min'), collect($items)->pluck('travel_min')->sort()->values()->all(), 'ranked by the estimated minutes');
    }

    public function test_the_estimate_uses_the_configured_factor_speed_and_rounding(): void
    {
        $estimator = new HaversineEstimator(1.3, 25.0);

        // Manila to Quezon City area, roughly 10 km as the crow flies
        $result = $estimator->estimate(14.5995, 120.9842, 14.6507, 121.0494);
        $straight = 2 * 6371.0088 * asin(sqrt(sin(deg2rad(0.0512) / 2) ** 2 + cos(deg2rad(14.5995)) * cos(deg2rad(14.6507)) * sin(deg2rad(0.0652) / 2) ** 2));
        $this->assertEqualsWithDelta(round($straight * 1.3, 2), $result['distance_km'], 0.0001);
        $this->assertSame((int) ceil($straight * 1.3 / 25 * 60), $result['travel_min']); // minutes always rounded up

        $this->assertSame(['distance_km' => 0.0, 'travel_min' => 0], $estimator->estimate(14.5, 121.0, 14.5, 121.0));

        config(['services.distance.road_factor' => 2.0, 'services.distance.avg_speed_kmh' => 50.0]);
        $configured = HaversineEstimator::fromConfig()->estimate(14.5995, 120.9842, 14.6507, 121.0494);
        $this->assertEqualsWithDelta(round($straight * 2.0, 2), $configured['distance_km'], 0.0001);
        $this->assertSame((int) ceil($straight * 2.0 / 50 * 60), $configured['travel_min']);
    }

    public function test_a_timeout_or_any_unusable_answer_gives_200_with_the_fallback(): void
    {
        foreach (['timeout', 'garbage_html', 'garbage_envelope', 'bad_source', 'negative', 'unprocessable'] as $mode) {
            $this->distanceMode = $mode;
            $this->calls = [];

            $response = $this->availability()->assertOk();

            $this->assertSame(['DISTANCE_FALLBACK'], $response->json('warnings'), $mode);
            $this->assertSame(['haversine'], array_unique(array_column($response->json('data.items'), 'distance_source')), $mode);
            $this->assertCount(3, $response->json('data.items'), $mode);
            $this->assertCount(1, $this->distanceCalls(), "{$mode}: one attempt, no retry");
            $this->assertStringNotContainsString('/var/www', $response->getContent(), $mode);
        }
    }

    // ------------------------------------------------------------ no coordinates

    public function test_without_coordinates_distances_are_null_and_the_order_is_price_then_branch_name(): void
    {
        $this->stock = [$this->row(3, 'Charlie', '8.00'), $this->row(1, 'Alpha', '10.00'), $this->row(2, 'Bravo', '8.00')];

        $response = $this->availability('medicine_id=1')->assertOk();

        $items = $response->json('data.items');
        $this->assertSame([2, 3, 1], $this->ids($items), 'price 8.00 (Bravo before Charlie), then 10.00');
        foreach ($items as $item) {
            $this->assertNull($item['distance_km']);
            $this->assertNull($item['travel_min']);
            $this->assertNull($item['distance_source']);
        }
        $this->assertSame(['ORIGIN_NOT_PROVIDED'], $response->json('warnings'));
        $this->assertSame(['count' => 3, 'origin_provided' => false], $response->json('data.meta'));
        $this->assertSame([], $this->distanceCalls(), 'no coordinates, no distance call');
    }

    // ------------------------------------------------------------ validation

    public function test_invalid_input_is_422_and_nothing_is_called(): void
    {
        $invalid = [
            'no medicine' => 'lat=14.5&lng=121',
            'nothing at all' => '',
            'empty q' => 'q=',
            'q too long' => 'q='.str_repeat('a', 101),
            'medicine 0' => 'medicine_id=0',
            'medicine text' => 'medicine_id=abc',
            'lat only' => 'medicine_id=1&lat=14.5',
            'lng only' => 'medicine_id=1&lng=121',
            'lat text' => 'medicine_id=1&lat=abc&lng=121',
            'lng text' => 'medicine_id=1&lat=14.5&lng=east',
            'lat exponent' => 'medicine_id=1&lat=1e1&lng=121',
            'lat south of the country' => 'medicine_id=1&lat=4.4&lng=121',
            'lat north of the country' => 'medicine_id=1&lat=21.6&lng=121',
            'lng west of the country' => 'medicine_id=1&lat=14.5&lng=115.9',
            'lng east of the country' => 'medicine_id=1&lat=14.5&lng=127.1',
            'tokyo' => 'medicine_id=1&lat=35.68&lng=139.69',
            'swapped' => 'medicine_id=1&lat=121&lng=14.5',
            'limit 0' => 'medicine_id=1&limit=0',
            'limit 21' => 'medicine_id=1&limit=21',
            'limit text' => 'medicine_id=1&limit=many',
            'array' => 'medicine_id[]=1',
        ];

        foreach ($invalid as $label => $query) {
            $this->availability($query)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonPath('service', 'gateway');
        }

        $this->assertSame([], $this->calls);
    }

    public function test_the_edges_of_the_philippines_box_are_accepted(): void
    {
        foreach ([[4.5, 116], [21.5, 127], [4.5, 127], [21.5, 116]] as [$lat, $lng]) {
            $this->availability("medicine_id=1&lat={$lat}&lng={$lng}")->assertOk();
        }
        $this->availability('medicine_id=1&lat=-0&lng=121')->assertStatus(422);
    }

    public function test_validation_messages_do_not_echo_the_coordinates(): void
    {
        $response = $this->availability('medicine_id=1&lat=35.6812345&lng=139.6912345')->assertStatus(422);

        $this->assertStringNotContainsString('35.6812345', $response->getContent());
        $this->assertStringNotContainsString('139.6912345', $response->getContent());
    }

    // ------------------------------------------------------------ inventory dependency

    public function test_inventory_down_is_503_and_a_timeout_is_504_and_no_distance_call_follows(): void
    {
        $this->inventoryOverride = [200, new ConnectionException('cURL error 7: Failed to connect to inventory.test')];
        $response = $this->availability()->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')->assertJsonPath('service', 'gateway');
        $this->assertStringNotContainsString('inventory.test', $response->getContent());

        $this->inventoryOverride = [200, new ConnectionException('cURL error 28: Operation timed out after 3001 milliseconds')];
        $this->availability()->assertStatus(504)->assertJsonPath('error.code', 'UPSTREAM_TIMEOUT');

        $this->assertSame([], $this->distanceCalls());
        $this->assertCount(2, $this->inventoryCalls(), 'not retried');
    }

    public function test_a_non_envelope_answer_from_inventory_is_502(): void
    {
        $this->inventoryOverride = [500, '<html>Fatal error at /var/www/app.php</html>'];

        $response = $this->availability()->assertStatus(502)->assertJsonPath('error.code', 'BAD_UPSTREAM_RESPONSE');

        $this->assertStringNotContainsString('/var/www', $response->getContent());
    }

    public function test_a_malformed_success_answer_from_inventory_is_502(): void
    {
        $this->inventoryOverride = [200, $this->envelope('inventory-service', ['unexpected' => true])];

        $this->availability()->assertStatus(502);
    }

    public function test_an_unknown_medicine_and_inventory_validation_errors_pass_through(): void
    {
        $notFound = $this->envelope('inventory-service', null, 404, [], ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.', 'details' => null]);
        $this->inventoryOverride = [404, $notFound];
        $this->assertSame($notFound, $this->availability('medicine_id=99999&lat=14.5&lng=121')->assertStatus(404)->json());

        $invalid = $this->envelope('inventory-service', null, 422, [], ['code' => 'VALIDATION_FAILED', 'message' => 'The given data was invalid.', 'details' => ['q' => ['x']]]);
        $this->inventoryOverride = [422, $invalid];
        $this->assertSame($invalid, $this->availability()->assertStatus(422)->json());

        $this->assertSame([], $this->distanceCalls());
    }

    public function test_an_empty_result_is_200_with_no_stock_found_and_no_distance_call(): void
    {
        $this->stock = [];

        $withOrigin = $this->availability()->assertOk();
        $this->assertSame([], $withOrigin->json('data.items'));
        $this->assertSame(['NO_STOCK_FOUND'], $withOrigin->json('warnings'));
        $this->assertSame(['count' => 0, 'origin_provided' => true], $withOrigin->json('data.meta'));

        $without = $this->availability('q=amoxicillin')->assertOk();
        $this->assertSame(['NO_STOCK_FOUND', 'ORIGIN_NOT_PROVIDED'], $without->json('warnings'));
        $this->assertSame(['count' => 0, 'origin_provided' => false], $without->json('data.meta'));

        $this->assertSame([], $this->distanceCalls());
    }

    // ------------------------------------------------------------ what is sent downstream

    public function test_inventory_gets_only_the_search_and_never_the_coordinates_or_client_headers(): void
    {
        $this->availability('q=%20paracetamol%20&lat=14.5995&lng=120.9842&limit=7', ['X-User-Role' => 'ceo', 'X-Internal-Key' => 'attacker', 'Authorization' => 'Bearer x'])->assertOk();

        $call = $this->inventoryCalls()[0];
        parse_str((string) parse_url($call->url(), PHP_URL_QUERY), $query);
        $this->assertSame(['q' => 'paracetamol', 'limit' => '7'], $query);
        $this->assertStringNotContainsString('14.5995', $call->url());
        $this->assertSame([$this->internalKey], $call->header('X-Internal-Key'));
        $this->assertFalse($call->hasHeader('X-User-Role'));
        $this->assertFalse($call->hasHeader('Authorization'));

        $distance = $this->distanceCalls()[0];
        $this->assertFalse($distance->hasHeader('X-User-Role'));
        $this->assertSame([$this->internalKey], $distance->header('X-Internal-Key'));
    }

    public function test_the_default_limit_is_ten(): void
    {
        $this->availability('medicine_id=1')->assertOk();

        $this->assertStringContainsString('limit=10', $this->inventoryCalls()[0]->url());
        $this->assertStringContainsString('medicine_id=1', $this->inventoryCalls()[0]->url());
    }

    // ------------------------------------------------------------ privacy

    public function test_the_coordinates_never_reach_the_application_log_or_the_response(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'gwlog');
        config(['logging.default' => 'privacytest', 'logging.channels.privacytest' => ['driver' => 'single', 'path' => $log, 'level' => 'debug']]);

        // the paths that log the most: a distance outage, garbage from integration, inventory failures, validation
        foreach (['down', 'timeout', 'garbage_html', 'garbage_envelope'] as $mode) {
            $this->distanceMode = $mode;
            $this->availability('medicine_id=1&lat=14.5995&lng=120.9842')->assertOk();
        }
        $this->distanceMode = 'ok';
        $this->availability('medicine_id=1&lat=14.5995&lng=120.9842')->assertOk();
        $this->inventoryOverride = [200, new ConnectionException('cURL error 28: Operation timed out')];
        $this->availability('medicine_id=1&lat=14.5995&lng=120.9842')->assertStatus(504);
        $this->inventoryOverride = null;
        $this->availability('medicine_id=1&lat=35.6812&lng=139.6912')->assertStatus(422);
        $response = $this->availability('medicine_id=1&lat=14.5995&lng=120.9842')->assertOk();

        $logged = (string) file_get_contents($log);
        $this->assertNotSame('', $logged, 'the failure paths do log (so this test can see a leak)');
        foreach (['14.5995', '120.9842', '35.6812', '139.6912', 'lat=', 'lng='] as $needle) {
            $this->assertStringNotContainsString($needle, $logged, "log leaks {$needle}");
        }
        foreach (['14.5995', '120.9842'] as $needle) {
            $this->assertStringNotContainsString($needle, $response->getContent(), 'the origin is never echoed');
        }
        @unlink($log);
    }

    // ------------------------------------------------------------ timeouts, limits, access

    public function test_inventory_waits_three_seconds_and_distance_the_configured_time(): void
    {
        $spy = new class extends ServiceClient
        {
            /** @var list<float> */
            public array $timeouts = [];

            protected function pending(float $timeoutSeconds): PendingRequest
            {
                $this->timeouts[] = $timeoutSeconds;

                return parent::pending($timeoutSeconds);
            }
        };
        $this->app->instance(ServiceClient::class, $spy);

        $this->availability()->assertOk();
        $this->assertSame([3.0, 2.5], $spy->timeouts);

        $spy->timeouts = [];
        config(['services.integration.distance_timeout' => 1.5]);
        $this->availability()->assertOk();
        $this->assertSame([3.0, 1.5], $spy->timeouts);

        $this->assertSame(2.5, (float) (include base_path('config/services.php'))['integration']['distance_timeout']);
    }

    public function test_the_route_is_public_and_limited_to_sixty_requests_a_minute_per_ip(): void
    {
        foreach (range(1, 60) as $n) {
            $this->availability('medicine_id=1')->assertOk(); // no token
        }

        $response = $this->availability('medicine_id=1')->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')->assertJsonPath('service', 'gateway');
        $this->assertNotEmpty($response->headers->get('Retry-After'));
    }

    public function test_a_bad_token_is_ignored_on_this_public_route(): void
    {
        $this->availability('medicine_id=1', $this->bearer($this->token([], str_repeat('x', 40))))->assertOk();
    }
}
