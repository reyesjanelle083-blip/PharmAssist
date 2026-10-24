<?php

namespace Tests\Feature;

use App\Models\ApiCache;
use App\Models\IntegrationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Every OSRM call here is faked with Http::fake: these tests never touch the real network. */
class DistanceTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'integration-test-key';
    private const OSRM = 'https://osrm.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pharmassist.internal_key' => self::KEY,
            'pharmassist.service' => 'integration-service',
            'integration.mode' => 'live',
            'integration.allow_stub_in_production' => false,
            'integration.distance.force_fail' => false,
            'integration.distance.cache_ttl_minutes' => 10,
            'integration.distance.osrm.base_url' => self::OSRM,
            'integration.distance.osrm.timeout_seconds' => 1.5,
            'integration.distance.osrm.retries' => 1,
            'integration.distance.osrm.deadline_seconds' => 2.5,
            'integration.distance.osrm.backoff_ms' => 0,
            'integration.distance.osrm.user_agent' => 'TestAgent/1.0 (test only)',
        ]);
    }

    // ------------------------------------------------------------ helpers

    private function headers(): array
    {
        return ['X-Internal-Key' => self::KEY, 'Accept' => 'application/json'];
    }

    /** Destinations deliberately NOT sorted by branch_id: the response must keep this order. */
    private function payload(float $lat = 14.5990, float $lng = 120.9840): array
    {
        return [
            'origin' => ['lat' => $lat, 'lng' => $lng],
            'destinations' => [
                ['branch_id' => 3, 'lat' => 14.723, 'lng' => 121.037],
                ['branch_id' => 1, 'lat' => 14.6507, 'lng' => 120.983],
                ['branch_id' => 2, 'lat' => 14.583, 'lng' => 121.005],
            ],
        ];
    }

    /** @param list<float|int|null> $durations @param list<float|int|null> $distances */
    private function osrmBody(array $durations = [1201.0, 301.0, 60], array $distances = [15999.9, 1234.5, 800]): array
    {
        return ['code' => 'Ok', 'durations' => [$durations], 'distances' => [$distances]];
    }

    private function distance(?array $payload = null)
    {
        return $this->postJson('/internal/v1/distance', $payload ?? $this->payload(), $this->headers());
    }

    private function osrmCalls(): int
    {
        return Http::recorded()->count();
    }

    private function lastLog(): IntegrationLog
    {
        return IntegrationLog::latest('id')->firstOrFail();
    }

    private function sources(array $items): array
    {
        return array_column($items, 'source');
    }

    // ------------------------------------------------------------ authentication

    public function test_a_missing_or_wrong_internal_key_returns_401(): void
    {
        Http::fake();

        $this->postJson('/internal/v1/distance', $this->payload())->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
        $this->postJson('/internal/v1/distance', $this->payload(), ['X-Internal-Key' => 'wrong'])->assertStatus(401);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ OSRM success

    public function test_an_osrm_answer_is_parsed_with_lon_lat_order_rounded_up_minutes_and_two_decimal_km(): void
    {
        Http::fake(['osrm.test/*' => Http::response($this->osrmBody(), 200)]);

        $response = $this->distance()->assertOk()
            ->assertJsonStructure(['status', 'service', 'request_id', 'data' => ['items'], 'error', 'warnings'])
            ->assertJsonPath('service', 'integration-service')
            ->assertJsonPath('warnings', []);

        $items = $response->json('data.items');

        // same order as sent (3, 1, 2), not sorted
        $this->assertSame([3, 1, 2], array_column($items, 'branch_id'));
        $this->assertSame(['osrm', 'osrm', 'osrm'], $this->sources($items));
        $this->assertSame([21, 6, 1], array_column($items, 'travel_min'));            // 20.02 -> 21, 5.02 -> 6, 1.0 -> 1
        $this->assertEqualsWithDelta(16.00, $items[0]['distance_km'], 0.0001);        // 15999.9 m
        $this->assertEqualsWithDelta(1.23, $items[1]['distance_km'], 0.0001);         // 1234.5 m
        $this->assertEqualsWithDelta(0.80, $items[2]['distance_km'], 0.0001);
        $this->assertSame(['branch_id', 'distance_km', 'source', 'travel_min'], collect(array_keys($items[0]))->sort()->values()->all());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            return $r->method() === 'GET'
                // origin first, then the branches in the order sent; every pair is lon,lat
                && str_starts_with($r->url(), 'https://osrm.test/table/v1/driving/120.984000,14.599000;121.037000,14.723000;120.983000,14.650700;121.005000,14.583000?')
                && str_contains($r->url(), 'sources=0')
                && str_contains($r->url(), 'destinations=1;2;3')
                && str_contains($r->url(), 'annotations=duration,distance')
                && $r->header('User-Agent') === ['TestAgent/1.0 (test only)'];
        });

        $log = $this->lastLog();
        $this->assertSame(['osrm', 'table', 'success', 200, null], [$log->provider, $log->operation, $log->outcome, $log->status_code, $log->error_message]);
        $this->assertSame($response->json('request_id'), $log->request_id);
        $this->assertSame(1, IntegrationLog::count(), 'one row per attempt group');
    }

    public function test_a_retry_after_a_5xx_can_succeed(): void
    {
        Http::fake(['osrm.test/*' => Http::sequence()->push('boom', 500)->push($this->osrmBody(), 200)]);

        $response = $this->distance()->assertOk()->assertJsonPath('warnings', []);

        $this->assertSame(['osrm', 'osrm', 'osrm'], $this->sources($response->json('data.items')));
        $this->assertSame(2, $this->osrmCalls());
        $this->assertSame(1, IntegrationLog::count());
        $this->assertSame('success', $this->lastLog()->outcome);
    }

    // ------------------------------------------------------------ failures fall back, never a 5xx

    public function test_a_5xx_is_retried_then_falls_back_with_a_warning_and_still_returns_200(): void
    {
        Http::fake(['osrm.test/*' => Http::sequence()->push('boom', 500)->push('boom', 503)]);

        $response = $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK'])->assertJsonPath('error', null);

        $items = $response->json('data.items');
        $this->assertSame([3, 1, 2], array_column($items, 'branch_id'));
        $this->assertSame(['haversine', 'haversine', 'haversine'], $this->sources($items));
        foreach ($items as $item) {
            $this->assertGreaterThan(0, $item['distance_km']);
            $this->assertGreaterThan(0, $item['travel_min']);
        }

        $this->assertSame(2, $this->osrmCalls(), 'first attempt plus one retry');
        $log = $this->lastLog();
        $this->assertSame(['failure', 503, 'http_5xx'], [$log->outcome, $log->status_code, $log->error_message]);
        $this->assertSame(1, IntegrationLog::count());
    }

    public function test_a_timeout_falls_back_inside_the_deadline(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out after 1500 milliseconds with 0 bytes received');
        });

        $started = microtime(true);
        $response = $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);
        $elapsed = microtime(true) - $started;

        $this->assertSame(['haversine', 'haversine', 'haversine'], $this->sources($response->json('data.items')));
        $this->assertSame(2, $calls);
        $this->assertLessThan(2.5, $elapsed);
        $this->assertSame(['timeout', null, 'timeout'], [$this->lastLog()->outcome, $this->lastLog()->status_code, $this->lastLog()->error_message]);
    }

    public function test_no_new_attempt_starts_once_the_total_deadline_is_used_up(): void
    {
        config(['integration.distance.osrm.retries' => 5, 'integration.distance.osrm.deadline_seconds' => 0.4]);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            usleep(250_000); // a slow upstream: 0.25 s per attempt
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $started = microtime(true);
        $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);
        $elapsed = microtime(true) - $started;

        $this->assertLessThanOrEqual(2, $calls, 'five retries were allowed, but the 0.4 s deadline stops them');
        $this->assertLessThan(1.0, $elapsed);
    }

    public function test_a_tls_error_falls_back_without_retrying_and_a_refused_connection_is_retried(): void
    {
        $calls = 0;
        $message = 'cURL error 60: SSL certificate problem: unable to get local issuer certificate for https://osrm.test/table/v1/driving/120.984000,14.599000';
        Http::fake(function () use (&$calls, &$message) {
            $calls++;
            throw new ConnectionException($message);
        });

        $response = $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);
        $this->assertSame(1, $calls, 'a certificate problem fails the same way every time, so it is not retried');
        $this->assertSame(['failure', 'tls_error'], [$this->lastLog()->outcome, $this->lastLog()->error_message]);
        $this->assertStringNotContainsString('osrm.test', $response->getContent());

        Cache::flush();
        $calls = 0;
        $message = 'cURL error 7: Failed to connect to osrm.test port 443';
        $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);
        $this->assertSame(2, $calls, 'a refused connection is transient: one retry');
        $this->assertSame('connection_error', $this->lastLog()->error_message);
    }

    public function test_a_429_falls_back_without_retrying(): void
    {
        Http::fake(['osrm.test/*' => Http::response('slow down', 429)]);

        $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);

        $this->assertSame(1, $this->osrmCalls());
        $this->assertSame(['failure', 429, 'http_429'], [$this->lastLog()->outcome, $this->lastLog()->status_code, $this->lastLog()->error_message]);
    }

    public function test_a_malformed_body_falls_back_without_retrying(): void
    {
        $body = '<html>not json</html>';
        Http::fake(['osrm.test/*' => function () use (&$body) {
            return Http::response($body, 200);
        }]);

        $bodies = [
            '<html>not json</html>',
            json_encode(['code' => 'NoRoute']),
            json_encode(['code' => 'Ok', 'durations' => [[1, 2]], 'distances' => [[1, 2]]]),          // wrong length
            json_encode(['code' => 'Ok', 'durations' => [[1, 2, 'x']], 'distances' => [[1, 2, 3]]]),   // non numeric
            json_encode(['code' => 'Ok']),
        ];

        foreach ($bodies as $i => $candidate) {
            Cache::flush(); // keep the circuit closed between cases
            $body = $candidate;
            $before = $this->osrmCalls();

            $response = $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);
            $this->assertSame(['haversine', 'haversine', 'haversine'], $this->sources($response->json('data.items')), "case {$i}");
            $this->assertSame($before + 1, $this->osrmCalls(), "case {$i}: a malformed answer is not retried");
            $this->assertSame('malformed_response', $this->lastLog()->error_message, "case {$i}");
        }
    }

    public function test_a_null_value_from_osrm_falls_back_for_that_item_only(): void
    {
        Http::fake(['osrm.test/*' => Http::response($this->osrmBody([1201.0, null, 60], [15999.9, null, 800]), 200)]);

        $response = $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);
        $items = $response->json('data.items');

        $this->assertSame(['osrm', 'haversine', 'osrm'], $this->sources($items));
        $this->assertSame([3, 1, 2], array_column($items, 'branch_id'));
        $this->assertSame(21, $items[0]['travel_min']);
        $this->assertGreaterThan(0, $items[1]['distance_km']);
        $this->assertSame(1, $items[2]['travel_min']);

        $this->assertSame(['fallback', 200, 'partial_null_values'], [$this->lastLog()->outcome, $this->lastLog()->status_code, $this->lastLog()->error_message]);

        // partial answers are not cached: the next request asks OSRM again
        $this->distance()->assertOk();
        $this->assertSame(2, $this->osrmCalls());
        $this->assertSame(0, ApiCache::count());
    }

    public function test_the_haversine_estimate_is_deterministic_and_plausible(): void
    {
        Http::fake(['osrm.test/*' => Http::response('boom', 500)]);

        $payload = $this->payload(14.5995, 120.9842); // Manila city center
        $first = $this->distance($payload)->json('data.items');
        $second = $this->distance($payload)->json('data.items');

        $this->assertSame($first, $second);

        $branchOne = collect($first)->firstWhere('branch_id', 1);
        $this->assertEqualsWithDelta(7.4, $branchOne['distance_km'], 1.0);                 // about 5.7 km straight line x 1.3
        $this->assertSame((int) ceil($branchOne['distance_km'] / 25 * 60), $branchOne['travel_min']); // 25 km/h average
    }

    // ------------------------------------------------------------ cache

    public function test_the_cache_avoids_a_second_osrm_call_and_stores_no_coordinates(): void
    {
        Http::fake(['osrm.test/*' => Http::response($this->osrmBody(), 200)]);

        $first = $this->distance()->assertOk()->json('data.items');
        $second = $this->distance()->assertOk()->json('data.items');
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->osrmCalls());

        // an origin about 40 m away rounds to the same 3-decimal key
        $this->distance($this->payload(14.5994, 120.9844))->assertOk();
        $this->assertSame(1, $this->osrmCalls());

        // a clearly different origin is a different key
        $this->distance($this->payload(14.6100, 120.9840))->assertOk();
        $this->assertSame(2, $this->osrmCalls());

        $row = ApiCache::orderBy('id')->firstOrFail();
        $this->assertSame(2, ApiCache::count());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->cache_key);
        $this->assertSame(['items'], array_keys($row->payload));
        $this->assertSame(['branch_id', 'distance_km', 'travel_min'], array_keys($row->payload['items'][0]));
        $this->assertEqualsWithDelta(10, now()->diffInMinutes($row->expires_at, false), 1);
    }

    public function test_the_cache_expires_after_ten_minutes(): void
    {
        Http::fake(['osrm.test/*' => Http::response($this->osrmBody(), 200)]);

        $this->distance()->assertOk();
        $this->travel(9)->minutes();
        $this->distance()->assertOk();
        $this->assertSame(1, $this->osrmCalls());

        $this->travel(2)->minutes(); // 11 minutes in total
        $this->distance()->assertOk();
        $this->assertSame(2, $this->osrmCalls());
    }

    public function test_fallback_answers_are_not_cached(): void
    {
        Http::fake(['osrm.test/*' => Http::response('boom', 500)]);

        $this->distance()->assertOk();
        $this->distance()->assertOk();

        $this->assertSame(4, $this->osrmCalls(), 'each request retried OSRM: nothing was served from the cache');
        $this->assertSame(0, ApiCache::count());
    }

    // ------------------------------------------------------------ circuit breaker

    public function test_the_circuit_opens_after_three_failures_and_closes_again_after_a_success(): void
    {
        $fail = true;
        $calls = 0;
        Http::fake(['osrm.test/*' => function () use (&$fail, &$calls) {
            $calls++;

            return $fail ? Http::response('boom', 500) : Http::response($this->osrmBody(), 200);
        }]);

        // three consecutive failed requests (two attempts each)
        foreach ([1, 2, 3] as $n) {
            $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);
        }
        $this->assertSame(6, $calls);

        // the circuit is now open: OSRM is not called, the answer is still 200 with the fallback
        $response = $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);
        $this->assertSame(['haversine', 'haversine', 'haversine'], $this->sources($response->json('data.items')));
        $this->distance()->assertOk();
        $this->assertSame(6, $calls, 'no OSRM call while the circuit is open');
        $this->assertSame(['fallback', 'circuit_open'], [$this->lastLog()->outcome, $this->lastLog()->error_message]);

        // after 60 seconds one request is allowed through again; a success closes the circuit
        $this->travel(61)->seconds();
        $fail = false;
        $this->distance()->assertOk()->assertJsonPath('warnings', []);
        $this->assertSame(7, $calls);

        $this->distance($this->payload(14.7, 121.0))->assertOk()->assertJsonPath('warnings', []);
        $this->assertSame(8, $calls, 'the circuit is closed again');
    }

    public function test_a_failure_in_the_half_open_state_reopens_the_circuit_immediately(): void
    {
        $calls = 0;
        Http::fake(['osrm.test/*' => function () use (&$calls) {
            $calls++;

            return Http::response('boom', 500);
        }]);

        foreach ([1, 2, 3] as $n) {
            $this->distance()->assertOk();
        }
        $this->assertSame(6, $calls);

        $this->travel(61)->seconds();
        $this->distance()->assertOk();                 // the one trial request fails ...
        $this->assertSame(8, $calls);
        $this->distance()->assertOk();                 // ... so the circuit is open again straight away
        $this->assertSame(8, $calls);
    }

    // ------------------------------------------------------------ stub mode and failure simulation

    public function test_stub_mode_never_touches_the_network_and_is_deterministic(): void
    {
        config(['integration.mode' => 'stub']);
        Http::fake();

        $first = $this->distance()->assertOk()->assertJsonPath('warnings', []);
        $second = $this->distance()->assertOk();

        $this->assertSame(['stub', 'stub', 'stub'], $this->sources($first->json('data.items')));
        $this->assertSame($first->json('data.items'), $second->json('data.items'));
        $this->assertSame([3, 1, 2], array_column($first->json('data.items'), 'branch_id'));
        Http::assertNothingSent();
        $this->assertSame(0, IntegrationLog::count());
        $this->assertSame(0, ApiCache::count());
    }

    public function test_distance_force_fail_uses_the_fallback_without_calling_osrm(): void
    {
        config(['integration.distance.force_fail' => true]);
        Http::fake();

        $response = $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);

        $this->assertSame(['haversine', 'haversine', 'haversine'], $this->sources($response->json('data.items')));
        Http::assertNothingSent();
        $this->assertSame(['failure', 'forced_failure'], [$this->lastLog()->outcome, $this->lastLog()->error_message]);
    }

    public function test_stub_mode_and_forced_failures_are_refused_in_production_unless_allowed(): void
    {
        Http::fake();
        $this->app->detectEnvironment(fn () => 'production');

        config(['integration.mode' => 'stub']);
        $this->distance()->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

        config(['integration.mode' => 'live', 'integration.distance.force_fail' => true]);
        $this->distance()->assertStatus(500);
        Http::assertNothingSent();

        config(['integration.mode' => 'stub', 'integration.distance.force_fail' => false, 'integration.allow_stub_in_production' => true]);
        $this->distance()->assertOk()->assertJsonPath('data.items.0.source', 'stub');
    }

    public function test_the_smoke_command_refuses_stub_mode_and_makes_no_request(): void
    {
        config(['integration.mode' => 'stub']);
        Http::fake();

        $this->artisan('integration:osrm-smoke')->expectsOutputToContain('refuses to run while INTEGRATION_MODE=stub')->assertFailed();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ validation

    public function test_invalid_input_returns_422(): void
    {
        Http::fake();
        $ok = $this->payload();
        $many = array_map(fn (int $i) => ['branch_id' => $i, 'lat' => 14.6, 'lng' => 121.0], range(1, 26));

        $cases = [
            'origin latitude too far south' => [['origin' => ['lat' => 4.4, 'lng' => 121.0]] + $ok, 'origin.lat'],
            'origin latitude too far north' => [['origin' => ['lat' => 21.6, 'lng' => 121.0]] + $ok, 'origin.lat'],
            'origin longitude too far west' => [['origin' => ['lat' => 14.6, 'lng' => 115.9]] + $ok, 'origin.lng'],
            'origin longitude too far east' => [['origin' => ['lat' => 14.6, 'lng' => 127.1]] + $ok, 'origin.lng'],
            'origin in Europe' => [['origin' => ['lat' => 48.85, 'lng' => 2.35]] + $ok, 'origin.lat'],
            'destination outside the Philippines' => [['destinations' => [['branch_id' => 1, 'lat' => 35.0, 'lng' => 139.0]]] + $ok, 'destinations.0.lat'],
            'origin latitude is a string' => [['origin' => ['lat' => '14.6', 'lng' => 121.0]] + $ok, 'origin.lat'],
            'origin latitude is text' => [['origin' => ['lat' => 'north', 'lng' => 121.0]] + $ok, 'origin.lat'],
            'destination longitude is a string' => [['destinations' => [['branch_id' => 1, 'lat' => 14.6, 'lng' => '121.0']]] + $ok, 'destinations.0.lng'],
            '26 destinations' => [['destinations' => $many] + $ok, 'destinations'],
            'no destinations' => [['destinations' => []] + $ok, 'destinations'],
            'duplicate branch_id' => [['destinations' => [['branch_id' => 1, 'lat' => 14.6, 'lng' => 121.0], ['branch_id' => 1, 'lat' => 14.7, 'lng' => 121.0]]] + $ok, 'destinations.0.branch_id'],
            'missing origin' => [array_diff_key($ok, ['origin' => 1]), 'origin'],
            'missing destinations' => [array_diff_key($ok, ['destinations' => 1]), 'destinations'],
            'origin without lng' => [['origin' => ['lat' => 14.6]] + $ok, 'origin.lng'],
            'destination without branch_id' => [['destinations' => [['lat' => 14.6, 'lng' => 121.0]]] + $ok, 'destinations.0.branch_id'],
            'destination without lat' => [['destinations' => [['branch_id' => 1, 'lng' => 121.0]]] + $ok, 'destinations.0.lat'],
            'branch_id zero' => [['destinations' => [['branch_id' => 0, 'lat' => 14.6, 'lng' => 121.0]]] + $ok, 'destinations.0.branch_id'],
        ];

        foreach ($cases as $name => [$payload, $field]) {
            $response = $this->distance($payload)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
            $this->assertArrayHasKey($field, $response->json('error.details'), $name);
        }

        Http::assertNothingSent();
        $this->assertSame(0, IntegrationLog::count());
    }

    public function test_the_boundaries_of_the_philippines_box_and_25_destinations_are_accepted(): void
    {
        Http::fake(['osrm.test/*' => Http::response('boom', 500)]);
        $many = array_map(fn (int $i) => ['branch_id' => $i, 'lat' => 14.6, 'lng' => 121.0], range(1, 25));

        $this->distance(['origin' => ['lat' => 4.5, 'lng' => 116], 'destinations' => $many])->assertOk()->assertJsonCount(25, 'data.items');
        $this->distance(['origin' => ['lat' => 21.5, 'lng' => 127], 'destinations' => $many])->assertOk();
    }

    // ------------------------------------------------------------ privacy and resilience

    public function test_no_coordinates_user_agent_or_urls_are_stored_in_logs_or_cache(): void
    {
        // one successful call (cached) and one failing call whose exception message contains the URL
        $fail = false;
        Http::fake(['osrm.test/*' => function () use (&$fail) {
            if ($fail) {
                throw new ConnectionException('cURL error 28: Operation timed out for https://osrm.test/table/v1/driving/120.984200,14.599500;121.037000,14.723000');
            }

            return Http::response($this->osrmBody(), 200);
        }]);
        $this->distance($this->payload(14.5995, 120.9842))->assertOk();

        $fail = true;
        Cache::flush();
        $this->distance($this->payload(14.6100, 120.9500))->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);

        $stored = json_encode(IntegrationLog::all()->toArray()).json_encode(ApiCache::all()->toArray());

        $this->assertGreaterThanOrEqual(2, IntegrationLog::count());
        foreach (['14.5995', '120.9842', '14.5990', '14.61', '120.95', '14.6507', '120.983', '14.583', '121.005', '14.723', '121.037',
            'osrm.test', 'TestAgent', 'https://', '"lat"', '"lng"', 'origin', 'cURL'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored, "found '{$forbidden}' in stored data");
        }

        foreach (ApiCache::all() as $row) {
            $this->assertSame(['items'], array_keys($row->payload));
            foreach ($row->payload['items'] as $item) {
                $this->assertSame(['branch_id', 'distance_km', 'travel_min'], array_keys($item));
            }
        }
        $this->assertSame('timeout', IntegrationLog::latest('id')->first()->error_message);
    }

    public function test_the_response_never_echoes_the_coordinates(): void
    {
        Http::fake(['osrm.test/*' => Http::response($this->osrmBody(), 200)]);

        $body = $this->distance($this->payload(14.5995, 120.9842))->assertOk()->getContent();

        foreach (['14.5995', '120.9842', '14.723', '121.037'] as $coordinate) {
            $this->assertStringNotContainsString($coordinate, $body);
        }
    }

    public function test_a_broken_database_never_turns_into_a_5xx(): void
    {
        $fail = false;
        Http::fake(['osrm.test/*' => function () use (&$fail) {
            return $fail ? Http::response('boom', 500) : Http::response($this->osrmBody(), 200);
        }]);
        Schema::drop('api_cache');
        Schema::drop('integration_logs');

        $this->distance()->assertOk()->assertJsonPath('warnings', [])->assertJsonPath('data.items.0.source', 'osrm');

        $fail = true;
        Cache::flush();
        $this->distance()->assertOk()->assertJsonPath('warnings', ['DISTANCE_FALLBACK']);
    }
}
