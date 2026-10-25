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

class ReservationRoutingTest extends TestCase
{
    use GatewayTestSetup;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private const BODY = ['branch_id' => 1, 'items' => [['medicine_id' => 1, 'quantity' => 2], ['medicine_id' => 3, 'quantity' => 1]], 'notes' => 'after 5 pm'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGateway();
    }

    // ------------------------------------------------------------ helpers

    private function as(string $role, int $id = 9, ?int $branch = null, array $extra = []): array
    {
        return $this->bearer($this->token(['sub' => (string) $id, 'role' => $role, 'branch_id' => in_array($role, ['customer', 'ceo'], true) ? null : ($branch ?? 1)])) + $extra;
    }

    private function customer(array $extra = []): array
    {
        return $this->as('customer', 9, null, ['Idempotency-Key' => 'key-0000-0001'] + $extra);
    }

    private function envelope(mixed $data, int $status = 200, ?array $error = null): array
    {
        return ['status' => $status, 'service' => 'reservation-service', 'request_id' => 'down-1', 'data' => $error ? null : $data, 'error' => $error, 'warnings' => []];
    }

    private function fake(?array $body = null, int $status = 200): void
    {
        Http::fake(['reservation.test/*' => Http::response($body ?? $this->envelope(['id' => 1, 'status' => 'pending']), $status)]);
    }

