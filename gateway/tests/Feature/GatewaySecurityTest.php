<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\GatewayTestSetup;
use Tests\TestCase;

class GatewaySecurityTest extends TestCase
{
    use GatewayTestSetup;

    /** Routes allowed to be reachable without a token. Everything else needs auth.jwt. */
    private const PUBLIC_ROUTES = [
        'internal/v1/health',
        'api/v1/auth/register',
        'api/v1/auth/verify-email',
        'api/v1/auth/login',
        'api/v1/medicines',
        'api/v1/medicines/categories',
        'api/v1/medicines/{id}',
        'api/v1/medicines/{id}/counseling',
        'api/v1/branches',
        'api/v1/availability',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGateway();
    }

    // ------------------------------------------------------------ default deny

    public function test_every_route_is_explicitly_public_or_behind_auth_jwt(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();

            $this->assertTrue(
                in_array($uri, self::PUBLIC_ROUTES, true) || in_array('auth.jwt', $middleware, true),
                "Route [{$uri}] is neither on the public allow-list nor protected by auth.jwt."
            );
        }
    }

    public function test_framework_pages_are_not_exposed(): void
    {
        foreach (['/', '/up', '/storage/anything'] as $path) {
            $this->get($path, ['Accept' => 'application/json'])
                ->assertStatus(404)->assertJsonPath('service', 'gateway')->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/internal/v1/health')->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('service', 'gateway');
    }

    // ------------------------------------------------------------ rate limiting

    public function test_login_is_limited_to_ten_requests_per_minute_per_ip(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream(['access_token' => 'x']), 200)]);

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'a@pharmassist.test', 'password' => 'x'])->assertOk();
        }

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'a@pharmassist.test', 'password' => 'x'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')
            ->assertJsonPath('service', 'gateway')
            ->assertJsonPath('data', null);

        $this->assertNotEmpty($response->headers->get('Retry-After'));
        Http::assertSentCount(10); // the limited request never reached identity
    }

    public function test_register_is_limited_to_five_requests_per_minute_per_ip(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream($this->userData(), 201), 201)]);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/auth/register', ['email' => "u$i@pharmassist.test"])->assertStatus(201);
        }
        $this->postJson('/api/v1/auth/register', ['email' => 'u6@pharmassist.test'])
            ->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    // ------------------------------------------------------------ CORS

    public function test_cors_is_limited_to_the_configured_origins_and_never_a_wildcard(): void
    {
        $patterns = config('cors.allowed_origins_patterns');
        $this->assertCount(1, $patterns);
        $this->assertSame(1, preg_match($patterns[0], 'http://localhost:5173'));
        foreach (['http://localhost:5173.evil.example', 'http://evil.example', 'https://localhost:5173', 'http://localhost:51730'] as $other) {
            $this->assertSame(0, preg_match($patterns[0], $other), $other);
        }
        $this->assertSame([], config('cors.allowed_origins')); // never a list that could contain *
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_preflight_from_an_unknown_origin_gets_no_allow_origin_header(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_preflight_from_the_allowed_origin_is_accepted(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type,idempotency-key',
        ]);

        $this->assertSame('http://localhost:5173', $response->headers->get('Access-Control-Allow-Origin'));
        $allowed = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        foreach (['authorization', 'content-type', 'idempotency-key'] as $header) {
            $this->assertStringContainsString($header, $allowed);
        }
    }

    public function test_actual_requests_only_get_cors_headers_for_the_allowed_origin_and_expose_the_request_id(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream(['access_token' => 'x']), 200)]);
        $body = ['email' => 'a@pharmassist.test', 'password' => 'x'];

        $evil = $this->postJson('/api/v1/auth/login', $body, ['Origin' => 'http://evil.example']);
        $this->assertNull($evil->headers->get('Access-Control-Allow-Origin'));

        $good = $this->postJson('/api/v1/auth/login', $body, ['Origin' => 'http://localhost:5173']);
        $this->assertSame('http://localhost:5173', $good->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('X-Request-Id', (string) $good->headers->get('Access-Control-Expose-Headers'));
    }

    // ------------------------------------------------------------ errors never leak

    public function test_404_and_405_use_the_gateway_envelope(): void
    {
        $this->getJson('/api/v1/nope')->assertStatus(404)
            ->assertJsonPath('service', 'gateway')->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonStructure(['status', 'service', 'request_id', 'data', 'error', 'warnings']);

        $this->postJson('/internal/v1/health')->assertStatus(405)
            ->assertJsonPath('service', 'gateway')->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    }

    public function test_a_500_leaks_no_trace_path_or_internal_key_even_with_debug_on(): void
    {
        config(['app.debug' => true]);

        Route::get('/api/v1/_test/boom', function () {
            throw new RuntimeException('failure with key '.config('pharmassist.internal_key').' at C:\\secret\\path.php');
        })->middleware('auth.jwt');

        $response = $this->getJson('/api/v1/_test/boom', $this->bearer())
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertJsonPath('service', 'gateway');

        foreach ([$this->internalKey, 'RuntimeException', 'secret', '.php', 'trace', 'vendor'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }
}
