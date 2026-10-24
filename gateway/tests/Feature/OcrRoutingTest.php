<?php

namespace Tests\Feature;

use App\Services\ServiceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GatewayTestSetup;
use Tests\TestCase;

class OcrRoutingTest extends TestCase
{
    use GatewayTestSetup;

    private const URL = '/api/v1/prescriptions/ocr';

    // 1x1 PNG (the gateway does not inspect the image: integration-service validates it)
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGateway();
    }

    // ------------------------------------------------------------ helpers

    private function image(string $name = 'prescription.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function ocr(?UploadedFile $file = null, array $headers = [], array $extraFields = [])
    {
        return $this->post(self::URL, ['image' => $file ?? $this->image()] + $extraFields, $headers + ['Accept' => 'application/json']);
    }

    private function as(string $role): array
    {
        return $this->bearer($this->token(['role' => $role, 'branch_id' => $role === 'customer' ? null : 1]));
    }

    /** @param array<string, mixed> $data */
    private function integrationEnvelope(array $data = [], int $status = 200, array $warnings = [], ?array $error = null): array
    {
        return [
            'status' => $status,
            'service' => 'integration-service',
            'request_id' => 'ocr-downstream',
            'data' => $error ? null : ($data ?: ['ocr_text' => "Take 1 capsule\nthree times a day", 'confidence' => 0.91, 'ocr_status' => 'success', 'source' => 'tesseract']),
            'error' => $error,
            'warnings' => $warnings,
        ];
    }

    private function fakeIntegration(?array $envelope = null, int $status = 200): void
    {
        Http::fake(['integration.test/*' => Http::response($envelope ?? $this->integrationEnvelope(), $status)]);
    }

    // ------------------------------------------------------------ authentication and authorization

    public function test_no_token_returns_401_and_never_reaches_integration(): void
    {
        Http::fake();

        $this->ocr()->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED')->assertJsonPath('service', 'gateway');
        $this->ocr(null, $this->bearer($this->token([], str_repeat('x', 40))))->assertStatus(401); // forged token

        Http::assertNothingSent();
    }

    public function test_the_ceo_and_unknown_roles_are_forbidden(): void
    {
        Http::fake();

        $this->ocr(null, $this->as('ceo'))->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->ocr(null, $this->bearer($this->token(['role' => 'guest'])))->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_customer_and_branch_staff_roles_are_allowed(): void
    {
        $this->fakeIntegration();

        foreach (['customer', 'pharmacy_technician', 'pharmacy_assistant', 'branch_manager'] as $role) {
            $this->ocr(null, $this->as($role))->assertOk()->assertJsonPath('data.ocr_status', 'success');
        }

        Http::assertSentCount(4);
    }

    // ------------------------------------------------------------ forwarding

    public function test_the_image_is_forwarded_as_multipart_with_a_neutral_file_name_and_nothing_else(): void
    {
        $this->fakeIntegration();

        $this->ocr($this->image('patient-juan-delacruz-rx.png'), $this->as('customer'), ['role' => 'ceo', 'note' => 'extra'])->assertOk();

        Http::assertSent(function (Request $r) {
            $parts = $r->data();

            return $r->method() === 'POST'
                && $r->url() === 'http://integration.test/internal/v1/ocr/prescription'
                && $r->isMultipart()
                && array_column($parts, 'name') === ['image']                       // no other field is forwarded
                && $parts[0]['filename'] === 'upload.png'                           // the uploader's file name is not
                && ! str_contains(json_encode($parts[0]['headers'] ?? []), 'juan')
                && $parts[0]['contents'] === base64_decode(self::PNG);              // the bytes are forwarded unchanged
        });
    }

    public function test_the_forwarded_identity_comes_only_from_the_token(): void
    {
        $this->fakeIntegration();

        $this->ocr(null, $this->bearer($this->token(['sub' => '9', 'role' => 'pharmacy_assistant', 'branch_id' => 1])) + [
            'X-User-Role' => 'ceo', 'X-User-Id' => '1', 'X-User-Branch' => '2', 'X-Internal-Key' => 'attacker', 'X-Request-Id' => 'client-req-ocr-1',
        ])->assertOk();

        Http::assertSent(fn (Request $r) => $r->header('X-User-Role') === ['pharmacy_assistant']
            && $r->header('X-User-Id') === ['9']
            && $r->header('X-User-Branch') === ['1']
            && $r->header('X-Internal-Key') === [$this->internalKey]
            && $r->header('X-Request-Id') === ['client-req-ocr-1']
            && ! $r->hasHeader('Authorization'));
    }

    public function test_a_missing_image_returns_422_without_calling_integration(): void
    {
        Http::fake();

        $this->post(self::URL, [], $this->as('customer') + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonPath('service', 'gateway')
            ->assertJsonStructure(['error' => ['details' => ['image']]]);
        $this->post(self::URL, ['image' => 'just text'], $this->as('customer') + ['Accept' => 'application/json'])->assertStatus(422);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ relaying downstream answers

    public function test_an_unavailable_ocr_answer_is_relayed_unchanged_with_status_200(): void
    {
        $body = $this->integrationEnvelope(['ocr_text' => null, 'confidence' => null, 'ocr_status' => 'unavailable', 'source' => 'tesseract'], 200, ['OCR_UNAVAILABLE', 'OCR_BUSY']);
        $this->fakeIntegration($body);

        $response = $this->ocr(null, $this->as('customer'))->assertOk();

        $this->assertSame($body, $response->json());
    }

    public function test_a_downstream_422_for_a_bad_file_is_relayed(): void
    {
        $body = $this->integrationEnvelope([], 422, [], ['code' => 'VALIDATION_FAILED', 'message' => 'The given data was invalid.', 'details' => ['image' => ['The image must be a JPEG or PNG file.']]]);
        $this->fakeIntegration($body, 422);

        $this->ocr(null, $this->as('customer'))->assertStatus(422)->assertJsonPath('error.details.image.0', 'The image must be a JPEG or PNG file.');
    }

    // ------------------------------------------------------------ failures

    public function test_integration_down_returns_503_and_a_timeout_returns_504(): void
    {
        $message = 'cURL error 7: Failed to connect to integration.test port 80';
        Http::fake(function () use (&$message) {
            throw new ConnectionException($message);
        });

        $down = $this->ocr(null, $this->as('customer'))
            ->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')->assertJsonPath('service', 'gateway');
        $this->assertStringNotContainsString('integration.test', $down->getContent());

        $message = 'cURL error 28: Operation timed out after 20001 milliseconds';
        $slow = $this->ocr(null, $this->as('customer'))
            ->assertStatus(504)->assertJsonPath('error.code', 'UPSTREAM_TIMEOUT');
        $this->assertStringNotContainsString('integration.test', $slow->getContent());
    }

    public function test_a_non_envelope_answer_returns_502_without_details(): void
    {
        Http::fake(['integration.test/*' => Http::response('<html>Fatal error at /var/www/app.php</html>', 500)]);

        $response = $this->ocr(null, $this->as('customer'))->assertStatus(502)->assertJsonPath('error.code', 'BAD_UPSTREAM_RESPONSE');

        $this->assertStringNotContainsString('/var/www', $response->getContent());
    }

    public function test_a_failed_ocr_upload_is_not_retried(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->ocr(null, $this->as('customer'))->assertStatus(504);

        $this->assertSame(1, $calls);
    }

    // ------------------------------------------------------------ the per-route timeout

    public function test_only_the_ocr_route_gets_the_long_timeout(): void
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

        Http::fake([
            'integration.test/*' => Http::response($this->integrationEnvelope(), 200),
            'inventory.test/*' => Http::response(['status' => 200, 'service' => 'inventory-service', 'request_id' => 'x', 'data' => [], 'error' => null, 'warnings' => []], 200),
            'identity.test/*' => Http::response(['status' => 200, 'service' => 'identity-service', 'request_id' => 'x', 'data' => [], 'error' => null, 'warnings' => []], 200),
        ]);
        $staff = $this->as('branch_manager');

        $this->ocr(null, $this->as('customer'))->assertOk();                                                                   // OCR
        $this->getJson('/api/v1/medicines')->assertOk();                                                                       // catalog
        $this->getJson('/api/v1/branches/1/inventory', $staff)->assertOk();                                                    // inventory read
        $this->putJson('/api/v1/branches/1/inventory/1', ['stock_quantity' => 5, 'price' => '1.00', 'version' => 1], $staff)->assertOk(); // inventory write
        $this->postJson('/api/v1/auth/login', ['email' => 'a@pharmassist.test', 'password' => 'x'])->assertOk();               // login
        $this->getJson('/api/v1/auth/me', $this->as('customer'))->assertOk();                                                 // me

        $this->assertSame([20.0, 3.0, 3.0, 3.0, 3.0, 3.0], $spy->timeouts, 'OCR waits 20 s; every other route keeps 3 s');
    }

    public function test_the_ocr_timeout_comes_from_configuration(): void
    {
        $spy = new class extends ServiceClient
        {
            public ?float $last = null;

            protected function pending(float $timeoutSeconds): PendingRequest
            {
                $this->last = $timeoutSeconds;

                return parent::pending($timeoutSeconds);
            }
        };
        $this->app->instance(ServiceClient::class, $spy);
        $this->fakeIntegration();

        config(['services.integration.ocr_timeout' => 35.0]);
        $this->ocr(null, $this->as('customer'))->assertOk();

        $this->assertSame(35.0, $spy->last);
    }

    public function test_the_default_ocr_timeout_is_twenty_seconds(): void
    {
        $this->assertSame(20.0, (float) (include base_path('config/services.php'))['integration']['ocr_timeout']);
    }

    // ------------------------------------------------------------ rate limit

    public function test_the_ocr_route_allows_ten_requests_a_minute_per_ip(): void
    {
        $this->fakeIntegration();
        $headers = $this->as('customer');

        foreach (range(1, 10) as $n) {
            $this->ocr(null, $headers)->assertOk();
        }

        $response = $this->ocr(null, $headers)
            ->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')->assertJsonPath('service', 'gateway')->assertJsonPath('data', null);

        $this->assertNotEmpty($response->headers->get('Retry-After'));
        Http::assertSentCount(10); // the limited request never reached integration-service
    }

    public function test_requests_rejected_for_a_missing_token_or_role_do_not_use_up_the_rate_limit(): void
    {
        $this->fakeIntegration();

        foreach (range(1, 12) as $n) {
            $this->ocr()->assertStatus(401);
            $this->ocr(null, $this->as('ceo'))->assertStatus(403);
        }

        $this->ocr(null, $this->as('customer'))->assertOk();
    }
}
