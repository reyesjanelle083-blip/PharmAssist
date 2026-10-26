<?php

namespace Tests\Feature;

use App\Services\ServiceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GatewayTestSetup;
use Tests\TestCase;

class PasswordResetRoutingTest extends TestCase
{
    use GatewayTestSetup;

    private const FORGOT = '/api/v1/auth/forgot-password';
    private const RESET = '/api/v1/auth/reset-password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGateway();
    }

    private function resetPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'user@pharmassist.test',
            'code' => '123456',
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ], $overrides);
    }

    private function identityError(int $status, string $code, string $message, mixed $details = null): array
    {
        return $this->downstream(null, $status, ['code' => $code, 'message' => $message, 'details' => $details]);
    }

    // ------------------------------------------------------------ both routes are public

    public function test_both_routes_work_without_a_token_and_relay_the_answer_unchanged(): void
    {
        $body = $this->downstream(null);
        Http::fake(['identity.test/*' => Http::response($body, 200)]);

        $forgot = $this->postJson(self::FORGOT, ['email' => 'user@pharmassist.test'])->assertOk();
        $reset = $this->postJson(self::RESET, $this->resetPayload())->assertOk();

        $this->assertSame($body, $forgot->json());
        $this->assertSame($body, $reset->json());
        Http::assertSentCount(2);
    }

    public function test_a_stale_or_bad_token_on_these_public_routes_does_not_matter_and_is_not_forwarded(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream(null), 200)]);

        $this->postJson(self::FORGOT, ['email' => 'user@pharmassist.test'], ['Authorization' => 'Bearer not.a.token'])->assertOk();

        Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization') && $r->header('X-Internal-Key') === [$this->internalKey]);
    }

    // ------------------------------------------------------------ what is forwarded

    public function test_forgot_password_forwards_only_the_email_as_json_with_the_internal_key_and_request_id(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream(null), 200)]);

        $response = $this->postJson(self::FORGOT, [
            'email' => 'user@pharmassist.test',
            'role' => 'ceo',            // must not be forwarded
            'code' => '123456',         // must not be forwarded
        ], ['X-Request-Id' => 'reset-req-0001'])->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === 'http://identity.test/internal/v1/auth/forgot-password'
            && $r->isJson()
            && $r->data() === ['email' => 'user@pharmassist.test']
            && $r->header('X-Internal-Key') === [$this->internalKey]
            && $r->header('X-Request-Id') === ['reset-req-0001']);
        $this->assertSame('reset-req-0001', $response->headers->get('X-Request-Id'));
    }

    public function test_reset_password_forwards_only_the_five_known_fields(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream(null), 200)]);

        $this->postJson(self::RESET, $this->resetPayload(['role' => 'ceo', 'user_id' => 1, 'is_active' => true, 'token' => 'abc']))->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === 'http://identity.test/internal/v1/auth/reset-password'
            && $r->isJson()
            && $r->data() === $this->resetPayload());
    }

    public function test_a_value_that_is_not_plain_text_is_dropped_so_identity_reports_it_missing(): void
    {
        $body = $this->identityError(422, 'VALIDATION_FAILED', 'The given data was invalid.', ['password' => ['The password field is required.']]);
        Http::fake(['identity.test/*' => Http::response($body, 422)]);

        $this->postJson(self::RESET, $this->resetPayload(['password' => ['x'], 'code' => ['1', '2']]))->assertStatus(422);

        Http::assertSent(fn (Request $r) => ! array_key_exists('password', $r->data()) && ! array_key_exists('code', $r->data()) && $r->data()['email'] === 'user@pharmassist.test');
    }

    // ------------------------------------------------------------ errors are relayed as they are

    public function test_reset_errors_are_relayed_with_their_status_and_body_unchanged(): void
    {
        $cases = [
            [422, 'INVALID_RESET_CODE', 'The reset code is invalid or has already been used.', null],
            [410, 'RESET_CODE_EXPIRED', 'The reset code has expired. Please request a new one.', null],
            [422, 'VALIDATION_FAILED', 'The given data was invalid.', ['password' => ['The password field must contain at least one number.']]],
        ];

        $current = null;
        Http::fake(function () use (&$current) { // one fake, answering with whichever case is current (a second Http::fake would not replace the first)
            return Http::response($current['body'], $current['status']);
        });

        foreach ($cases as [$status, $code, $message, $details]) {
            $body = $this->identityError($status, $code, $message, $details);
            $current = ['body' => $body, 'status' => $status];

            $response = $this->postJson(self::RESET, $this->resetPayload())->assertStatus($status);

            $this->assertSame($body, $response->json(), $code);
            $this->assertSame('identity-service', $response->json('service'));
        }
    }

    public function test_forgot_password_relays_a_validation_error(): void
    {
        $body = $this->identityError(422, 'VALIDATION_FAILED', 'The given data was invalid.', ['email' => ['The email field must be a valid email address.']]);
        Http::fake(['identity.test/*' => Http::response($body, 422)]);

        $this->assertSame($body, $this->postJson(self::FORGOT, ['email' => 'nope'])->assertStatus(422)->json());
    }

    public function test_identity_down_is_503_and_a_timeout_is_504_without_internal_details_and_without_a_retry(): void
    {
        $calls = 0;
        $exception = null;
        Http::fake(function () use (&$calls, &$exception) {
            $calls++;
            throw $exception;
        });

        foreach ([
            [new ConnectionException('cURL error 7: Failed to connect to identity.test port 80'), 503, 'SERVICE_UNAVAILABLE'],
            [new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'), 504, 'UPSTREAM_TIMEOUT'],
        ] as [$thrown, $status, $code]) {
            $calls = 0;
            $exception = $thrown;

            $response = $this->postJson(self::RESET, $this->resetPayload())->assertStatus($status)->assertJsonPath('error.code', $code)->assertJsonPath('service', 'gateway');

            $this->assertStringNotContainsString('identity.test', $response->getContent());
            $this->assertStringNotContainsString('cURL', $response->getContent());
            $this->assertSame(1, $calls, 'a failed POST is never retried');
        }
    }

    public function test_both_routes_get_the_longer_identity_budget_and_it_comes_from_configuration(): void
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
        Http::fake(['identity.test/*' => Http::response($this->downstream(null), 200)]);

        $this->postJson(self::FORGOT, ['email' => 'user@pharmassist.test'])->assertOk();
        $this->postJson(self::RESET, $this->resetPayload())->assertOk();
        $this->assertSame([10.0, 10.0], $spy->timeouts);

        config(['services.identity.login_timeout' => 6]);
        $spy->timeouts = [];
        $this->postJson(self::FORGOT, ['email' => 'user@pharmassist.test'])->assertOk();
        $this->assertSame([6.0], $spy->timeouts);
    }

    // ------------------------------------------------------------ rate limits

    public function test_forgot_password_is_limited_to_five_requests_a_minute_per_ip(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream(null), 200)]);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(self::FORGOT, ['email' => "u{$i}@pharmassist.test"])->assertOk();
        }

        $response = $this->postJson(self::FORGOT, ['email' => 'u6@pharmassist.test'])
            ->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')->assertJsonPath('service', 'gateway')->assertJsonPath('data', null);

        $this->assertNotEmpty($response->headers->get('Retry-After'));
        Http::assertSentCount(5); // the limited request never reached identity
    }

    public function test_reset_password_is_limited_to_ten_requests_a_minute_per_ip_and_separately_from_forgot(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->identityError(422, 'INVALID_RESET_CODE', 'The reset code is invalid or has already been used.'), 422)]);

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson(self::RESET, $this->resetPayload())->assertStatus(422);
        }

        $this->postJson(self::RESET, $this->resetPayload())->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
        $this->postJson(self::FORGOT, ['email' => 'user@pharmassist.test'])->assertStatus(422); // its own limit is untouched (the fake answers 422)
        Http::assertSentCount(11);
    }

    public function test_the_login_and_register_limits_are_not_shared_with_the_reset_routes(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream(null), 200)]);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(self::FORGOT, ['email' => "u{$i}@pharmassist.test"])->assertOk();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'a@pharmassist.test', 'password' => 'x'])->assertOk();
    }
}
