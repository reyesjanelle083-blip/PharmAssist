<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;
use App\Models\StockHold;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryTestHelpers;
use Tests\TestCase;

/**
 * SQLite is used here: lockForUpdate() is a no-op, so these tests prove the logic and the guarded updates.
 * The real concurrency test is `php artisan inventory:race-test` on MySQL (T20).
 */
class StockHoldTest extends TestCase
{
    use InventoryTestHelpers;
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
        $this->branch = $this->makeBranch();
    }

    // ------------------------------------------------------------ helpers

    private function stocked(string $name, int $stock = 10, int $reserved = 0, int $threshold = 2): BranchInventory
    {
        return $this->makeInventory($this->branch, $this->makeMedicine($name), $stock, $reserved, $threshold);
    }

    /** @param array<int, array{0: BranchInventory, 1: int}> $lines */
    private function body(string $ref, array $lines, array $extra = []): array
    {
        return array_merge([
            'reservation_ref' => $ref,
            'branch_id' => $this->branch->id,
            'items' => array_map(fn (array $line) => ['medicine_id' => $line[0]->medicine_id, 'quantity' => $line[1]], $lines),
        ], $extra);
    }

    /** @param array<int, array{0: BranchInventory, 1: int}> $lines */
    private function hold(string $ref, array $lines, array $extra = [])
    {
        return $this->postJson('/internal/v1/stock/holds', $this->body($ref, $lines, $extra), $this->internal());
    }

    private function url(string $ref, string $action = ''): string
    {
        return '/internal/v1/stock/holds/'.$ref.($action ? '/'.$action : '');
    }

    /** reserved_quantity must always be within 0..stock_quantity. */
    private function assertInvariant(): void
    {
        foreach (BranchInventory::all() as $row) {
            $this->assertGreaterThanOrEqual(0, $row->reserved_quantity, "reserved < 0 on row {$row->id}");
            $this->assertGreaterThanOrEqual(0, $row->stock_quantity, "stock < 0 on row {$row->id}");
            $this->assertLessThanOrEqual($row->stock_quantity, $row->reserved_quantity, "reserved > stock on row {$row->id}");
        }
    }

    // ------------------------------------------------------------ authentication

    public function test_missing_or_wrong_internal_key_returns_401_on_every_hold_route(): void
    {
        $item = $this->stocked('Amoxicillin');

        $this->postJson('/internal/v1/stock/holds', $this->body('R-1', [[$item, 1]]))->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
        $this->postJson($this->url('R-1', 'commit'), [], ['X-Internal-Key' => 'wrong'])->assertStatus(401);
        $this->postJson($this->url('R-1', 'release'))->assertStatus(401);
        $this->getJson($this->url('R-1'))->assertStatus(401);

        $this->assertSame(0, StockHold::count());
    }

    // ------------------------------------------------------------ create

    public function test_the_hold_answer_names_each_medicine_with_its_current_price_and_prescription_flag(): void
    {
        $branded = $this->makeInventory($this->branch, $this->makeMedicine('Paracetamol', 'Biogesic', 'Pain', false, '500mg', 'Tablet'), 10, 0, 2, '12.50');
        $unbranded = $this->makeInventory($this->branch, $this->makeMedicine('Amoxicillin', '', 'Antibiotics', true, '500mg', 'Capsule'), 10, 0, 2, '8.00');

        $items = $this->hold('RES-NAMES', [[$branded, 1], [$unbranded, 2]])->assertStatus(201)->json('data.items');

        $byId = collect($items)->keyBy('medicine_id');
        $this->assertSame('Paracetamol (Biogesic) 500mg Tablet', $byId[$branded->medicine_id]['medicine_name']);
        $this->assertSame('12.50', $byId[$branded->medicine_id]['unit_price']);
        $this->assertFalse($byId[$branded->medicine_id]['prescription_required']);
        $this->assertSame('Amoxicillin 500mg Capsule', $byId[$unbranded->medicine_id]['medicine_name']);
        $this->assertSame('8.00', $byId[$unbranded->medicine_id]['unit_price']);
        $this->assertTrue($byId[$unbranded->medicine_id]['prescription_required']);

        // The price is the CURRENT one: a replay after a price change shows the new price, the stock is not reserved twice.
        $branded->update(['price' => '13.00']);
        $this->hold('RES-NAMES', [[$branded, 1], [$unbranded, 2]])->assertOk()->assertJsonPath('data.items.0.unit_price', '13.00');
    }

    public function test_a_hold_reserves_stock_and_creates_hold_rows(): void
    {
        $item = $this->stocked('Amoxicillin', 10);

        $response = $this->hold('RES-1001', [[$item, 3]], ['ttl_minutes' => 60])
            ->assertStatus(201)
            ->assertJsonPath('data.reservation_ref', 'RES-1001')
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.status', 'held')
            ->assertJsonPath('data.items.0.medicine_id', $item->medicine_id)
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.items.0.status', 'held');

        $fresh = $item->fresh();
        $this->assertSame(3, $fresh->reserved_quantity);
        $this->assertSame(10, $fresh->stock_quantity);          // stock itself is untouched by a hold
        $this->assertSame(7, $fresh->available_quantity);
        $this->assertSame(1, $fresh->version);                  // holds do not bump the staff edit version

        $hold = StockHold::firstOrFail();
        $this->assertSame('RES-1001', $hold->reservation_ref);
        $this->assertSame($item->id, $hold->branch_inventory_id);
        $this->assertSame(3, $hold->quantity);
        $this->assertSame('held', $hold->status);
        $this->assertNotEmpty($hold->hold_ref);
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($hold->expires_at, false), 1);
        $this->assertSame($hold->expires_at->toIso8601String(), $response->json('data.expires_at'));

        $this->assertSame(0, StockMovement::count(), 'a hold is not a stock movement');
        $this->assertInvariant();
    }

    public function test_the_default_ttl_comes_from_configuration(): void
    {
        $item = $this->stocked('Amoxicillin');

        $this->hold('RES-TTL-1', [[$item, 1]])->assertStatus(201);
        $this->assertEqualsWithDelta(48 * 60, now()->diffInMinutes(StockHold::first()->expires_at, false), 1);

        config(['inventory.hold_ttl_hours' => 2]);
        $this->hold('RES-TTL-2', [[$item, 1]])->assertStatus(201);
        $this->assertEqualsWithDelta(120, now()->diffInMinutes(StockHold::where('reservation_ref', 'RES-TTL-2')->first()->expires_at, false), 1);
    }

    public function test_a_hold_can_use_every_available_unit_but_not_one_more(): void
    {
        $item = $this->stocked('Amoxicillin', 5, 2); // 3 available

        $this->hold('RES-EDGE-1', [[$item, 4]])->assertStatus(409)
            ->assertJsonPath('error.code', 'STOCK_INSUFFICIENT')
            ->assertJsonPath('error.details.items.0.available', 3)
            ->assertJsonPath('error.details.items.0.requested', 4);

        $this->hold('RES-EDGE-2', [[$item, 3]])->assertStatus(201);
        $this->assertSame(5, $item->fresh()->reserved_quantity);
        $this->hold('RES-EDGE-3', [[$item, 1]])->assertStatus(409);
        $this->assertInvariant();
    }

    public function test_insufficient_stock_returns_409_with_available_and_changes_nothing(): void
    {
        $item = $this->stocked('Amoxicillin', 5, 3); // 2 available

        $this->hold('RES-2001', [[$item, 3]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STOCK_INSUFFICIENT')
            ->assertJsonPath('data', null)
            ->assertJsonPath('error.details.items.0.medicine_id', $item->medicine_id)
            ->assertJsonPath('error.details.items.0.available', 2)
            ->assertJsonPath('error.details.items.0.sufficient', false);

        $this->assertSame(3, $item->fresh()->reserved_quantity);
        $this->assertSame(0, StockHold::count());
        $this->assertInvariant();
    }

    public function test_a_multi_item_hold_is_all_or_nothing(): void
    {
        $plenty = $this->stocked('Plenty', 10);
        $short = $this->stocked('Short', 2);

        $response = $this->hold('RES-3001', [[$plenty, 4], [$short, 3]])
            ->assertStatus(409)->assertJsonPath('error.code', 'STOCK_INSUFFICIENT');

        $details = collect($response->json('error.details.items'))->keyBy('medicine_id');
        $this->assertSame(10, $details[$plenty->medicine_id]['available']);
        $this->assertTrue($details[$plenty->medicine_id]['sufficient']);
        $this->assertSame(2, $details[$short->medicine_id]['available']);
        $this->assertFalse($details[$short->medicine_id]['sufficient']);

        $this->assertSame(0, $plenty->fresh()->reserved_quantity, 'the item with enough stock must not be reserved');
        $this->assertSame(0, $short->fresh()->reserved_quantity);
        $this->assertSame(0, StockHold::count());
    }

    public function test_a_multi_item_hold_reserves_every_item(): void
    {
        $a = $this->stocked('A Med', 10);
        $b = $this->stocked('B Med', 4);

        $this->hold('RES-3002', [[$a, 2], [$b, 4]])->assertStatus(201)->assertJsonCount(2, 'data.items');

        $this->assertSame(2, $a->fresh()->reserved_quantity);
        $this->assertSame(4, $b->fresh()->reserved_quantity);
        $this->assertSame(2, StockHold::where('reservation_ref', 'RES-3002')->count());
        $this->assertSame(2, StockHold::pluck('hold_ref')->unique()->count());
        $this->assertInvariant();
    }

    public function test_replaying_the_same_request_returns_the_original_and_reserves_nothing_twice(): void
    {
        $a = $this->stocked('A Med', 10);
        $b = $this->stocked('B Med', 10);

        $first = $this->hold('RES-4001', [[$a, 2], [$b, 3]])->assertStatus(201);

        // same body, items in a different order
        $replay = $this->hold('RES-4001', [[$b, 3], [$a, 2]])->assertStatus(200);

        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame(2, $a->fresh()->reserved_quantity);
        $this->assertSame(3, $b->fresh()->reserved_quantity);
        $this->assertSame(2, StockHold::count());
    }

    public function test_replaying_with_a_different_body_returns_409_duplicate_request(): void
    {
        $a = $this->stocked('A Med', 10);
        $b = $this->stocked('B Med', 10);
        $otherBranch = $this->makeBranch('Other Branch');
        $this->hold('RES-4002', [[$a, 2]])->assertStatus(201);

        // different quantity
        $this->hold('RES-4002', [[$a, 5]])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_REQUEST');
        // different item
        $this->hold('RES-4002', [[$b, 2]])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_REQUEST');
        // extra item
        $this->hold('RES-4002', [[$a, 2], [$b, 1]])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_REQUEST');
        // different branch
        $this->postJson('/internal/v1/stock/holds', ['branch_id' => $otherBranch->id] + $this->body('RES-4002', [[$a, 2]]), $this->internal())
            ->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_REQUEST');

        $this->assertSame(2, $a->fresh()->reserved_quantity);
        $this->assertSame(0, $b->fresh()->reserved_quantity);
        $this->assertSame(1, StockHold::count());
    }

    public function test_unknown_inactive_branch_unknown_medicine_and_missing_stock_row_return_404(): void
    {
        $item = $this->stocked('Amoxicillin', 10);
        $closed = $this->makeBranch('Closed', false);
        $noStock = $this->makeMedicine('Never stocked', '', 'Other', false, '10mg', 'Tablet');

        $this->postJson('/internal/v1/stock/holds', ['branch_id' => $closed->id] + $this->body('RES-5001', [[$item, 1]]), $this->internal())
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->postJson('/internal/v1/stock/holds', ['branch_id' => 9999] + $this->body('RES-5002', [[$item, 1]]), $this->internal())->assertStatus(404);

        $this->postJson('/internal/v1/stock/holds', ['reservation_ref' => 'RES-5003', 'branch_id' => $this->branch->id, 'items' => [['medicine_id' => 9999, 'quantity' => 1]]], $this->internal())
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->postJson('/internal/v1/stock/holds', ['reservation_ref' => 'RES-5004', 'branch_id' => $this->branch->id, 'items' => [['medicine_id' => $noStock->id, 'quantity' => 1]]], $this->internal())
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        // one unknown line makes the whole request fail: the valid line is not reserved
        $this->postJson('/internal/v1/stock/holds', ['reservation_ref' => 'RES-5005', 'branch_id' => $this->branch->id, 'items' => [
            ['medicine_id' => $item->medicine_id, 'quantity' => 1], ['medicine_id' => 9999, 'quantity' => 1],
        ]], $this->internal())->assertStatus(404);

        $this->assertSame(0, $item->fresh()->reserved_quantity);
        $this->assertSame(0, StockHold::count());
    }

    public function test_validation_errors_return_422(): void
    {
        $item = $this->stocked('Amoxicillin', 50);
        $ok = ['medicine_id' => $item->medicine_id, 'quantity' => 1];
        $base = ['reservation_ref' => 'RES-6001', 'branch_id' => $this->branch->id, 'items' => [$ok]];

        $cases = [
            'missing reservation_ref' => [array_diff_key($base, ['reservation_ref' => 1]), 'reservation_ref'],
            'bad characters in reservation_ref' => [['reservation_ref' => 'bad ref/1'] + $base, 'reservation_ref'],
            'reservation_ref too long' => [['reservation_ref' => str_repeat('a', 65)] + $base, 'reservation_ref'],
            'missing branch' => [array_diff_key($base, ['branch_id' => 1]), 'branch_id'],
            'no items' => [['items' => []] + $base, 'items'],
            'items missing' => [array_diff_key($base, ['items' => 1]), 'items'],
            'more than 10 items' => [['items' => array_map(fn ($i) => ['medicine_id' => $i, 'quantity' => 1], range(1, 11))] + $base, 'items'],
            'quantity 0' => [['items' => [['medicine_id' => $item->medicine_id, 'quantity' => 0]]] + $base, 'items.0.quantity'],
            'quantity 21' => [['items' => [['medicine_id' => $item->medicine_id, 'quantity' => 21]]] + $base, 'items.0.quantity'],
            'quantity not a number' => [['items' => [['medicine_id' => $item->medicine_id, 'quantity' => 'two']]] + $base, 'items.0.quantity'],
            'duplicate medicine' => [['items' => [$ok, $ok]] + $base, 'items.0.medicine_id'],
            'missing medicine_id' => [['items' => [['quantity' => 1]]] + $base, 'items.0.medicine_id'],
            'ttl 0' => [['ttl_minutes' => 0] + $base, 'ttl_minutes'],
            'ttl too large' => [['ttl_minutes' => 10081] + $base, 'ttl_minutes'],
        ];

        foreach ($cases as $name => [$payload, $field]) {
            $response = $this->postJson('/internal/v1/stock/holds', $payload, $this->internal())->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
            $this->assertArrayHasKey($field, $response->json('error.details'), $name);
        }

        $this->assertSame(0, StockHold::count());
        $this->assertSame(0, $item->fresh()->reserved_quantity);
    }

    public function test_ten_items_and_quantity_twenty_are_accepted(): void
    {
        $lines = [];
        foreach (range(1, 10) as $i) {
            $lines[] = [$this->stocked("Med {$i}", 25), 20];
        }

        $this->hold('RES-6002', $lines)->assertStatus(201)->assertJsonCount(10, 'data.items');
        $this->assertSame(10, StockHold::count());
        $this->assertInvariant();
    }

    // ------------------------------------------------------------ commit

    public function test_commit_lowers_stock_and_reserved_writes_a_movement_and_recomputes_status(): void
    {
        $item = $this->stocked('Amoxicillin', 10, 0, 8); // threshold 8: 10 is available, 7 is low
        $this->hold('RES-7001', [[$item, 3]])->assertStatus(201);

        $this->postJson($this->url('RES-7001', 'commit'), [], $this->internal() + ['X-Request-Id' => 'req-commit-0001'])
            ->assertOk()
            ->assertJsonPath('data.status', 'committed')
            ->assertJsonPath('data.items.0.status', 'committed');

        $fresh = $item->fresh();
        $this->assertSame(7, $fresh->stock_quantity);
        $this->assertSame(0, $fresh->reserved_quantity);
        $this->assertSame('low_stock', $fresh->status);        // 7 < threshold 8
        $this->assertSame(2, $fresh->version);                 // stock changed, so staff must reload

        $movement = StockMovement::firstOrFail();
        $this->assertSame($item->id, $movement->branch_inventory_id);
        $this->assertSame(-3, $movement->delta);
        $this->assertSame('hold_commit', $movement->reason);
        $this->assertNull($movement->actor_id);
        $this->assertSame('req-commit-0001', $movement->request_id);

        $this->assertSame('committed', StockHold::firstOrFail()->status);
        $this->assertInvariant();
    }

    public function test_commit_of_the_last_units_marks_the_row_out_of_stock(): void
    {
        $item = $this->stocked('Amoxicillin', 3, 0, 2);
        $this->hold('RES-7002', [[$item, 3]])->assertStatus(201);

        $this->postJson($this->url('RES-7002', 'commit'), [], $this->internal())->assertOk();

        $this->assertSame(0, $item->fresh()->stock_quantity);
        $this->assertSame('out_of_stock', $item->fresh()->status);
    }

    public function test_commit_of_a_multi_item_reservation_updates_every_row(): void
    {
        $a = $this->stocked('A Med', 10);
        $b = $this->stocked('B Med', 6);
        $this->hold('RES-7003', [[$a, 2], [$b, 6]])->assertStatus(201);

        $this->postJson($this->url('RES-7003', 'commit'), [], $this->internal())->assertOk();

        $this->assertSame([8, 0], [$a->fresh()->stock_quantity, $a->fresh()->reserved_quantity]);
        $this->assertSame([0, 0], [$b->fresh()->stock_quantity, $b->fresh()->reserved_quantity]);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(['committed'], StockHold::pluck('status')->unique()->all());
    }

    public function test_commit_in_the_wrong_state_returns_409_and_changes_nothing(): void
    {
        $item = $this->stocked('Amoxicillin', 10);
        $this->hold('RES-7004', [[$item, 2]])->assertStatus(201);
        $this->postJson($this->url('RES-7004', 'commit'), [], $this->internal())->assertOk();
        $after = $item->fresh()->only(['stock_quantity', 'reserved_quantity', 'version']);

        // committing twice
        $this->postJson($this->url('RES-7004', 'commit'), [], $this->internal())
            ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_HOLD_STATE');
        $this->assertSame($after, $item->fresh()->only(['stock_quantity', 'reserved_quantity', 'version']));
        $this->assertSame(1, StockMovement::count());

        // committing a released reservation
        $this->hold('RES-7005', [[$item, 2]])->assertStatus(201);
        $this->postJson($this->url('RES-7005', 'release'), [], $this->internal())->assertOk();
        $afterRelease = $item->fresh()->only(['stock_quantity', 'reserved_quantity', 'version']);
        $this->postJson($this->url('RES-7005', 'commit'), [], $this->internal())->assertStatus(409)->assertJsonPath('error.code', 'INVALID_HOLD_STATE');
        $this->assertSame($afterRelease, $item->fresh()->only(['stock_quantity', 'reserved_quantity', 'version']));
        $this->assertSame(1, StockMovement::count());
        $this->assertInvariant();
    }

    public function test_commit_is_all_or_nothing_when_one_hold_is_not_held(): void
    {
        $a = $this->stocked('A Med', 10);
        $b = $this->stocked('B Med', 10);
        $this->hold('RES-7006', [[$a, 2], [$b, 2]])->assertStatus(201);
        StockHold::where('branch_inventory_id', $b->id)->update(['status' => 'released']); // simulate a partially finished reservation

        $this->postJson($this->url('RES-7006', 'commit'), [], $this->internal())->assertStatus(409)->assertJsonPath('error.code', 'INVALID_HOLD_STATE');

        $this->assertSame(10, $a->fresh()->stock_quantity);
        $this->assertSame(2, $a->fresh()->reserved_quantity);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('held', StockHold::where('branch_inventory_id', $a->id)->value('status'));
    }

    // ------------------------------------------------------------ release

    public function test_release_restores_reserved_quantity_and_writes_no_movement(): void
    {
        $a = $this->stocked('A Med', 10);
        $b = $this->stocked('B Med', 5);
        $this->hold('RES-8001', [[$a, 4], [$b, 5]])->assertStatus(201);

        $this->postJson($this->url('RES-8001', 'release'), [], $this->internal())
            ->assertOk()->assertJsonPath('data.status', 'released')->assertJsonPath('data.items.0.status', 'released');

        $this->assertSame([10, 0], [$a->fresh()->stock_quantity, $a->fresh()->reserved_quantity]);
        $this->assertSame([5, 0], [$b->fresh()->stock_quantity, $b->fresh()->reserved_quantity]);
        $this->assertSame(1, $a->fresh()->version);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(['released'], StockHold::pluck('status')->unique()->all());

        // the stock can be held again
        $this->hold('RES-8002', [[$a, 10]])->assertStatus(201);
        $this->assertInvariant();
    }

    public function test_releasing_twice_is_safe_and_returns_200(): void
    {
        $item = $this->stocked('Amoxicillin', 10);
        $this->hold('RES-8003', [[$item, 4]])->assertStatus(201);
        $this->hold('RES-8004', [[$item, 3]])->assertStatus(201);

        $this->postJson($this->url('RES-8003', 'release'), [], $this->internal())->assertOk();
        $this->postJson($this->url('RES-8003', 'release'), [], $this->internal())->assertOk()->assertJsonPath('data.status', 'released');

        // the second release did not take back the other reservation's units
        $this->assertSame(3, $item->fresh()->reserved_quantity);
        $this->assertInvariant();
    }

    public function test_releasing_an_expired_hold_returns_200_without_changing_anything(): void
    {
        $item = $this->stocked('Amoxicillin', 10);
        $this->hold('RES-8005', [[$item, 4]], ['ttl_minutes' => 5])->assertStatus(201);
        $this->travel(10)->minutes();
        $this->artisan('inventory:expire-holds')->assertSuccessful();
        $this->assertSame(0, $item->fresh()->reserved_quantity);

        $this->postJson($this->url('RES-8005', 'release'), [], $this->internal())
            ->assertOk()->assertJsonPath('data.status', 'expired');
        $this->assertSame(0, $item->fresh()->reserved_quantity);
        $this->assertInvariant();
    }

    public function test_release_after_commit_returns_409_and_changes_nothing(): void
    {
        $item = $this->stocked('Amoxicillin', 10);
        $this->hold('RES-8006', [[$item, 4]])->assertStatus(201);
        $this->postJson($this->url('RES-8006', 'commit'), [], $this->internal())->assertOk();
        $after = $item->fresh()->only(['stock_quantity', 'reserved_quantity', 'version']);

        $this->postJson($this->url('RES-8006', 'release'), [], $this->internal())
            ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_HOLD_STATE');

        $this->assertSame($after, $item->fresh()->only(['stock_quantity', 'reserved_quantity', 'version']));
        $this->assertSame('committed', StockHold::firstOrFail()->status);
    }

    public function test_an_unknown_reference_returns_404(): void
    {
        $this->postJson($this->url('NOPE-1', 'commit'), [], $this->internal())->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->postJson($this->url('NOPE-1', 'release'), [], $this->internal())->assertStatus(404);
        $this->getJson($this->url('NOPE-1'), $this->internal())->assertStatus(404);
    }

    public function test_show_returns_the_current_state(): void
    {
        $a = $this->stocked('A Med', 10);
        $b = $this->stocked('B Med', 10);
        $this->hold('RES-9001', [[$a, 1], [$b, 2]])->assertStatus(201);

        $response = $this->getJson($this->url('RES-9001'), $this->internal())->assertOk()
            ->assertJsonPath('data.reservation_ref', 'RES-9001')->assertJsonPath('data.status', 'held');
        $this->assertSame([1, 2], array_column($response->json('data.items'), 'quantity'));
        $this->assertEqualsCanonicalizing([$a->medicine_id, $b->medicine_id], array_column($response->json('data.items'), 'medicine_id'));
    }

    // ------------------------------------------------------------ expiry

    public function test_the_expire_command_releases_only_holds_that_are_due(): void
    {
        $item = $this->stocked('Amoxicillin', 20);
        $this->hold('RES-E-DUE', [[$item, 4]], ['ttl_minutes' => 30])->assertStatus(201);
        $this->hold('RES-E-LATER', [[$item, 3]], ['ttl_minutes' => 600])->assertStatus(201);
        $this->hold('RES-E-DONE', [[$item, 2]], ['ttl_minutes' => 30])->assertStatus(201);
        $this->postJson($this->url('RES-E-DONE', 'commit'), [], $this->internal())->assertOk();
        $this->assertSame(7, $item->fresh()->reserved_quantity); // 4 + 3 still held; the 2 committed units left stock and reserved

        $this->travel(60)->minutes();
        $this->artisan('inventory:expire-holds')->expectsOutputToContain('Expired 1 hold(s) of 1 reservation(s)')->assertSuccessful();

        $this->assertSame('expired', StockHold::where('reservation_ref', 'RES-E-DUE')->value('status'));
        $this->assertSame('held', StockHold::where('reservation_ref', 'RES-E-LATER')->value('status'));
        $this->assertSame('committed', StockHold::where('reservation_ref', 'RES-E-DONE')->value('status'));
        $this->assertSame(3, $item->fresh()->reserved_quantity);   // only RES-E-LATER is still reserved
        $this->assertSame(18, $item->fresh()->stock_quantity);     // the commit took 2
        $this->assertInvariant();
    }

    public function test_the_expire_command_is_safe_to_run_repeatedly(): void
    {
        $item = $this->stocked('Amoxicillin', 10);
        $this->hold('RES-E-1', [[$item, 4]], ['ttl_minutes' => 5])->assertStatus(201);
        $this->travel(10)->minutes();

        $this->artisan('inventory:expire-holds')->expectsOutputToContain('Expired 1 hold(s)')->assertSuccessful();
        $this->artisan('inventory:expire-holds')->expectsOutputToContain('Expired 0 hold(s)')->assertSuccessful();
        $this->artisan('inventory:expire-holds')->assertSuccessful();

        $this->assertSame(0, $item->fresh()->reserved_quantity);
        $this->assertInvariant();
    }

    public function test_an_expired_hold_cannot_be_committed(): void
    {
        $item = $this->stocked('Amoxicillin', 10);
        $this->hold('RES-E-2', [[$item, 4]], ['ttl_minutes' => 5])->assertStatus(201);
        $this->travel(10)->minutes();
        $this->artisan('inventory:expire-holds')->assertSuccessful();

        $this->postJson($this->url('RES-E-2', 'commit'), [], $this->internal())->assertStatus(409)->assertJsonPath('error.code', 'INVALID_HOLD_STATE');
        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_the_expire_command_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('inventory:expire-holds')->assertSuccessful();
    }

    // ------------------------------------------------------------ invariants

    public function test_selling_out_never_breaks_the_invariant(): void
    {
        $item = $this->stocked('Amoxicillin', 5);

        $granted = 0;
        foreach (range(1, 12) as $i) {
            $status = $this->hold("RES-INV-{$i}", [[$item, 1]])->status();
            $granted += $status === 201 ? 1 : 0;
            $this->assertContains($status, [201, 409]);
            $this->assertInvariant();
        }

        $this->assertSame(5, $granted);
        $this->assertSame(5, $item->fresh()->reserved_quantity);

        // complete three, cancel two: nothing may go negative
        foreach ([1, 2, 3] as $i) {
            $this->postJson($this->url("RES-INV-{$i}", 'commit'), [], $this->internal())->assertOk();
            $this->assertInvariant();
        }
        foreach ([4, 5] as $i) {
            $this->postJson($this->url("RES-INV-{$i}", 'release'), [], $this->internal())->assertOk();
            $this->assertInvariant();
        }

        $row = $item->fresh();
        $this->assertSame([2, 0], [$row->stock_quantity, $row->reserved_quantity]);
        $this->assertSame('available', $row->status); // stock 2 is not below the threshold of 2
    }

    // ------------------------------------------------------------ race command safeguards

    public function test_the_race_test_refuses_to_run_in_production_and_on_non_mysql_databases(): void
    {
        $this->artisan('inventory:race-test')->expectsOutputToContain('needs a real MySQL database')->assertFailed();

        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('inventory:race-test')->expectsOutputToContain('refuses to run when APP_ENV=production')->assertFailed();

        $this->assertSame(0, Medicine::where('generic_name', 'like', 'RACE-TEST-%')->count());
    }
}