    private function png(string $name = 'patient-juan.png', ?string $content = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content ?? base64_decode(self::PNG));
    }

    /** @return list<Request> */
    private function sentRequests(): array
    {
        return array_map(fn ($pair) => $pair[0], Http::recorded()->all());
    }

    private function postJsonReservation(array $headers = null, array $body = self::BODY)
    {
        return $this->postJson('/api/v1/reservations', $body, $headers ?? $this->customer());
    }

    // ------------------------------------------------------------ authentication and roles

    public function test_every_route_needs_a_token(): void
    {
        Http::fake();
        $accept = ['Accept' => 'application/json'];

        $this->postJson('/api/v1/reservations', self::BODY, $accept + ['Idempotency-Key' => 'key-0000-0001'])->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->getJson('/api/v1/reservations', $accept)->assertStatus(401);
        $this->getJson('/api/v1/reservations/1', $accept)->assertStatus(401);
        $this->patchJson('/api/v1/reservations/1/status', ['action' => 'approve'], $accept)->assertStatus(401);
        $this->getJson('/api/v1/reservations/1/prescription', $accept)->assertStatus(401);
        $this->getJson('/api/v1/reservations', $accept + $this->bearer($this->token([], str_repeat('x', 40))))->assertStatus(401); // forged token

        Http::assertNothingSent();
    }

    public function test_only_customers_can_create(): void
    {
        Http::fake();

        foreach (['pharmacy_technician', 'pharmacy_assistant', 'branch_manager', 'ceo', 'guest'] as $role) {
            $this->postJsonReservation($this->as($role, 9, 1, ['Idempotency-Key' => 'key-0000-0001']))->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN')->assertJsonPath('service', 'gateway');
        }

        Http::assertNothingSent();
    }

    public function test_the_ceo_cannot_change_a_status_and_unknown_roles_are_refused_everywhere(): void
    {
        Http::fake();

        $this->patchJson('/api/v1/reservations/1/status', ['action' => 'approve'], $this->as('ceo'))->assertStatus(403);
        $this->patchJson('/api/v1/reservations/1/status', ['action' => 'approve'], $this->as('guest'))->assertStatus(403);
        $this->getJson('/api/v1/reservations', $this->as('guest'))->assertStatus(403);
        $this->getJson('/api/v1/reservations/1', $this->as('guest'))->assertStatus(403);
        $this->getJson('/api/v1/reservations/1/prescription', $this->as('guest'))->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_the_gateway_allows_every_role_it_should_and_leaves_the_rest_to_the_service(): void
    {
        $this->fake();

        foreach (['customer', 'pharmacy_technician', 'pharmacy_assistant', 'branch_manager', 'ceo'] as $role) {
            $this->getJson('/api/v1/reservations', $this->as($role))->assertOk();
            $this->getJson('/api/v1/reservations/1', $this->as($role))->assertOk();
        }
        // A customer asking to approve is decided by the service, not the gateway.
        foreach (['customer', 'pharmacy_technician', 'pharmacy_assistant', 'branch_manager'] as $role) {
            $this->patchJson('/api/v1/reservations/1/status', ['action' => 'approve'], $this->as($role))->assertOk();
        }
        Http::assertSentCount(14);
    }

    // ------------------------------------------------------------ POST /reservations

    public function test_json_is_forwarded_with_the_idempotency_key_and_only_documented_fields(): void
    {
        $body = $this->envelope(['id' => 5, 'claim_code' => 'PA-7K3Q9X2M', 'status' => 'pending'], 201);
        $this->fake($body, 201);

        $response = $this->postJsonReservation($this->customer(), array_replace(self::BODY, ['user_id' => 1, 'status' => 'approved', 'items' => [['medicine_id' => 1, 'quantity' => 2, 'unit_price' => '0.01', 'extra' => 'x']]]))
            ->assertStatus(201);

        $this->assertSame($body, $response->json(), 'the downstream envelope and status are relayed unchanged');

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && $r->url() === 'http://reservation.test/internal/v1/reservations'
                && $r->header('Idempotency-Key') === ['key-0000-0001']
                && $r->data() == ['branch_id' => 1, 'items' => [['medicine_id' => 1, 'quantity' => 2]], 'notes' => 'after 5 pm'];
        });
    }

    public function test_the_forwarded_identity_comes_only_from_the_token(): void
    {
        $this->fake();

        $this->postJsonReservation($this->as('customer', 9, null, [
            'Idempotency-Key' => 'key-0000-0001',
            'X-User-Role' => 'ceo', 'X-User-Id' => '1', 'X-User-Branch' => '2', 'X-Internal-Key' => 'attacker', 'X-Request-Id' => 'client-res-1', 'X-Evil' => 'yes',
        ]))->assertOk();

        Http::assertSent(fn (Request $r) => $r->header('X-User-Role') === ['customer']
            && $r->header('X-User-Id') === ['9']
            && $r->header('X-User-Branch') === ['']
            && $r->header('X-Internal-Key') === [$this->internalKey]
            && $r->header('X-Request-Id') === ['client-res-1']
            && ! $r->hasHeader('Authorization')
            && ! $r->hasHeader('X-Evil'));
    }

    public function test_the_idempotency_key_is_required(): void
    {
        Http::fake();

        $this->postJsonReservation($this->as('customer'))->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED')->assertJsonPath('service', 'gateway');
        $this->postJsonReservation($this->as('customer', 9, null, ['Idempotency-Key' => '   ']))->assertStatus(400);

        Http::assertNothingSent();
    }

    public function test_a_multipart_reservation_forwards_nested_items_and_the_file_with_a_neutral_name(): void
    {
        $this->fake($this->envelope(['id' => 6], 201), 201);

        $this->post('/api/v1/reservations', self::BODY + ['prescription' => $this->png(), 'user_id' => 1, 'status' => 'approved'], $this->customer(['Accept' => 'application/json']))
            ->assertStatus(201);

        Http::assertSent(function (Request $r) {
            $parts = collect($r->data())->keyBy('name');

            return $r->isMultipart()
                && $r->url() === 'http://reservation.test/internal/v1/reservations'
                && $r->header('Idempotency-Key') === ['key-0000-0001']
                && $parts->keys()->sort()->values()->all() === collect(['branch_id', 'items[0][medicine_id]', 'items[0][quantity]', 'items[1][medicine_id]', 'items[1][quantity]', 'notes', 'prescription'])->sort()->values()->all()
                && (string) $parts['items[0][medicine_id]']['contents'] === '1'
                && (string) $parts['items[1][quantity]']['contents'] === '1'
                && $parts['prescription']['filename'] === 'upload.png'
                && $parts['prescription']['contents'] === base64_decode(self::PNG)
                && ! str_contains(json_encode($parts['prescription']['headers'] ?? []), 'juan');
        });
    }

    public function test_a_multipart_request_without_a_file_works(): void
    {
        $this->fake($this->envelope(['id' => 6], 201), 201);

        $this->post('/api/v1/reservations', self::BODY, $this->customer(['Accept' => 'application/json']))->assertStatus(201);

        // without a file there is nothing to make multipart: the fields go as a form, items nested the same way
        Http::assertSent(function (Request $r) {
            parse_str($r->body(), $form); // what reservation-service's PHP will see

            return $r->isForm() && ($form['items'][1]['medicine_id'] ?? null) === '3' && ($form['items'][0]['quantity'] ?? null) === '2' && $form['branch_id'] === '1' && ! isset($form['prescription']);
        });
    }

    public function test_the_four_megabyte_limit_is_enforced_at_the_gateway(): void
    {
        $this->fake($this->envelope(['id' => 6], 201), 201);
        $headers = $this->customer(['Accept' => 'application/json']);

        $this->post('/api/v1/reservations', self::BODY + ['prescription' => UploadedFile::fake()->create('rx.png', 4096)], $headers)->assertStatus(201); // exactly 4 MB is allowed
        Http::assertSentCount(1);

        $this->post('/api/v1/reservations', self::BODY + ['prescription' => UploadedFile::fake()->create('rx.png', 4097)], $headers)
            ->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE')->assertJsonPath('service', 'gateway');
        Http::assertSentCount(1); // the big one never left the gateway

        // PHP itself refusing the upload (its own upload_max_filesize) is the same answer
        $path = tempnam(sys_get_temp_dir(), 'rx');
        $refused = new UploadedFile($path, 'rx.png', null, UPLOAD_ERR_INI_SIZE, true);
        $this->post('/api/v1/reservations', self::BODY + ['prescription' => $refused], $headers)->assertStatus(413);
        Http::assertSentCount(1);
    }

    public function test_other_content_types_and_a_non_file_prescription_are_refused_without_calling_the_service(): void
    {
        Http::fake();

        $this->call('POST', '/api/v1/reservations', [], [], [], $this->transformHeadersToServerVars($this->customer(['Accept' => 'application/json']) + ['CONTENT_TYPE' => 'text/plain']), 'hello')
            ->assertStatus(415)->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
        $this->post('/api/v1/reservations', self::BODY + ['prescription' => 'just text'], $this->customer(['Accept' => 'application/json']))
            ->assertStatus(422)->assertJsonPath('error.details.prescription.0', 'The prescription must be a file.');

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ GET routes

    public function test_the_list_forwards_only_the_documented_filters(): void
    {
        $this->fake($this->envelope(['items' => [], 'meta' => ['page' => 1]]));

        $this->getJson('/api/v1/reservations?status=pending&page=2&per_page=5&branch_id=3&user_id=99&x[]=1&status2=a', $this->as('ceo'))->assertOk();
        $this->getJson('/api/v1/reservations?status[]=pending&page=2', $this->as('ceo'))->assertOk(); // a non-scalar filter is dropped

        $sent = $this->sentRequests();
        parse_str(parse_url($sent[0]->url(), PHP_URL_QUERY), $first);
        parse_str((string) parse_url($sent[1]->url(), PHP_URL_QUERY), $second);
        $this->assertSame(['status' => 'pending', 'page' => '2', 'per_page' => '5', 'branch_id' => '3'], $first);
        $this->assertSame(['page' => '2'], $second);
        $this->assertSame('GET', $sent[0]->method());
        $this->assertStringStartsWith('http://reservation.test/internal/v1/reservations?', $sent[0]->url());
    }

    public function test_show_and_status_ids_must_be_numeric_and_are_checked_before_the_service(): void
    {
        Http::fake();

        foreach (['abc', '1.5', '1%20or%201', '-1'] as $id) {
            $this->getJson("/api/v1/reservations/{$id}", $this->as('customer'))->assertStatus(404);
            $this->patchJson("/api/v1/reservations/{$id}/status", ['action' => 'approve'], $this->as('pharmacy_technician'))->assertStatus(404);
            $this->getJson("/api/v1/reservations/{$id}/prescription", $this->as('customer'))->assertStatus(404);
        }
        $this->getJson('/api/v1/reservations/abc')->assertStatus(404); // not even a token is needed to learn that

        Http::assertNothingSent();
    }

    public function test_show_relays_the_envelope_and_status_unchanged(): void
    {
        $body = $this->envelope(null, 404, ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.', 'details' => null]);
        $this->fake($body, 404);

        $this->assertSame($body, $this->getJson('/api/v1/reservations/77', $this->as('customer'))->assertStatus(404)->json());
        Http::assertSent(fn (Request $r) => $r->url() === 'http://reservation.test/internal/v1/reservations/77');
    }

    // ------------------------------------------------------------ PATCH status

    public function test_the_status_change_forwards_only_action_reason_and_claim_code(): void
    {
        $body = $this->envelope(['id' => 4, 'status' => 'approved']);
        $this->fake($body);

        $this->patchJson('/api/v1/reservations/4/status', ['action' => 'complete', 'reason' => 'ok', 'claim_code' => 'PA-7K3Q9X2M', 'status' => 'completed', 'user_id' => 1, 'branch_id' => 2], $this->as('pharmacy_assistant', 20, 1, ['X-User-Role' => 'ceo', 'X-Internal-Key' => 'attacker']))
            ->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->url() === 'http://reservation.test/internal/v1/reservations/4/status'
            && $r->data() === ['action' => 'complete', 'reason' => 'ok', 'claim_code' => 'PA-7K3Q9X2M']
            && $r->header('X-User-Role') === ['pharmacy_assistant']
            && $r->header('X-User-Id') === ['20']
            && $r->header('X-User-Branch') === ['1']
            && $r->header('X-Internal-Key') === [$this->internalKey]
            && ! $r->hasHeader('Idempotency-Key'));
    }

    public function test_service_errors_are_relayed_unchanged(): void
    {
        $answer = null;
        Http::fake(function () use (&$answer) {
            return Http::response($answer['body'], $answer['status']);
        });

        foreach ([
            [409, ['code' => 'INVALID_TRANSITION', 'message' => 'x', 'details' => ['current_status' => 'approved']]],
            [422, ['code' => 'INVALID_CLAIM_CODE', 'message' => 'x', 'details' => null]],
            [403, ['code' => 'FORBIDDEN', 'message' => 'x', 'details' => null]],
        ] as [$status, $error]) {
            $answer = ['status' => $status, 'body' => $this->envelope(null, $status, $error)];

            $this->assertSame($answer['body'], $this->patchJson('/api/v1/reservations/4/status', ['action' => 'approve'], $this->as('pharmacy_technician'))->assertStatus($status)->json());
        }
    }

    // ------------------------------------------------------------ binary stream

    public function test_the_prescription_image_is_streamed_with_its_headers(): void
    {
        $png = base64_decode(self::PNG);
        Http::fake(['reservation.test/*' => Http::response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename=prescription.png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ])]);

        $response = $this->get('/api/v1/reservations/12/prescription', $this->as('pharmacy_technician') + ['Accept' => 'application/json'])->assertOk();

        $this->assertSame($png, $response->getContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename=prescription.png', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));

        Http::assertSent(fn (Request $r) => $r->url() === 'http://reservation.test/internal/v1/reservations/12/prescription'
            && $r->header('X-User-Role') === ['pharmacy_technician'] && ! $r->hasHeader('Authorization'));
    }

    public function test_a_jpeg_is_streamed_too_and_a_hostile_file_name_is_never_passed_on(): void
    {
        Http::fake(['reservation.test/*' => Http::response('jpegbytes', 200, [
            'Content-Type' => 'image/jpeg; charset=binary',
            'Content-Disposition' => 'attachment; filename="C:\\var\\www\\storage\\prescriptions\\abc123.jpg"',
            'Cache-Control' => 'public, max-age=999',
        ])]);

        $response = $this->get('/api/v1/reservations/12/prescription', $this->as('customer') + ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename=prescription.jpg', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('abc123', json_encode($response->headers->all()));
        $this->assertStringNotContainsString('storage', json_encode($response->headers->all()));
    }

    public function test_an_error_envelope_from_the_service_is_relayed_as_an_envelope(): void
    {
        $answer = null;
        Http::fake(function () use (&$answer) {
            return Http::response($answer['body'], $answer['status']);
        });

        foreach ([403, 404] as $status) {
            $answer = ['status' => $status, 'body' => $this->envelope(null, $status, ['code' => $status === 403 ? 'FORBIDDEN' : 'NOT_FOUND', 'message' => 'x', 'details' => null])];

            $response = $this->getJson('/api/v1/reservations/12/prescription', $this->as('customer'))->assertStatus($status);

            $this->assertSame($answer['body'], $response->json());
            $this->assertSame('application/json', $response->headers->get('Content-Type'));
        }
    }
    public function test_a_200_that_is_not_an_image_is_a_502_without_details(): void
    {
        $current = null;
        Http::fake(function () use (&$current) {
            return $current;
        });
        $answers = [
            'html' => Http::response('<html>Fatal error in /var/www/app.php</html>', 200, ['Content-Type' => 'text/html']),
            'gif' => Http::response('GIF89a', 200, ['Content-Type' => 'image/gif']),
            'svg' => Http::response('<svg onload=alert(1)/>', 200, ['Content-Type' => 'image/svg+xml']),
            'envelope' => Http::response($this->envelope(['id' => 1]), 200),
            'empty png' => Http::response('', 200, ['Content-Type' => 'image/png']),
            'no type' => Http::response('bytes', 200),
        ];

        foreach ($answers as $label => $answer) {
            $current = $answer;

            $response = $this->getJson('/api/v1/reservations/12/prescription', $this->as('customer'))
                ->assertStatus(502)->assertJsonPath('error.code', 'BAD_UPSTREAM_RESPONSE')->assertJsonPath('service', 'gateway');
            $this->assertStringNotContainsString('/var/www', $response->getContent(), $label);
        }
    }

    // ------------------------------------------------------------ timeouts

    private function clientSpy(): ServiceClient
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

        return $spy;
    }

    public function test_create_and_status_wait_ten_seconds_and_the_get_routes_three(): void
    {
        $spy = $this->clientSpy();
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/prescription')
            ? Http::response('png', 200, ['Content-Type' => 'image/png'])
            : Http::response($this->envelope(['id' => 1]), 200));

        $this->postJsonReservation()->assertOk();                                                                     // create
        $this->patchJson('/api/v1/reservations/1/status', ['action' => 'approve'], $this->as('pharmacy_technician'))->assertOk(); // status
        $this->getJson('/api/v1/reservations', $this->as('customer'))->assertOk();                                     // list
        $this->getJson('/api/v1/reservations/1', $this->as('customer'))->assertOk();                                   // show
        $this->get('/api/v1/reservations/1/prescription', $this->as('customer') + ['Accept' => 'application/json'])->assertOk(); // stream

        $this->assertSame([10.0, 10.0, 3.0, 3.0, 3.0], $spy->timeouts);
    }

    public function test_the_reservation_timeout_comes_from_configuration_and_defaults_to_ten(): void
    {
        $spy = $this->clientSpy();
        $this->fake();

        config(['services.reservation.timeout' => 25.0]);
        $this->postJsonReservation()->assertOk();
        $this->assertSame([25.0], $spy->timeouts);

        $this->assertSame(10.0, (float) (include base_path('config/services.php'))['reservation']['timeout']);
    }

    // ------------------------------------------------------------ failures

    public function test_service_down_is_503_and_timeout_is_504_and_nothing_is_retried(): void
    {
        $calls = 0;
        $message = 'cURL error 7: Failed to connect to reservation.test port 80';
        Http::fake(function () use (&$calls, &$message) {
            $calls++;
            throw new ConnectionException($message);
        });

        $requests = [
            fn () => $this->postJsonReservation(),
            fn () => $this->patchJson('/api/v1/reservations/1/status', ['action' => 'approve'], $this->as('pharmacy_technician')),
            fn () => $this->getJson('/api/v1/reservations', $this->as('customer')),
            fn () => $this->getJson('/api/v1/reservations/1', $this->as('customer')),
            fn () => $this->getJson('/api/v1/reservations/1/prescription', $this->as('customer')),
        ];

        foreach ($requests as $send) {
            $response = $send()->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')->assertJsonPath('service', 'gateway');
            $this->assertStringNotContainsString('reservation.test', $response->getContent());
        }
        $this->assertSame(5, $calls, 'one attempt per request');

        $message = 'cURL error 28: Operation timed out after 10001 milliseconds';
        $calls = 0;
        foreach ($requests as $send) {
            $send()->assertStatus(504)->assertJsonPath('error.code', 'UPSTREAM_TIMEOUT');
        }
        $this->assertSame(5, $calls, 'a timeout is never retried, least of all a POST or PATCH');
    }

    public function test_a_non_envelope_answer_is_502(): void
    {
        Http::fake(['reservation.test/*' => Http::response('<html>Fatal error at /var/www/app.php</html>', 500)]);

        $response = $this->postJsonReservation()->assertStatus(502)->assertJsonPath('error.code', 'BAD_UPSTREAM_RESPONSE');

        $this->assertStringNotContainsString('/var/www', $response->getContent());
    }

    public function test_a_missing_service_url_is_503(): void
    {
        config(['services.reservation.url' => null]);
        Http::fake();

        $this->getJson('/api/v1/reservations', $this->as('customer'))->assertStatus(503);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ rate limits

    public function test_create_allows_ten_a_minute_and_only_authorized_requests_count(): void
    {
        $this->fake();

        foreach (range(1, 12) as $n) { // refused before the limiter
            $this->postJson('/api/v1/reservations', self::BODY, ['Accept' => 'application/json', 'Idempotency-Key' => 'key-0000-0001'])->assertStatus(401);
            $this->postJsonReservation($this->as('ceo', 9, null, ['Idempotency-Key' => 'key-0000-0001']))->assertStatus(403);
        }
        foreach (range(1, 10) as $n) {
            $this->postJsonReservation()->assertOk();
        }

        $response = $this->postJsonReservation()->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')->assertJsonPath('service', 'gateway');
        $this->assertNotEmpty($response->headers->get('Retry-After'));
        Http::assertSentCount(10);
    }

    public function test_status_changes_allow_thirty_a_minute_and_only_authorized_requests_count(): void
    {
        $this->fake();
        $staff = $this->as('pharmacy_technician');

        foreach (range(1, 35) as $n) {
            $this->patchJson('/api/v1/reservations/1/status', ['action' => 'approve'], ['Accept' => 'application/json'])->assertStatus(401);
            $this->patchJson('/api/v1/reservations/1/status', ['action' => 'approve'], $this->as('ceo'))->assertStatus(403);
        }
        foreach (range(1, 30) as $n) {
            $this->patchJson('/api/v1/reservations/1/status', ['action' => 'approve'], $staff)->assertOk();
        }

        $this->patchJson('/api/v1/reservations/1/status', ['action' => 'approve'], $staff)->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
        Http::assertSentCount(30);
    }

    public function test_the_two_limits_are_independent(): void
    {
        $this->fake();

        foreach (range(1, 10) as $n) {
            $this->postJsonReservation()->assertOk();
        }
        $this->postJsonReservation()->assertStatus(429);

        // The status limit is a different bucket, and reads are not limited here.
        $this->patchJson('/api/v1/reservations/1/status', ['action' => 'cancel'], $this->as('customer'))->assertOk();
        $this->getJson('/api/v1/reservations', $this->as('customer'))->assertOk();
    }
}
