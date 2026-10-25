<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\ReservationStatusHistory;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ReservationTestHelpers;
use Tests\TestCase;

class CreateReservationTest extends TestCase
{
    use RefreshDatabase;
    use ReservationTestHelpers;

    private const URL = '/internal/v1/reservations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReservation();
    }

    private function create(array $body = [], ?array $headers = null)
    {
        return $this->postJson(self::URL, $this->body($body), $headers ?? $this->customer());
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, ReservationItem::count());
        $this->assertSame(0, ReservationStatusHistory::count());
    }

    // ------------------------------------------------------------ success

    public function test_a_reservation_is_created_with_a_claim_code_history_and_price_snapshots(): void
    {
        $response = $this->create([], $this->customer('key-0000-0001', 7, ['X-Request-Id' => 'req-create-1']))
            ->assertStatus(201)
            ->assertJsonPath('status', 201)
            ->assertJsonPath('service', 'reservation-service')
            ->assertJsonPath('request_id', 'req-create-1')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.branch_id', 1)
            ->assertJsonPath('data.ocr_status', 'not_required')
            ->assertJsonPath('data.items.0.medicine_name', 'Med 1 500mg Tablet')
            ->assertJsonPath('data.items.0.unit_price', '12.50')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.1.unit_price', '8.00')
            ->assertJsonPath('warnings', []);

        $this->assertMatchesRegularExpression('/^PA-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $response->json('data.claim_code'));

        $reservation = Reservation::firstOrFail();
        $this->assertSame($response->json('data.id'), $reservation->id);
        $this->assertSame(7, $reservation->user_id);
        $this->assertSame('pending', $reservation->status);
        $this->assertSame('not_required', $reservation->ocr_status);
        $this->assertSame('key-0000-0001', $reservation->idempotency_key);
        $this->assertSame('Pick up after 5 pm', $reservation->notes);

        $items = ReservationItem::orderBy('medicine_id')->get();
        $this->assertCount(2, $items);
        $this->assertSame('12.50', $items[0]->unit_price);
        $this->assertSame('Med 2 500mg Tablet', $items[1]->medicine_name);

        $history = ReservationStatusHistory::firstOrFail();
        $this->assertNull($history->from_status);
        $this->assertSame('pending', $history->to_status);
        $this->assertSame(7, $history->actor_id);
        $this->assertSame('req-create-1', $history->request_id);
        $this->assertSame($reservation->id, $history->reservation_id);
    }

    public function test_the_hold_uses_a_new_uuid_stored_as_hold_ref_and_a_matching_ttl_and_expiry(): void
    {
        $response = $this->create([], $this->customer('key-0000-0001', 7, ['X-Request-Id' => 'req-hold-1']))->assertStatus(201);

        $calls = $this->holdCalls();
        $this->assertCount(1, $calls);
        $hold = $calls[0];

        $this->assertSame('http://inventory.test/internal/v1/stock/holds', $hold->url());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $hold['reservation_ref']);
        $this->assertSame(Reservation::firstOrFail()->hold_ref, $hold['reservation_ref']);
        $this->assertSame(48 * 60, $hold['ttl_minutes']);
        $this->assertSame(1, $hold['branch_id']);
        $this->assertSame([['medicine_id' => 1, 'quantity' => 2], ['medicine_id' => 2, 'quantity' => 1]], $hold['items']);
        $this->assertSame([$this->internalKey], $hold->header('X-Internal-Key'));
        $this->assertSame(['req-hold-1'], $hold->header('X-Request-Id'));

        // The reservation expires when the hold does (the hold's own expiry is stored).
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, strtotime($response->json('data.expires_at')), 5);
        $this->assertSame(strtotime($response->json('data.expires_at')), Reservation::firstOrFail()->expires_at->timestamp);
    }

    public function test_the_ttl_follows_the_configuration(): void
    {
        config(['reservation.ttl_hours' => 24]);

        $this->create()->assertStatus(201);

        $this->assertSame(24 * 60, $this->holdCalls()[0]['ttl_minutes']);
    }

    public function test_the_medicines_are_looked_up_once_each_before_the_hold(): void
    {
        $this->create()->assertStatus(201);

        $urls = array_map(fn ($r) => $r->url(), $this->sent());
        $this->assertSame([
            'http://inventory.test/internal/v1/medicines/1',
            'http://inventory.test/internal/v1/medicines/2',
            'http://inventory.test/internal/v1/stock/holds',
        ], $urls);
    }

    public function test_a_multipart_request_with_a_prescription_works_with_bracket_items(): void
    {
        $this->medicines[3]['rx'] = true;

        $response = $this->post(self::URL, [
            'branch_id' => 1,
            'items' => [['medicine_id' => 3, 'quantity' => 1], ['medicine_id' => 1, 'quantity' => 2]],
            'prescription' => $this->png(),
        ], $this->customer());

        $response->assertStatus(201)->assertJsonPath('data.ocr_status', 'success')->assertJsonPath('data.ocr_text', 'Amoxicillin 500mg - take twice daily for 7 days')->assertJsonPath('data.prescription_uploaded', true);
        $this->assertCount(2, ReservationItem::all());
    }

    // ------------------------------------------------------------ prescriptions

    public function test_a_prescription_item_without_a_file_is_422_and_no_hold_is_made(): void
    {
        $this->create(['items' => [['medicine_id' => 3, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PRESCRIPTION_REQUIRED')
            ->assertJsonPath('error.details.medicine_ids', [3]);

        $this->assertSame([], $this->holdCalls());
        $this->assertNothingStored();
    }

    public function test_the_prescription_is_stored_privately_with_a_random_name_and_its_path_is_never_returned(): void
    {
        $response = $this->post(self::URL, [
            'branch_id' => 1,
            'items' => [['medicine_id' => 3, 'quantity' => 1]],
            'prescription' => $this->png('patient-juan-delacruz.png'),
        ], $this->customer())->assertStatus(201);

        $reservation = Reservation::firstOrFail();
        $this->assertNotNull($reservation->prescription_path);
        $this->assertStringStartsWith('prescriptions/', $reservation->prescription_path);
        $this->assertStringNotContainsString('juan', $reservation->prescription_path);
        Storage::disk('local')->assertExists($reservation->prescription_path);
        $this->assertSame(base64_decode(self::PNG), Storage::disk('local')->get($reservation->prescription_path));
        $this->assertSame('success', $reservation->ocr_status);

        $raw = $response->getContent();
        $this->assertStringNotContainsString($reservation->prescription_path, $raw);
        $this->assertStringNotContainsString(basename($reservation->prescription_path), $raw);
        $this->assertStringNotContainsString('prescription_path', $raw);
        $response->assertJsonPath('data.prescription_uploaded', true);
    }

    public function test_a_file_sent_when_none_is_needed_is_kept_and_ocr_stays_not_required(): void
    {
        $this->post(self::URL, ['branch_id' => 1, 'items' => [['medicine_id' => 1, 'quantity' => 1]], 'prescription' => $this->png()], $this->customer())
            ->assertStatus(201)->assertJsonPath('data.ocr_status', 'not_required')->assertJsonPath('warnings', []);

        Storage::disk('local')->assertExists(Reservation::firstOrFail()->prescription_path);
        $this->assertSame([], $this->sent('/ocr/prescription'), 'a photo nobody needs is stored, not read');
    }

    // ------------------------------------------------------------ idempotency

    public function test_the_same_key_and_body_replays_the_stored_answer_and_creates_nothing_new(): void
    {
        $first = $this->create()->assertStatus(201);
        $second = $this->create()->assertStatus(201);

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, IdempotencyKey::count());
        $this->assertCount(1, $this->holdCalls(), 'the replay must not hold stock again');
        $this->assertCount(2, $this->sent('/medicines/'), 'and not look anything up again');
    }

    public function test_the_item_order_does_not_change_the_request_identity(): void
    {
        $first = $this->create()->assertStatus(201);
        $reordered = $this->create(['items' => [['medicine_id' => 2, 'quantity' => 1], ['medicine_id' => 1, 'quantity' => 2]]])->assertStatus(201);

        $this->assertSame($first->json('data.id'), $reordered->json('data.id'));
        $this->assertSame(1, Reservation::count());
    }

    public function test_the_same_key_with_a_different_body_is_409_duplicate_request(): void
    {
        $this->create()->assertStatus(201);

        $this->create(['items' => [['medicine_id' => 1, 'quantity' => 3]]])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_REQUEST');
        $this->create(['branch_id' => 2])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_REQUEST');
        $this->create(['notes' => 'other'])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_REQUEST');

        $this->assertSame(1, Reservation::count());
        $this->assertCount(1, $this->holdCalls());
    }

    public function test_a_different_prescription_file_with_the_same_key_is_a_different_request(): void
    {
        $send = fn (string $content) => $this->post(self::URL, [
            'branch_id' => 1,
            'items' => [['medicine_id' => 3, 'quantity' => 1]],
            'prescription' => UploadedFile::fake()->createWithContent('rx.png', $content),
        ], $this->customer());

        $png = base64_decode(self::PNG);
        $send($png)->assertStatus(201);
        $send($png)->assertStatus(201);                       // the same file replays
        $send($png."\x00")->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_REQUEST'); // a different file (still a PNG header) does not

        $this->assertSame(1, Reservation::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_the_same_key_from_another_customer_is_independent(): void
    {
        $this->create()->assertStatus(201);
        $this->create([], $this->customer('key-0000-0001', 8))->assertStatus(201);

        $this->assertSame(2, Reservation::count());
    }

    public function test_a_key_row_that_is_still_in_progress_is_409_and_creates_nothing(): void
    {
        $body = $this->body();
        // Same request as create(), but the first attempt "has not finished": no stored response yet.
        $this->create()->assertStatus(201);
        IdempotencyKey::query()->update(['status_code' => null, 'response_json' => null]);

        $this->create($body)->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_REQUEST')->assertJsonPath('error.details.reason', 'in_progress');
        $this->assertSame(1, Reservation::count());
    }

    public function test_a_missing_idempotency_key_is_400(): void
    {
        $headers = $this->as('customer');

        $this->postJson(self::URL, $this->body(), $headers)->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->postJson(self::URL, $this->body(), $headers + ['Idempotency-Key' => '  '])->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertSame([], $this->sent());
        $this->assertNothingStored();
    }

    public function test_a_key_of_the_wrong_length_is_422(): void
    {
        $this->create([], $this->customer('short'))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->create([], $this->customer(str_repeat('k', 129)))->assertStatus(422);
        $this->create([], $this->customer(str_repeat('k', 128)))->assertStatus(201);
    }

    public function test_a_failed_attempt_does_not_block_a_retry_with_the_same_key(): void
    {
        $this->holdMode = 'insufficient';
        $this->create()->assertStatus(409);
        $this->assertSame(0, IdempotencyKey::count());

        $this->holdMode = 'ok';
        $this->create()->assertStatus(201);
        $this->assertSame(1, Reservation::count());
    }

    // ------------------------------------------------------------ stock and inventory failures

    public function test_insufficient_stock_is_409_with_details_and_no_reservation_is_created(): void
    {
        $this->holdMode = 'insufficient';

        $this->create()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STOCK_INSUFFICIENT')
            ->assertJsonPath('error.details.items.0.available', 1)
            ->assertJsonPath('error.details.items.0.requested', 2)
            ->assertJsonPath('service', 'reservation-service');

        $this->assertNothingStored();
        $this->assertSame([], $this->releaseCalls(), 'nothing was held, nothing to release');
    }

    public function test_an_unknown_medicine_is_404_and_no_hold_is_made(): void
    {
        $this->create(['items' => [['medicine_id' => 1, 'quantity' => 1], ['medicine_id' => 99, 'quantity' => 1]]])
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertSame([], $this->holdCalls());
        $this->assertNothingStored();
    }

    public function test_a_medicine_not_stocked_at_the_branch_is_404_from_the_hold(): void
    {
        $this->holdMode = 'not_found';

        $this->create()->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND')->assertJsonPath('error.details.medicine_ids', [1]);
        $this->assertNothingStored();
    }

    public function test_inventory_down_is_503_and_nothing_is_stored(): void
    {
        $this->holdMode = 'down';

        $response = $this->create()->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');

        $this->assertStringNotContainsString('inventory.test', $response->getContent());
        $this->assertNothingStored();
        $this->assertSame([], $this->releaseCalls(), 'the connection failed, so no hold can exist');
        $this->assertCount(1, $this->holdCalls(), 'a POST is never retried');
    }

    public function test_a_timeout_is_504_and_one_release_is_attempted_for_the_reference(): void
    {
        $this->holdMode = 'timeout';

        $this->create()->assertStatus(504)->assertJsonPath('error.code', 'UPSTREAM_TIMEOUT');

        $this->assertNothingStored();
        $this->assertCount(1, $this->holdCalls(), 'a POST is never retried');
        $holdRef = $this->holdCalls()[0]['reservation_ref'];
        $releases = $this->releaseCalls();
        $this->assertCount(1, $releases);
        $this->assertSame("http://inventory.test/internal/v1/stock/holds/{$holdRef}/release", $releases[0]->url());
    }

    public function test_a_non_envelope_answer_is_502_without_details_and_a_release_is_attempted(): void
    {
        $this->holdMode = 'garbage';

        $response = $this->create()->assertStatus(502)->assertJsonPath('error.code', 'BAD_UPSTREAM_RESPONSE');

        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertCount(1, $this->releaseCalls());
        $this->assertNothingStored();
    }

    public function test_a_failing_release_after_a_timeout_does_not_change_the_answer(): void
    {
        $this->holdMode = 'timeout';
        $this->releaseFailures = PHP_INT_MAX;

        $this->create()->assertStatus(504);

        $this->assertCount(1, $this->releaseCalls());
    }

    // ------------------------------------------------------------ compensation

    public function test_a_database_failure_after_the_hold_releases_the_hold_and_returns_500(): void
    {
        Reservation::creating(fn () => throw new \RuntimeException('disk full SQLSTATE secret detail'));

        $response = $this->create()->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertNothingStored();
        $this->assertSame(0, IdempotencyKey::count());

        $holdRef = $this->holdCalls()[0]['reservation_ref'];
        $releases = $this->releaseCalls();
        $this->assertCount(1, $releases);
        $this->assertSame("http://inventory.test/internal/v1/stock/holds/{$holdRef}/release", $releases[0]->url());
    }

    public function test_the_release_is_retried_up_to_three_times_and_then_succeeds(): void
    {
        Reservation::creating(fn () => throw new \RuntimeException('boom'));
        $this->releaseFailures = 2;

        $this->create()->assertStatus(500);

        $this->assertCount(3, $this->releaseCalls());
    }

    public function test_when_every_release_fails_it_is_logged_without_personal_data_and_the_answer_is_still_500(): void
    {
        Reservation::creating(fn () => throw new \RuntimeException('boom'));
        $this->releaseFailures = PHP_INT_MAX;
        Log::spy();

        $this->create(['notes' => 'my secret note'], $this->customer('key-0000-0001', 7, ['X-Request-Id' => 'req-comp-1']))
            ->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertCount(3, $this->releaseCalls(), 'no more than 3 attempts');
        $holdRef = $this->holdCalls()[0]['reservation_ref'];

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context = []) use ($holdRef) {
            return $message === 'Stock hold could not be released'
                && $context['request_id'] === 'req-comp-1'
                && $context['reservation_ref'] === $holdRef
                && ! str_contains(json_encode($context), 'secret');
        })->once();
    }

    public function test_a_file_that_cannot_be_saved_releases_the_hold_and_rolls_the_reservation_back(): void
    {
        // A disk whose root sits under a regular file: nothing can be written there.
        config([
            'filesystems.disks.broken' => ['driver' => 'local', 'root' => __FILE__.'/nope', 'throw' => false],
            'reservation.files_disk' => 'broken',
        ]);

        $this->post(self::URL, [
            'branch_id' => 1,
            'items' => [['medicine_id' => 3, 'quantity' => 1]],
            'prescription' => $this->png(),
        ], $this->customer())->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertNothingStored();
        $this->assertSame(0, IdempotencyKey::count());
        $this->assertCount(1, $this->releaseCalls());
    }

    public function test_a_claim_code_clash_draws_a_new_code(): void
    {
        $this->makeReservation(99, 1, 'PA-AAAAAAAA');

        $codes = ['PA-AAAAAAAA', 'PA-BBBBBBBB'];
        $this->app->bind(ReservationService::class, fn ($app) => new class($app->make(\App\Services\InventoryClient::class), $app->make(\App\Services\IntegrationClient::class), $codes) extends ReservationService
        {
            public function __construct(\App\Services\InventoryClient $inventory, \App\Services\IntegrationClient $integration, private array $queue)
            {
                parent::__construct($inventory, $integration);
            }

            protected function newClaimCode(): string
            {
                return array_shift($this->queue);
            }
        });

        $this->create()->assertStatus(201)->assertJsonPath('data.claim_code', 'PA-BBBBBBBB');
        $this->assertSame(2, Reservation::count());
        $this->assertSame([], $this->releaseCalls());
    }

    public function test_generated_claim_codes_are_all_in_the_unambiguous_alphabet(): void
    {
        $service = new class(app(\App\Services\InventoryClient::class), app(\App\Services\IntegrationClient::class)) extends ReservationService
        {
            public function code(): string
            {
                return $this->newClaimCode();
            }
        };

        $seen = [];
        for ($i = 0; $i < 300; $i++) {
            $code = $service->code();
            $this->assertMatchesRegularExpression('/^PA-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code);
            $seen[$code] = true;
        }
        $this->assertGreaterThan(295, count($seen), 'codes must not repeat');
    }

    // ------------------------------------------------------------ authentication and roles

    public function test_roles_other_than_customer_are_403_and_nothing_is_called(): void
    {
        foreach (['pharmacy_technician', 'pharmacy_assistant', 'branch_manager', 'ceo'] as $role) {
            $this->postJson(self::URL, $this->body(), $this->as($role, 1, 9, ['Idempotency-Key' => 'key-0000-0001']))
                ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        }

        $this->assertSame([], $this->sent());
        $this->assertSame(0, IdempotencyKey::count());
        $this->assertNothingStored();
    }

    public function test_missing_or_bad_user_headers_are_401(): void
    {
        $base = ['X-Internal-Key' => $this->internalKey, 'Idempotency-Key' => 'key-0000-0001', 'Accept' => 'application/json'];

        $this->postJson(self::URL, $this->body(), $base)->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->postJson(self::URL, $this->body(), $base + ['X-User-Id' => '7'])->assertStatus(401);
        $this->postJson(self::URL, $this->body(), $base + ['X-User-Id' => 'abc', 'X-User-Role' => 'customer'])->assertStatus(401);
        $this->postJson(self::URL, $this->body(), $base + ['X-User-Id' => '7', 'X-User-Role' => 'superuser'])->assertStatus(401);

        $this->assertSame([], $this->sent());
    }

    public function test_a_missing_or_wrong_internal_key_is_401(): void
    {
        $headers = $this->customer();
        unset($headers['X-Internal-Key']);

        $this->postJson(self::URL, $this->body(), $headers)->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
        $this->postJson(self::URL, $this->body(), ['X-Internal-Key' => 'wrong'] + $headers)->assertStatus(401);
        $this->getJson(self::URL.'/1', ['X-Internal-Key' => 'wrong'] + $headers)->assertStatus(401);

        $this->assertSame([], $this->sent());
        $this->assertNothingStored();
    }

    // ------------------------------------------------------------ validation

    public function test_item_and_field_limits_are_enforced_without_calling_inventory(): void
    {
        $item = fn (int $id, int $qty = 1) => ['medicine_id' => $id, 'quantity' => $qty];
        $eleven = array_map($item, range(1, 11));
        $ten = array_map($item, range(1, 10));

        $invalid = [
            'no items' => ['items' => []],
            'items missing' => ['items' => null],
            'eleven items' => ['items' => $eleven],
            'duplicate medicine' => ['items' => [$item(1), $item(1, 2)]],
            'quantity 0' => ['items' => [$item(1, 0)]],
            'quantity 21' => ['items' => [$item(1, 21)]],
            'quantity text' => ['items' => [['medicine_id' => 1, 'quantity' => 'many']]],
            'medicine id 0' => ['items' => [$item(0)]],
            'no branch' => ['branch_id' => null],
            'branch 0' => ['branch_id' => 0],
            'notes too long' => ['notes' => str_repeat('n', 501)],
        ];

        foreach ($invalid as $label => $override) {
            $this->create($override)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED', $label);
            $this->assertSame(0, IdempotencyKey::count(), "{$label}: an invalid request must not use up the key");
        }

        $this->assertSame([], $this->sent(), 'invalid input never reaches inventory-service');

        // The limits themselves are allowed: 10 items, quantity 1 and 20.
        $this->medicines = array_fill_keys(range(1, 10), ['rx' => false, 'price' => '1.00']);
        $this->create(['items' => array_map(fn ($id) => $item($id, $id % 2 ? 1 : 20), range(1, 10))], $this->customer('key-0000-0002'))->assertStatus(201)->assertJsonCount(10, 'data.items');
    }

    /** A real file on disk: Laravel's fake UploadedFile would guess the type from the name instead of the content. */
    private function realFile(string $content, string $name = 'rx.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rx');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_the_prescription_must_be_a_small_jpg_or_png(): void
    {
        $send = fn (UploadedFile $file) => $this->post(self::URL, ['branch_id' => 1, 'items' => [['medicine_id' => 3, 'quantity' => 1]], 'prescription' => $file], $this->customer());

        $send($this->realFile('this is not an image'))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $send($this->realFile(base64_decode('R0lGODlhAQABAAAAACwAAAAAAQABAAACAkQBADs=')))->assertStatus(422); // a GIF named rx.png
        $send($this->realFile('<?php echo 1;', 'rx.php'))->assertStatus(422);
        $send($this->realFile(base64_decode(self::PNG).str_repeat('0', 4 * 1024 * 1024)))->assertStatus(422); // over 4 MB

        $this->assertSame([], $this->sent());
        $this->assertNothingStored();

        // And a real PNG is accepted.
        $send($this->realFile(base64_decode(self::PNG)))->assertStatus(201);
    }
}
