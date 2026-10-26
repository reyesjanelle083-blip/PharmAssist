<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GatewayTestSetup;
use Tests\TestCase;

class ServiceClientTest extends TestCase
{
    use GatewayTestSetup;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGateway();
    }

    private function login(array $headers = [])
    {
        return $this->postJson('/api/v1/auth/login', ['email' => 'a@pharmassist.test', 'password' => 'Secret123'], $headers);
    }

    // ------------------------------------------------------------ header handling

    public function test_client_sent_identity_and_key_headers_are_not_forwarded_on_a_protected_route(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream($this->userData()), 200)]);

        $response = $this->getJson('/api/v1/auth/me', array_merge($this->bearer($this->token(['sub' => '7', 'role' => 'customer', 'branch_id' => null])), [
            'X-User-Role' => 'ceo',
            'X-User-Id' => '1',
            'X-User-Branch' => '99',
            'X-Internal-Key' => 'attacker-key',
            'X-Request-Id' => 'client-req-12345',
        ]))->assertOk();

        Http::assertSent(function (Request $r) {
            return $r->header('X-Internal-Key') === [$this->internalKey]
                && $r->header('X-User-Role') === ['customer']      // from the verified token, not "ceo"
                && $r->header('X-User-Id') === ['7']
                && $r->header('X-User-Branch') === ['']
                && $r->header('X-Request-Id') === ['client-req-12345']
                && ! $r->hasHeader('Authorization');                // the client's token is never forwarded
        });

        $this->assertSame('client-req-12345', $response->headers->get('X-Request-Id'));
    }

    public function test_staff_branch_comes_from_the_token(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream($this->userData()), 200)]);

        $this->getJson('/api/v1/auth/me', array_merge(
            $this->bearer($this->token(['sub' => '9', 'role' => 'branch_manager', 'branch_id' => 1])),
            ['X-User-Branch' => '2']
        ))->assertOk();

        Http::assertSent(fn (Request $r) => $r->header('X-User-Branch') === ['1'] && $r->header('X-User-Role') === ['branch_manager']);
    }

    public function test_spoofed_headers_on_a_public_route_are_dropped_and_no_identity_is_added(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream(['access_token' => 'x']), 200)]);

        $this->login([
            'X-User-Role' => 'ceo',
            'X-User-Id' => '1',
            'X-Internal-Key' => 'attacker-key',
            'Authorization' => 'Bearer '.$this->token(['role' => 'ceo']),
        ])->assertOk();

        Http::assertSent(fn (Request $r) => $r->header('X-Internal-Key') === [$this->internalKey]
            && ! $r->hasHeader('X-User-Role')
            && ! $r->hasHeader('X-User-Id')
            && ! $r->hasHeader('X-User-Branch')
            && ! $r->hasHeader('Authorization'));
    }

    // ------------------------------------------------------------ relaying

    public function test_downstream_envelope_and_status_are_relayed_unchanged(): void
    {
        $body = $this->downstream(null, 401, ['code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid email or password', 'details' => null]);
        Http::fake(['identity.test/*' => Http::response($body, 401)]);

        $response = $this->login()->assertStatus(401);

        $this->assertSame($body, $response->json());
        $this->assertSame('identity-service', $response->json('service'));
    }

    public function test_register_is_plain_json_without_an_id_photo_and_forwards_only_known_fields(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream($this->userData(), 201), 201)]);

        $this->postJson('/api/v1/auth/register', [
            'email' => 'new@pharmassist.test',
            'password' => 'Secret123',
            'first_name' => 'New',
            'last_name' => 'User',
            'contact_no' => '09171234567',
            'role' => 'ceo',                       // must not be forwarded
            'verification_status' => 'approved',   // must not be forwarded
            'branch_id' => 1,                      // must not be forwarded
        ])->assertStatus(201)->assertJsonPath('service', 'identity-service');

        Http::assertSent(function (Request $r) {
            return $r->url() === 'http://identity.test/internal/v1/auth/register'
                && ! $r->isMultipart()
                && $r->isJson()
                && $r->data() === [
                    'email' => 'new@pharmassist.test',
                    'password' => 'Secret123',
                    'first_name' => 'New',
                    'last_name' => 'User',
                    'contact_no' => '09171234567',
                ]
                && $r->header('X-Internal-Key') === [$this->internalKey];
        });
    }

    public function test_an_old_client_that_still_sends_an_id_photo_is_not_broken_and_the_file_is_not_forwarded(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream($this->userData(), 201), 201)]);

        $this->post('/api/v1/auth/register', [
            'email' => 'new@pharmassist.test',
            'password' => 'Secret123',
            'first_name' => 'New',
            'last_name' => 'User',
            'contact_no' => '09171234567',
            'id_photo' => UploadedFile::fake()->createWithContent('id.png', base64_decode(self::PNG_1X1)),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        Http::assertSent(fn (Request $r) => $r->url() === 'http://identity.test/internal/v1/auth/register'
            && ! $r->isMultipart()
            && ! array_key_exists('id_photo', $r->data()));
    }

    public function test_a_duplicate_email_is_relayed_unchanged_as_409(): void
    {
        $body = $this->downstream(null, 409, ['code' => 'EMAIL_EXISTS', 'message' => 'An account with this email already exists.', 'details' => null]);
        Http::fake(['identity.test/*' => Http::response($body, 409)]);

        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'taken@pharmassist.test', 'password' => 'Secret123', 'first_name' => 'A', 'last_name' => 'B', 'contact_no' => '09171234567',
        ])->assertStatus(409);

        $this->assertSame($body, $response->json());
    }

    // ------------------------------------------------------------ failures

    public function test_identity_down_returns_503_and_post_is_not_retried(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 7: Failed to connect to identity.test port 80');
        });

        $response = $this->login()
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('service', 'gateway');

        $this->assertStringNotContainsString('identity.test', $response->getContent());
        $this->assertStringNotContainsString('cURL', $response->getContent());
        $this->assertSame(1, $calls, 'A failed POST must never be retried.');
    }

    public function test_login_waits_for_the_configured_login_timeout_and_other_identity_routes_wait_three_seconds(): void
    {
        $spy = new class extends \App\Services\ServiceClient
        {
            /** @var list<float> */
            public array $timeouts = [];

            protected function pending(float $timeoutSeconds): \Illuminate\Http\Client\PendingRequest
            {
                $this->timeouts[] = $timeoutSeconds;

                return parent::pending($timeoutSeconds);
            }
        };
        $this->app->instance(\App\Services\ServiceClient::class, $spy);
        Http::fake(['identity.test/*' => Http::response($this->downstream(['access_token' => 'x']), 200)]);

        $this->login()->assertOk();
        $this->assertSame([10.0], $spy->timeouts, 'login defaults to 10 s, not the shared 3 s');

        $spy->timeouts = [];
        config(['services.identity.login_timeout' => 6]);
        $this->login()->assertOk();
        $this->getJson('/api/v1/auth/me', $this->bearer($this->token(['sub' => '7', 'role' => 'customer', 'branch_id' => null])))->assertOk();
        $this->assertSame([6.0, 3.0], $spy->timeouts);

        $this->assertSame(10.0, (float) (include base_path('config/services.php'))['identity']['login_timeout']);
    }

    public function test_a_login_that_times_out_is_a_504_envelope_without_internals(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));

        $raw = $this->login()->assertStatus(504)->assertJsonPath('error.code', 'UPSTREAM_TIMEOUT')->getContent();
        $this->assertStringNotContainsString('cURL', $raw);
        $this->assertStringNotContainsString('identity.test', $raw);
    }

    public function test_timeout_returns_504(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 3001 milliseconds'));

        $response = $this->login()
            ->assertStatus(504)
            ->assertJsonPath('error.code', 'UPSTREAM_TIMEOUT')
            ->assertJsonPath('service', 'gateway');

        $this->assertStringNotContainsString('identity.test', $response->getContent());
    }

    public function test_non_json_and_non_envelope_responses_return_502_without_details(): void
    {
        foreach ([
            Http::response('<html>Whoops, secret stack trace in /var/www/app.php</html>', 200),
            Http::response(['message' => 'Server error', 'trace' => ['/var/www/app.php']], 500),
            Http::response(['hello' => 'world'], 200),
            Http::response('', 204),
        ] as $downstream) {
            Http::fake(['identity.test/*' => $downstream]);

            $response = $this->login()
                ->assertStatus(502)
                ->assertJsonPath('error.code', 'BAD_UPSTREAM_RESPONSE')
                ->assertJsonPath('service', 'gateway');

            foreach (['secret', 'trace', '/var/www', 'identity.test', 'Whoops'] as $leak) {
                $this->assertStringNotContainsString($leak, $response->getContent());
            }
        }
    }

    public function test_a_missing_service_url_returns_503(): void
    {
        config(['services.identity.url' => null]);
        Http::fake();

        $this->login()->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
        Http::assertNothingSent();
    }
}
