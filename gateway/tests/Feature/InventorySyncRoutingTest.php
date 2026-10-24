<?php

namespace Tests\Feature;

use App\Services\ServiceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GatewayTestSetup;
use Tests\TestCase;

class InventorySyncRoutingTest extends TestCase
{
    use GatewayTestSetup;

    private const URL = '/api/v1/integrations/inventory/sync';
    private const XML = '<?xml version="1.0" encoding="UTF-8"?><inventoryFeed branchId="1" generatedAt="2026-10-24T08:00:00+08:00"><item/></inventoryFeed>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGateway();
    }

    private function as(string $role, ?int $branch = 1): array
    {
        return $this->bearer($this->token(['sub' => '9', 'role' => $role, 'branch_id' => in_array($role, ['ceo', 'customer'], true) ? null : $branch]));
    }

    /** @param array<string, string> $headers */
    private function send(string $body = self::XML, string $type = 'application/xml', array $headers = [], string $query = '')
    {
        $server = $this->transformHeadersToServerVars($headers + ['Accept' => 'application/json']);
        $server['CONTENT_TYPE'] = $type;

        return $this->call('POST', self::URL.$query, [], [], [], $server, $body);
    }

    private function envelope(string $service, mixed $data, int $status = 200, ?array $error = null): array
    {
        return ['status' => $status, 'service' => $service, 'request_id' => 'down', 'data' => $error ? null : $data, 'error' => $error, 'warnings' => []];
    }

    private function feed(int $branch = 1): array
    {
        return ['branch_id' => $branch, 'generated_at' => '2026-10-24T08:00:00+08:00', 'items' => [['row' => 1, 'generic_name' => 'Amoxicillin']]];
    }

    private function fake(?array $translate = null, ?array $sync = null, int $syncStatus = 200, int $translateStatus = 200): void
    {
        Http::fake([
            'integration.test/*' => Http::response($translate ?? $this->envelope('integration-service', $this->feed()), $translateStatus),
            'inventory.test/*' => Http::response($sync ?? $this->envelope('inventory-service', ['accepted' => 1, 'rejected' => 0, 'unchanged' => 0, 'errors' => []]), $syncStatus),
        ]);
    }

    // ------------------------------------------------------------ access

    public function test_no_token_is_401_and_other_roles_are_403_and_nothing_is_called(): void
    {
        Http::fake();

        $this->send()->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        foreach (['customer', 'pharmacy_technician', 'pharmacy_assistant'] as $role) {
            $this->send(self::XML, 'application/xml', $this->as($role))->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        }

        Http::assertNothingSent();
    }

    public function test_manager_and_ceo_are_allowed(): void
    {
        $this->fake();

        $this->send(self::XML, 'application/xml', $this->as('branch_manager'))->assertOk()->assertJsonPath('data.accepted', 1);
        $this->send(self::XML, 'application/xml', $this->as('ceo'))->assertOk();
    }

    // ------------------------------------------------------------ checks done at the gateway

    public function test_a_wrong_content_type_is_415_and_nothing_is_called(): void
    {
        Http::fake();

        $this->send('a,b', 'text/csv', $this->as('branch_manager'))->assertStatus(415)->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE')->assertJsonPath('service', 'gateway');
        $this->send('x', 'text/plain', $this->as('branch_manager'))->assertStatus(415);

        Http::assertNothingSent();
    }

    public function test_an_oversized_body_is_413_and_nothing_is_called(): void
    {
        Http::fake();

        $this->send(str_repeat('a', 512 * 1024 + 1), 'application/xml', $this->as('branch_manager'))
            ->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE')->assertJsonPath('service', 'gateway');

        Http::assertNothingSent();
    }

    public function test_a_bad_strict_value_is_422(): void
    {
        Http::fake();

        $this->send(self::XML, 'application/xml', $this->as('branch_manager'), '?strict=maybe')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ orchestration

    public function test_the_raw_body_goes_to_translate_then_the_normalised_json_to_inventory(): void
    {
        $this->fake();

        $this->send(self::XML, 'text/xml; charset=utf-8', $this->as('branch_manager', 1), '?strict=true')->assertOk();

        $sent = Http::recorded();
        $this->assertCount(2, $sent);

        [$translate] = $sent[0];
        $this->assertSame('http://integration.test/internal/v1/inventory-feed/translate', $translate->url());
        $this->assertSame(self::XML, $translate->body());
        $this->assertSame(['text/xml'], $translate->header('Content-Type')); // normalised, not the client's value

        [$sync] = $sent[1];
        $this->assertSame('http://inventory.test/internal/v1/inventory/sync', $sync->url());
        $this->assertSame(['branch_id' => 1, 'strict' => true, 'items' => $this->feed()['items']], $sync->data());
    }

    public function test_strict_defaults_to_false(): void
    {
        $this->fake();

        $this->send(self::XML, 'application/xml', $this->as('branch_manager'))->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/inventory/sync') && $r->data()['strict'] === false);
    }

    public function test_a_json_feed_is_forwarded_as_json(): void
    {
        $this->fake();

        $this->send('{"branch_id":1}', 'application/json', $this->as('branch_manager'))->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/translate') && $r->header('Content-Type') === ['application/json'] && $r->body() === '{"branch_id":1}');
    }

    public function test_a_translator_refusal_is_returned_as_it_is_and_inventory_is_not_called(): void
    {
        $body = $this->envelope('integration-service', null, 400, ['code' => 'MALFORMED_XML', 'message' => 'The feed is not a valid inventory feed.', 'details' => ['errors' => ['Line 2: bad']]]);
        $this->fake($body, null, 200, 400);

        $response = $this->send(self::XML, 'application/xml', $this->as('branch_manager'))->assertStatus(400);

        $this->assertSame($body, $response->json());
        Http::assertSentCount(1);
    }

    public function test_the_inventory_answer_is_returned_as_it_is(): void
    {
        $body = $this->envelope('inventory-service', null, 422, ['code' => 'FEED_REJECTED', 'message' => 'Rejected.', 'details' => ['accepted' => 0, 'rejected' => 1, 'errors' => [['row' => 1, 'code' => 'UNKNOWN_MEDICINE', 'message' => 'x']]]]);
        $this->fake(null, $body, 422);

        $response = $this->send(self::XML, 'application/xml', $this->as('branch_manager'), '?strict=true')->assertStatus(422);

        $this->assertSame($body, $response->json());
    }

    // ------------------------------------------------------------ branch scope

    public function test_a_manager_of_another_branch_is_403_and_inventory_is_not_called(): void
    {
        $this->fake($this->envelope('integration-service', $this->feed(2)));

        $this->send(self::XML, 'application/xml', $this->as('branch_manager', 1))->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN')->assertJsonPath('service', 'gateway');

        Http::assertSentCount(1); // only translate
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/inventory/sync'));
    }

    public function test_the_ceo_may_sync_any_branch(): void
    {
        $this->fake($this->envelope('integration-service', $this->feed(2)));

        $this->send(self::XML, 'application/xml', $this->as('ceo'))->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/inventory/sync') && $r->data()['branch_id'] === 2);
    }

    public function test_a_manager_without_a_branch_in_the_token_is_403(): void
    {
        $this->fake();

        $this->send(self::XML, 'application/xml', $this->bearer($this->token(['role' => 'branch_manager', 'branch_id' => null])))->assertStatus(403);
    }

    // ------------------------------------------------------------ headers

    public function test_the_forwarded_identity_comes_only_from_the_token(): void
    {
        $this->fake();

        $this->send(self::XML, 'application/xml', $this->as('branch_manager', 1) + ['X-User-Role' => 'ceo', 'X-User-Id' => '1', 'X-User-Branch' => '2', 'X-Internal-Key' => 'attacker', 'X-Request-Id' => 'client-sync-1'])->assertOk();

        Http::assertSent(fn (Request $r) => $r->header('X-User-Role') === ['branch_manager']
            && $r->header('X-User-Id') === ['9']
            && $r->header('X-User-Branch') === ['1']
            && $r->header('X-Internal-Key') === [$this->internalKey]
            && $r->header('X-Request-Id') === ['client-sync-1']
            && ! $r->hasHeader('Authorization'));
    }

    // ------------------------------------------------------------ failures

    public function test_a_service_down_is_503_and_a_timeout_is_504_for_either_step(): void
    {
        $message = 'cURL error 7: Failed to connect to integration.test';
        Http::fake(function () use (&$message) {
            throw new ConnectionException($message);
        });
        $this->send(self::XML, 'application/xml', $this->as('branch_manager'))->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');

        $message = 'cURL error 28: Operation timed out';
        $this->send(self::XML, 'application/xml', $this->as('branch_manager'))->assertStatus(504)->assertJsonPath('error.code', 'UPSTREAM_TIMEOUT');

        // the inventory step
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'integration.test')) {
                return Http::response($this->envelope('integration-service', $this->feed()));
            }
            throw new ConnectionException('cURL error 28: Operation timed out');
        });
        $this->send(self::XML, 'application/xml', $this->as('branch_manager'))->assertStatus(504);
    }

    public function test_a_non_envelope_translate_answer_is_502(): void
    {
        Http::fake(['integration.test/*' => Http::response('<html>Fatal /var/www/x.php</html>', 500)]);

        $response = $this->send(self::XML, 'application/xml', $this->as('branch_manager'))->assertStatus(502);

        $this->assertStringNotContainsString('/var/www', $response->getContent());
    }

    public function test_a_translate_answer_without_a_feed_is_502(): void
    {
        $this->fake($this->envelope('integration-service', ['unexpected' => true]));

        $this->send(self::XML, 'application/xml', $this->as('branch_manager'))->assertStatus(502)->assertJsonPath('error.code', 'BAD_UPSTREAM_RESPONSE');
    }

    // ------------------------------------------------------------ timeout and rate limit

    public function test_both_steps_use_the_sync_timeout_from_configuration(): void
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
        $this->fake();

        $this->send(self::XML, 'application/xml', $this->as('branch_manager'))->assertOk();
        $this->assertSame([10.0, 10.0], $spy->timeouts);

        $spy->timeouts = [];
        config(['services.integration.sync_timeout' => 25.0]);
        $this->send(self::XML, 'application/xml', $this->as('branch_manager'))->assertOk();
        $this->assertSame([25.0, 25.0], $spy->timeouts);
    }

    public function test_the_default_sync_timeout_is_ten_seconds(): void
    {
        $this->assertSame(10.0, (float) (include base_path('config/services.php'))['integration']['sync_timeout']);
    }

    public function test_the_route_allows_five_requests_a_minute_per_ip_counted_after_auth(): void
    {
        $this->fake();
        $headers = $this->as('branch_manager');

        foreach (range(1, 12) as $n) { // refused before the limiter: these must not use it up
            $this->send()->assertStatus(401);
            $this->send(self::XML, 'application/xml', $this->as('customer'))->assertStatus(403);
        }
        foreach (range(1, 5) as $n) {
            $this->send(self::XML, 'application/xml', $headers)->assertOk();
        }

        $response = $this->send(self::XML, 'application/xml', $headers)->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');

        $this->assertNotEmpty($response->headers->get('Retry-After'));
        Http::assertSentCount(10);
    }
}
