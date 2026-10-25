<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GatewayTestSetup;
use Tests\TestCase;

class InventoryRoutingTest extends TestCase
{
    use GatewayTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGateway();
    }

    private function inventoryEnvelope(mixed $data = ['ok' => true], int $status = 200, ?array $error = null): array
    {
        return ['status' => $status, 'service' => 'inventory-service', 'request_id' => 'inv-req', 'data' => $data, 'error' => $error, 'warnings' => []];
    }

    private function fakeInventory(mixed $data = ['ok' => true], int $status = 200): void
    {
        Http::fake(['inventory.test/*' => Http::response($this->inventoryEnvelope($data, $status), $status)]);
    }

    private function updateBody(): array
    {
        return ['stock_quantity' => 55, 'price' => '12.50', 'min_threshold' => 20, 'batch_no' => 'B-1', 'expiration_date' => '2028-01-31', 'version' => 1];
    }

    // ------------------------------------------------------------ public catalog

    public function test_public_catalog_routes_need_no_token_and_send_no_user_identity(): void
    {
        $this->fakeInventory(['items' => [], 'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);

        $this->getJson('/api/v1/medicines')->assertOk()->assertJsonPath('service', 'inventory-service');
        $this->getJson('/api/v1/medicines/5')->assertOk();
        $this->getJson('/api/v1/branches')->assertOk();

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r->header('X-Internal-Key') === [$this->internalKey]
            && ! $r->hasHeader('X-User-Id') && ! $r->hasHeader('X-User-Role') && ! $r->hasHeader('X-User-Branch')
            && ! $r->hasHeader('Authorization'));
    }

    public function test_medicine_search_forwards_only_the_documented_filters(): void
    {
        $this->fakeInventory();

        $this->getJson('/api/v1/medicines?q=amox&category=Antibiotics&rx=true&page=2&per_page=5&admin=1&branch_id=9')->assertOk();

        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            return str_starts_with($r->url(), 'http://inventory.test/internal/v1/medicines?')
                && $query === ['q' => 'amox', 'category' => 'Antibiotics', 'rx' => 'true', 'page' => '2', 'per_page' => '5'];
        });
    }

    public function test_a_public_route_ignores_spoofed_identity_headers_from_the_client(): void
    {
        $this->fakeInventory();

        $this->getJson('/api/v1/branches', ['X-User-Role' => 'ceo', 'X-User-Branch' => '1', 'X-Internal-Key' => 'attacker', 'Authorization' => 'Bearer '.$this->token(['role' => 'ceo'])])->assertOk();

        Http::assertSent(fn (Request $r) => $r->header('X-Internal-Key') === [$this->internalKey]
            && ! $r->hasHeader('X-User-Role') && ! $r->hasHeader('X-User-Branch') && ! $r->hasHeader('Authorization'));
    }

    public function test_counseling_and_categories_are_public_and_go_to_the_matching_internal_routes(): void
    {
        $this->fakeInventory(['medicine_id' => 5, 'summary' => 'Take with food.', 'how_to_use' => null, 'warnings' => null, 'source' => 'internal']);

        $this->getJson('/api/v1/medicines/5/counseling')->assertOk()->assertJsonPath('service', 'inventory-service')->assertJsonPath('data.summary', 'Take with food.');
        $this->getJson('/api/v1/medicines/categories')->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://inventory.test/internal/v1/medicines/5/counseling'
            && $r->header('X-Internal-Key') === [$this->internalKey] && ! $r->hasHeader('X-User-Id') && ! $r->hasHeader('Authorization'));
        Http::assertSent(fn (Request $r) => $r->url() === 'http://inventory.test/internal/v1/medicines/categories');
    }

    public function test_counseling_for_an_unknown_medicine_relays_the_404_unchanged(): void
    {
        $body = $this->inventoryEnvelope(null, 404, ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.', 'details' => null]);
        Http::fake(['inventory.test/*' => Http::response($body, 404)]);

        $this->assertSame($body, $this->getJson('/api/v1/medicines/9999/counseling')->assertStatus(404)->json());
    }

    public function test_counseling_with_a_non_numeric_id_never_reaches_the_service(): void
    {
        Http::fake();

        $this->getJson('/api/v1/medicines/abc/counseling')->assertStatus(404)->assertJsonPath('service', 'gateway');
        $this->getJson('/api/v1/medicines/1%2F..%2Fadmin/counseling')->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_counseling_reports_an_unavailable_inventory_service_cleanly(): void
    {
        Http::fake(['inventory.test/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to inventory.test port 80')]);

        $response = $this->getJson('/api/v1/medicines/5/counseling')->assertStatus(503);

        $this->assertSame('gateway', $response->json('service'));
        $this->assertStringNotContainsString('cURL', $response->getContent());
    }

    public function test_downstream_errors_are_relayed_unchanged(): void
    {
        $body = $this->inventoryEnvelope(null, 404, ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.', 'details' => null]);
        Http::fake(['inventory.test/*' => Http::response($body, 404)]);

        $response = $this->getJson('/api/v1/medicines/9999')->assertStatus(404);

        $this->assertSame($body, $response->json());
    }

    public function test_non_numeric_ids_never_reach_the_downstream_service(): void
    {
        Http::fake();

        $this->getJson('/api/v1/medicines/abc')->assertStatus(404)->assertJsonPath('service', 'gateway');
        $this->getJson('/api/v1/medicines/1%2F..%2Fadmin')->assertStatus(404);
        $this->getJson('/api/v1/branches/x/inventory', $this->bearer($this->token(['role' => 'ceo'])))->assertStatus(404);
        $this->putJson('/api/v1/branches/1/inventory/x', $this->updateBody(), $this->bearer($this->token(['role' => 'branch_manager', 'branch_id' => 1])))->assertStatus(404);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ authentication and authorization

    public function test_inventory_routes_return_401_without_a_token(): void
    {
        Http::fake();

        $this->getJson('/api/v1/branches/1/inventory')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED')->assertJsonPath('service', 'gateway');
        $this->putJson('/api/v1/branches/1/inventory/1', $this->updateBody())->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->getJson('/api/v1/branches/1/inventory', $this->bearer($this->token([], str_repeat('x', 40))))->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_a_customer_token_on_inventory_returns_403(): void
    {
        Http::fake();
        $customer = $this->bearer($this->token(['role' => 'customer']));

        $this->getJson('/api/v1/branches/1/inventory', $customer)->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->putJson('/api/v1/branches/1/inventory/1', $this->updateBody(), $customer)->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

        Http::assertNothingSent();
    }

    public function test_the_update_route_is_limited_to_technician_and_branch_manager(): void
    {
        $this->fakeInventory();

        foreach (['pharmacy_assistant', 'ceo'] as $role) {
            $this->putJson('/api/v1/branches/1/inventory/1', $this->updateBody(), $this->bearer($this->token(['role' => $role, 'branch_id' => 1])))
                ->assertStatus(403);
        }
        Http::assertNothingSent();

        foreach (['pharmacy_technician', 'branch_manager'] as $role) {
            $this->putJson('/api/v1/branches/1/inventory/1', $this->updateBody(), $this->bearer($this->token(['role' => $role, 'branch_id' => 1])))
                ->assertOk();
        }
    }

    public function test_all_staff_roles_may_read_inventory(): void
    {
        $this->fakeInventory(['items' => [], 'meta' => []]);

        foreach (['pharmacy_technician', 'pharmacy_assistant', 'branch_manager', 'ceo'] as $role) {
            $this->getJson('/api/v1/branches/1/inventory', $this->bearer($this->token(['role' => $role, 'branch_id' => $role === 'ceo' ? null : 1])))->assertOk();
        }
        Http::assertSentCount(4);
    }

    // ------------------------------------------------------------ forwarding

    public function test_a_technician_put_returns_200_and_forwards_the_body_to_inventory(): void
    {
        $this->fakeInventory(['id' => 1, 'version' => 2]);

        $this->putJson('/api/v1/branches/1/inventory/12', $this->updateBody(), $this->bearer($this->token(['sub' => '9', 'role' => 'pharmacy_technician', 'branch_id' => 1])))
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('service', 'inventory-service');

        Http::assertSent(function (Request $r) {
            return $r->method() === 'PUT'
                && $r->url() === 'http://inventory.test/internal/v1/branches/1/inventory/12'
                && $r->data() === $this->updateBody();
        });
    }

    public function test_the_put_body_is_limited_to_the_editable_fields(): void
    {
        $this->fakeInventory();

        $this->putJson('/api/v1/branches/1/inventory/12', $this->updateBody() + ['reserved_quantity' => 0, 'status' => 'available', 'branch_id' => 2], $this->bearer($this->token(['role' => 'branch_manager', 'branch_id' => 1])))
            ->assertOk();

        Http::assertSent(fn (Request $r) => array_keys($r->data()) === array_keys($this->updateBody()));
    }

    public function test_forwarded_identity_comes_only_from_the_token_not_from_client_headers(): void
    {
        $this->fakeInventory();

        $this->putJson('/api/v1/branches/1/inventory/12', $this->updateBody(), array_merge(
            $this->bearer($this->token(['sub' => '9', 'role' => 'pharmacy_technician', 'branch_id' => 1])),
            ['X-User-Branch' => '2', 'X-User-Role' => 'ceo', 'X-User-Id' => '1', 'X-Internal-Key' => 'attacker', 'X-Request-Id' => 'client-req-77777']
        ))->assertOk();

        Http::assertSent(fn (Request $r) => $r->header('X-User-Branch') === ['1']          // from the token, not "2"
            && $r->header('X-User-Role') === ['pharmacy_technician']                        // not "ceo"
            && $r->header('X-User-Id') === ['9']                                            // not "1"
            && $r->header('X-Internal-Key') === [$this->internalKey]                        // not the attacker's
            && $r->header('X-Request-Id') === ['client-req-77777']
            && ! $r->hasHeader('Authorization'));
    }

    public function test_a_ceo_token_forwards_an_empty_branch(): void
    {
        $this->fakeInventory();

        $this->getJson('/api/v1/branches/3/inventory?status=low_stock&per_page=5&junk=1', array_merge(
            $this->bearer($this->token(['sub' => '1', 'role' => 'ceo', 'branch_id' => null])),
            ['X-User-Branch' => '3']
        ))->assertOk();

        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            return str_starts_with($r->url(), 'http://inventory.test/internal/v1/branches/3/inventory?')
                && $query === ['status' => 'low_stock', 'per_page' => '5']
                && $r->header('X-User-Branch') === ['']
                && $r->header('X-User-Role') === ['ceo'];
        });
    }

    public function test_the_downstream_403_for_another_branch_is_relayed(): void
    {
        $body = $this->inventoryEnvelope(null, 403, ['code' => 'FORBIDDEN', 'message' => 'You are not allowed to perform this action.', 'details' => null]);
        Http::fake(['inventory.test/*' => Http::response($body, 403)]);

        $this->getJson('/api/v1/branches/2/inventory', $this->bearer($this->token(['role' => 'pharmacy_technician', 'branch_id' => 1])))
            ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN')->assertJsonPath('service', 'inventory-service');
    }

    // ------------------------------------------------------------ failures

    public function test_inventory_down_returns_503_for_public_and_staff_routes(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to inventory.test port 80'));

        $public = $this->getJson('/api/v1/medicines')
            ->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')->assertJsonPath('service', 'gateway');
        $staff = $this->putJson('/api/v1/branches/1/inventory/1', $this->updateBody(), $this->bearer($this->token(['role' => 'branch_manager', 'branch_id' => 1])))
            ->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');

        foreach ([$public, $staff] as $response) {
            $this->assertStringNotContainsString('inventory.test', $response->getContent());
        }
    }

    public function test_a_timeout_returns_504(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 3001 milliseconds'));

        $this->getJson('/api/v1/branches')->assertStatus(504)->assertJsonPath('error.code', 'UPSTREAM_TIMEOUT')->assertJsonPath('service', 'gateway');
    }

    public function test_a_non_envelope_response_returns_502_without_details(): void
    {
        Http::fake(['inventory.test/*' => Http::response('<html>Fatal error in /var/www/app.php</html>', 500)]);
        $response = $this->getJson('/api/v1/branches')->assertStatus(502)->assertJsonPath('error.code', 'BAD_UPSTREAM_RESPONSE');
        $this->assertStringNotContainsString('/var/www', $response->getContent());
    }

    public function test_a_failed_put_is_not_retried(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 7: refused');
        });

        $this->putJson('/api/v1/branches/1/inventory/1', $this->updateBody(), $this->bearer($this->token(['role' => 'pharmacy_technician', 'branch_id' => 1])))->assertStatus(503);

        $this->assertSame(1, $calls);
    }
}
