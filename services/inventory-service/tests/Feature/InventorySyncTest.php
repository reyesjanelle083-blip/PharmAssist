<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;
use App\Models\StockMovement;
use Database\Seeders\BranchInventorySeeder;
use Database\Seeders\BranchSeeder;
use Database\Seeders\MedicineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryTestHelpers;
use Tests\TestCase;

/** Uses SQLite: lockForUpdate() is a no-op there, so this proves the logic; MySQL adds the row locks. */
class InventorySyncTest extends TestCase
{
    use InventoryTestHelpers;
    use RefreshDatabase;

    private const URL = '/internal/v1/inventory/sync';

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
        $this->branch = $this->makeBranch('Branch One');
    }

    // ------------------------------------------------------------ helpers

    /** A feed row as integration-service delivers it. */
    private function row(Medicine $m, array $overrides = [], int $row = 1): array
    {
        return array_merge([
            'row' => $row,
            'generic_name' => $m->generic_name,
            'brand_name' => $m->brand_name,
            'strength' => $m->strength,
            'dosage_form' => $m->dosage_form,
            'quantity' => 60,
            'price' => '12.50',
            'batch_no' => 'B-NEW',
            'expiration_date' => now()->addYears(2)->toDateString(),
            'min_threshold' => null,
        ], $overrides);
    }

    private function sync(array $items, bool $strict = false, ?array $headers = null, ?int $branchId = null)
    {
        return $this->postJson(self::URL, [
            'branch_id' => $branchId ?? $this->branch->id,
            'strict' => $strict,
            'items' => $items,
        ], $headers ?? $this->as('branch_manager', $this->branch->id, 42, ['X-Request-Id' => 'req-sync-0001']));
    }

    private function stocked(string $name, int $stock = 40, int $reserved = 0, int $threshold = 20): array
    {
        $medicine = $this->makeMedicine($name);
        $inventory = $this->makeInventory($this->branch, $medicine, $stock, $reserved, $threshold, '10.00');

        return [$medicine, $inventory];
    }

    private function snapshot(): array
    {
        return BranchInventory::orderBy('id')->get(['id', 'stock_quantity', 'reserved_quantity', 'price', 'batch_no', 'expiration_date', 'min_threshold', 'status', 'version'])->toArray();
    }

    // ------------------------------------------------------------ authentication and authorization

    public function test_a_missing_internal_key_and_missing_user_headers_return_401(): void
    {
        [$medicine] = $this->stocked('Amoxicillin');
        $body = ['branch_id' => $this->branch->id, 'items' => [$this->row($medicine)]];

        $this->postJson(self::URL, $body)->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
        $this->postJson(self::URL, $body, ['X-Internal-Key' => $this->internalKey])->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_only_a_branch_manager_and_the_ceo_may_sync(): void
    {
        [$medicine, $inventory] = $this->stocked('Amoxicillin');

        foreach (['pharmacy_technician', 'pharmacy_assistant', 'customer'] as $role) {
            $this->sync([$this->row($medicine)], false, $this->as($role, $role === 'customer' ? null : $this->branch->id))
                ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        }

        $this->assertSame(40, $inventory->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_manager_of_another_branch_gets_403_even_before_validation(): void
    {
        $other = $this->makeBranch('Other');
        [$medicine, $inventory] = $this->stocked('Amoxicillin');

        $this->sync([$this->row($medicine)], false, $this->as('branch_manager', $other->id))->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        // an invalid body for someone else's branch is still 403, never a 422 that reveals anything
        $this->postJson(self::URL, ['branch_id' => $this->branch->id, 'items' => []], $this->as('branch_manager', $other->id))->assertStatus(403);
        // a manager without any branch cannot sync any
        $this->sync([$this->row($medicine)], false, $this->as('branch_manager', null))->assertStatus(403);

        $this->assertSame(40, $inventory->fresh()->stock_quantity);
    }

    public function test_the_ceo_can_sync_any_branch_and_a_manager_their_own(): void
    {
        [$medicine] = $this->stocked('Amoxicillin');

        $this->sync([$this->row($medicine)], false, $this->as('ceo'))->assertOk()->assertJsonPath('data.accepted', 1);
        $this->sync([$this->row($medicine, ['quantity' => 61])], false, $this->as('branch_manager', $this->branch->id))->assertOk()->assertJsonPath('data.accepted', 1);
    }

    public function test_an_unknown_branch_is_404_for_the_ceo(): void
    {
        [$medicine] = $this->stocked('Amoxicillin');

        $this->sync([$this->row($medicine)], false, $this->as('ceo'), 9999)->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_the_request_shape_is_validated(): void
    {
        $headers = $this->as('branch_manager', $this->branch->id);

        $this->postJson(self::URL, ['items' => [['generic_name' => 'x']]], $headers)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['branch_id']]]);
        $this->postJson(self::URL, ['branch_id' => $this->branch->id], $headers)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['items']]]);
        $this->postJson(self::URL, ['branch_id' => $this->branch->id, 'items' => []], $headers)->assertStatus(422);
        $this->postJson(self::URL, ['branch_id' => $this->branch->id, 'items' => ['not a row']], $headers)->assertStatus(422);
        $this->postJson(self::URL, ['branch_id' => $this->branch->id, 'items' => array_fill(0, 501, ['generic_name' => 'x'])], $headers)->assertStatus(422);
        $this->postJson(self::URL, ['branch_id' => $this->branch->id, 'strict' => 'maybe', 'items' => [['generic_name' => 'x']]], $headers)->assertStatus(422);
    }

    // ------------------------------------------------------------ applying a feed

    public function test_a_valid_feed_sets_absolute_values_bumps_the_version_recomputes_the_status_and_writes_movements(): void
    {
        [$medicine, $inventory] = $this->stocked('Amoxicillin', 40, 0, 20);
        $date = now()->addYears(2)->toDateString();

        $response = $this->sync([$this->row($medicine, ['quantity' => 5, 'price' => '12.50', 'batch_no' => 'B-27', 'expiration_date' => $date, 'min_threshold' => 15])])
            ->assertOk()
            ->assertJsonPath('data.accepted', 1)
            ->assertJsonPath('data.rejected', 0)
            ->assertJsonPath('data.unchanged', 0)
            ->assertJsonPath('data.errors', [])
            ->assertJsonPath('request_id', 'req-sync-0001');

        $row = $inventory->fresh();
        $this->assertSame(5, $row->stock_quantity);
        $this->assertSame('12.50', $row->price);
        $this->assertSame('B-27', $row->batch_no);
        $this->assertSame($date, $row->expiration_date->toDateString());
        $this->assertSame(15, $row->min_threshold);
        $this->assertSame('low_stock', $row->status);   // 5 < 15
        $this->assertSame(2, $row->version);

        $movement = StockMovement::firstOrFail();
        $this->assertSame([$inventory->id, -35, 'sync', 42, 'req-sync-0001'], [$movement->branch_inventory_id, $movement->delta, $movement->reason, $movement->actor_id, $movement->request_id]);
        $this->assertEqualsCanonicalizing(['accepted', 'rejected', 'unchanged', 'errors'], array_keys($response->json('data')));
    }

    public function test_the_threshold_is_kept_when_the_feed_does_not_give_one_and_status_follows_the_quantity(): void
    {
        [$medicine, $inventory] = $this->stocked('Amoxicillin', 40, 0, 20);

        $this->sync([$this->row($medicine, ['quantity' => 0])])->assertOk();
        $this->assertSame([20, 'out_of_stock'], [$inventory->fresh()->min_threshold, $inventory->fresh()->status]);

        $this->sync([$this->row($medicine, ['quantity' => 19])])->assertOk();
        $this->assertSame([20, 'low_stock'], [$inventory->fresh()->min_threshold, $inventory->fresh()->status]);

        $this->sync([$this->row($medicine, ['quantity' => 20])])->assertOk();
        $this->assertSame('available', $inventory->fresh()->status);
        $this->assertSame(4, $inventory->fresh()->version);
    }

    public function test_medicines_are_matched_by_natural_key_ignoring_case_and_an_empty_brand_matches_the_stored_empty_string(): void
    {
        $unbranded = $this->makeMedicine('Losartan', '', 'Hypertension', true, '50mg', 'Tablet');
        $branded = $this->makeMedicine('Ibuprofen', 'Advil', 'Pain Relievers', false, '200mg', 'Capsule');
        $a = $this->makeInventory($this->branch, $unbranded, 10);
        $b = $this->makeInventory($this->branch, $branded, 10);

        $this->sync([
            $this->row($unbranded, ['generic_name' => '  LOSARTAN ', 'brand_name' => '', 'strength' => '50MG', 'dosage_form' => 'tablet', 'quantity' => 11], 1),
            $this->row($branded, ['generic_name' => 'ibuprofen', 'brand_name' => 'ADVIL', 'strength' => '200mg', 'dosage_form' => 'CAPSULE', 'quantity' => 12], 2),
        ])->assertOk()->assertJsonPath('data.accepted', 2)->assertJsonPath('data.rejected', 0);

        $this->assertSame([11, 12], [$a->fresh()->stock_quantity, $b->fresh()->stock_quantity]);
    }

    public function test_a_wrong_brand_strength_or_form_is_an_unknown_medicine_and_no_medicine_is_ever_created(): void
    {
        $medicine = $this->makeMedicine('Ibuprofen', 'Advil', 'Pain Relievers', false, '200mg', 'Capsule');
        $this->makeInventory($this->branch, $medicine, 10);
        $before = Medicine::count();

        $response = $this->sync([
            $this->row($medicine, ['brand_name' => ''], 1),            // brand differs
            $this->row($medicine, ['strength' => '400mg'], 2),         // strength differs
            $this->row($medicine, ['dosage_form' => 'Tablet'], 3),     // form differs
            $this->row($medicine, ['generic_name' => 'Unobtainium'], 4),
            ['row' => 5],                                              // a row with no medicine data at all
        ])->assertOk();

        $this->assertSame(5, $response->json('data.rejected'));
        $this->assertSame(['UNKNOWN_MEDICINE'], array_values(array_unique(array_column($response->json('data.errors'), 'code'))));
        $this->assertSame($before, Medicine::count());
        $this->assertSame(10, BranchInventory::first()->stock_quantity);
    }

    public function test_each_row_code_is_reported_and_good_rows_are_still_applied(): void
    {
        [$good, $goodRow] = $this->stocked('Good Med', 40);
        [$qty] = $this->stocked('Qty Med');
        [$price] = $this->stocked('Price Med');
        [$date] = $this->stocked('Date Med');
        [$reserved, $reservedRow] = $this->stocked('Reserved Med', 40, 25);

        $response = $this->sync([
            $this->row($good, ['quantity' => 77], 1),
            $this->row($qty, ['quantity' => -1], 2),
            $this->row($price, ['price' => '0'], 3),
            $this->row($date, ['expiration_date' => now()->subDay()->toDateString()], 4),
            $this->row($reserved, ['quantity' => 24], 5),   // below the 25 reserved
            $this->row($good, ['quantity' => 5], 6),        // the same medicine again
        ])->assertOk()->assertJsonPath('data.accepted', 1)->assertJsonPath('data.rejected', 5);

        $this->assertSame(
            [2 => 'INVALID_QUANTITY', 3 => 'INVALID_PRICE', 4 => 'PAST_EXPIRATION', 5 => 'BELOW_RESERVED', 6 => 'DUPLICATE_ITEM'],
            collect($response->json('data.errors'))->pluck('code', 'row')->all()
        );
        foreach ($response->json('data.errors') as $error) {
            $this->assertNotSame('', $error['message']);
        }

        $this->assertSame(77, $goodRow->fresh()->stock_quantity, 'the first occurrence of a duplicated medicine is applied');
        $this->assertSame(40, $reservedRow->fresh()->stock_quantity, 'a rejected row changes nothing');
        $this->assertSame(1, StockMovement::count());
    }

    public function test_invalid_quantity_price_date_and_other_values_are_each_rejected(): void
    {
        [$m] = $this->stocked('Value Med');
        $future = now()->addYear()->toDateString();

        $cases = [
            'quantity text' => [['quantity' => 'abc'], 'INVALID_QUANTITY'],
            'quantity decimal' => [['quantity' => 1.5], 'INVALID_QUANTITY'],
            'quantity decimal text' => [['quantity' => '1.5'], 'INVALID_QUANTITY'],
            'quantity too large' => [['quantity' => 2000000], 'INVALID_QUANTITY'],
            'quantity null' => [['quantity' => null], 'INVALID_QUANTITY'],
            'quantity bool' => [['quantity' => true], 'INVALID_QUANTITY'],
            'price negative' => [['price' => '-1.00'], 'INVALID_PRICE'],
            'price text' => [['price' => 'free'], 'INVALID_PRICE'],
            'price three decimals' => [['price' => '1.999'], 'INVALID_PRICE'],
            'price empty' => [['price' => ''], 'INVALID_PRICE'],
            'price too large' => [['price' => '1000000000'], 'INVALID_PRICE'],
            'expiry today' => [['expiration_date' => now()->toDateString()], 'PAST_EXPIRATION'],
            'expiry impossible' => [['expiration_date' => '2028-13-45'], 'PAST_EXPIRATION'],
            'expiry wrong format' => [['expiration_date' => '31/12/2030'], 'PAST_EXPIRATION'],
            'expiry empty' => [['expiration_date' => ''], 'PAST_EXPIRATION'],
            'threshold negative' => [['min_threshold' => -1], 'INVALID_MIN_THRESHOLD'],
            'threshold text' => [['min_threshold' => 'x'], 'INVALID_MIN_THRESHOLD'],
            'batch too long' => [['batch_no' => str_repeat('x', 51)], 'INVALID_BATCH_NO'],
        ];

        foreach ($cases as $name => [$override, $code]) {
            $response = $this->sync([$this->row($m, $override + ['expiration_date' => $future])])->assertOk();
            $this->assertSame($code, $response->json('data.errors.0.code'), $name);
            $this->assertSame(1, $response->json('data.rejected'), $name);
        }

        $this->assertSame(40, BranchInventory::first()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_boundary_values_are_accepted(): void
    {
        [$m, $row] = $this->stocked('Edge Med', 40, 0);

        $this->sync([$this->row($m, ['quantity' => 0, 'price' => '0.01', 'expiration_date' => now()->addDay()->toDateString()])])
            ->assertOk()->assertJsonPath('data.accepted', 1);
        $this->sync([$this->row($m, ['quantity' => '1000000', 'price' => '99999999.99'])])->assertOk()->assertJsonPath('data.accepted', 1);

        $this->assertSame(1000000, $row->fresh()->stock_quantity);
    }

    public function test_a_quantity_exactly_equal_to_the_reserved_stock_is_allowed(): void
    {
        [$m, $row] = $this->stocked('Reserved Med', 40, 25);

        $this->sync([$this->row($m, ['quantity' => 25])])->assertOk()->assertJsonPath('data.accepted', 1);
        $this->assertSame(25, $row->fresh()->stock_quantity);
        $this->assertSame(25, $row->fresh()->reserved_quantity, 'reserved stock is never touched by a feed');
    }

    // ------------------------------------------------------------ strict and non-strict

    public function test_strict_mode_applies_nothing_when_any_row_is_rejected(): void
    {
        [$good, $goodRow] = $this->stocked('Good Med', 40);
        [$reserved, $reservedRow] = $this->stocked('Reserved Med', 40, 25);
        $before = $this->snapshot();

        $this->sync([$this->row($good, ['quantity' => 77], 1), $this->row($reserved, ['quantity' => 5], 2)], true)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FEED_REJECTED')
            ->assertJsonPath('data', null)
            ->assertJsonPath('error.details.accepted', 0)
            ->assertJsonPath('error.details.rejected', 1)
            ->assertJsonPath('error.details.errors.0.code', 'BELOW_RESERVED')
            ->assertJsonPath('error.details.errors.0.row', 2);

        $this->assertSame($before, $this->snapshot(), 'not even the good row was applied');
        $this->assertSame(0, StockMovement::count());
    }

    public function test_strict_mode_also_rejects_everything_for_an_unknown_medicine(): void
    {
        [$good, $goodRow] = $this->stocked('Good Med', 40);

        $this->sync([$this->row($good, ['quantity' => 77], 1), $this->row($good, ['generic_name' => 'Nope'], 2)], true)
            ->assertStatus(422)->assertJsonPath('error.code', 'FEED_REJECTED');

        $this->assertSame(40, $goodRow->fresh()->stock_quantity);
    }

    public function test_strict_mode_applies_everything_when_all_rows_are_good(): void
    {
        [$a, $aRow] = $this->stocked('A Med', 40);
        [$b, $bRow] = $this->stocked('B Med', 40);

        $this->sync([$this->row($a, ['quantity' => 1], 1), $this->row($b, ['quantity' => 2], 2)], true)->assertOk()->assertJsonPath('data.accepted', 2);

        $this->assertSame([1, 2], [$aRow->fresh()->stock_quantity, $bRow->fresh()->stock_quantity]);
    }

    public function test_non_strict_mode_applies_the_good_rows_and_reports_the_bad_ones(): void
    {
        [$a, $aRow] = $this->stocked('A Med', 40);
        [$b, $bRow] = $this->stocked('B Med', 40);

        $this->sync([$this->row($a, ['quantity' => 1], 1), $this->row($b, ['price' => '0'], 2)], false)
            ->assertOk()->assertJsonPath('data.accepted', 1)->assertJsonPath('data.rejected', 1)->assertJsonPath('data.errors.0.row', 2);

        $this->assertSame([1, 40], [$aRow->fresh()->stock_quantity, $bRow->fresh()->stock_quantity]);
    }

    // ------------------------------------------------------------ idempotence

    public function test_sending_the_same_feed_again_changes_nothing(): void
    {
        [$a] = $this->stocked('A Med', 40);
        [$b] = $this->stocked('B Med', 7, 0, 10);
        $feed = [$this->row($a, ['quantity' => 50], 1), $this->row($b, ['quantity' => 60, 'min_threshold' => 15], 2)];

        $this->sync($feed)->assertOk()->assertJsonPath('data.accepted', 2)->assertJsonPath('data.unchanged', 0);
        $after = $this->snapshot();
        $movements = StockMovement::count();
        $this->assertSame(2, $movements);

        $second = $this->sync($feed)->assertOk();
        $this->assertSame([2, 0, 2], [$second->json('data.accepted'), $second->json('data.rejected'), $second->json('data.unchanged')]);
        $this->sync($feed)->assertOk()->assertJsonPath('data.unchanged', 2);

        $this->assertSame($after, $this->snapshot(), 'same values, same versions');
        $this->assertSame($movements, StockMovement::count(), 'no new movements');
    }

    public function test_a_price_only_change_bumps_the_version_and_writes_a_zero_delta_movement(): void
    {
        [$m, $row] = $this->stocked('Price Med', 40);

        $this->sync([$this->row($m, ['quantity' => 40, 'price' => '11.00', 'batch_no' => 'B-1', 'expiration_date' => $row->expiration_date->toDateString()])])
            ->assertOk()->assertJsonPath('data.unchanged', 0);

        $this->assertSame(2, $row->fresh()->version);
        $this->assertSame('11.00', $row->fresh()->price);
        $this->assertSame(0, StockMovement::firstOrFail()->delta);
    }

    // ------------------------------------------------------------ first stock entry

    public function test_a_medicine_without_a_stock_row_gets_one_like_the_put_endpoint_creates_it(): void
    {
        $medicine = $this->makeMedicine('New Med');

        $this->sync([$this->row($medicine, ['quantity' => 30])])->assertOk()->assertJsonPath('data.accepted', 1);

        $row = BranchInventory::where('medicine_id', $medicine->id)->firstOrFail();
        $this->assertSame([30, 0, 10, 1, 'available'], [$row->stock_quantity, $row->reserved_quantity, $row->min_threshold, $row->version, $row->status]);

        $movement = StockMovement::firstOrFail();
        $this->assertSame([30, 'sync'], [$movement->delta, $movement->reason]);

        $this->sync([$this->row($medicine, ['quantity' => 30])])->assertOk()->assertJsonPath('data.unchanged', 1);
        $this->assertSame(1, BranchInventory::count());
    }

    public function test_the_feed_only_touches_the_stated_branch(): void
    {
        $other = $this->makeBranch('Other Branch');
        [$m, $own] = $this->stocked('Shared Med', 40);
        $elsewhere = $this->makeInventory($other, $m, 99);

        $this->sync([$this->row($m, ['quantity' => 1])])->assertOk();

        $this->assertSame([1, 99], [$own->fresh()->stock_quantity, $elsewhere->fresh()->stock_quantity]);
    }

    // ------------------------------------------------------------ the real samples and the real seeders

    public function test_the_sample_json_feed_matches_the_seeded_medicines_and_is_idempotent(): void
    {
        $this->branch->delete(); // setUp made a branch with id 1; the real seeder creates the real branch 1
        $this->seed([BranchSeeder::class, MedicineSeeder::class, BranchInventorySeeder::class]);
        $feed = json_decode((string) file_get_contents(base_path('../../docs/samples/inventory-feed.json')), true);
        $this->assertSame(1, $feed['branch_id']);
        $manager = $this->as('branch_manager', 1, 42);

        $first = $this->postJson(self::URL, ['branch_id' => 1, 'strict' => true, 'items' => $feed['items']], $manager)->assertOk();
        $this->assertSame([8, 0], [$first->json('data.accepted'), $first->json('data.rejected')], 'every sample item matches a seeded medicine');

        $amoxicillin = BranchInventory::where('branch_id', 1)->whereHas('medicine', fn ($q) => $q->where('generic_name', 'Amoxicillin'))->firstOrFail();
        $this->assertSame([60, '10.50', 'B27-AMOX-01'], [$amoxicillin->stock_quantity, $amoxicillin->price, $amoxicillin->batch_no]);
        $this->assertSame('2028-06-30', $amoxicillin->expiration_date->toDateString());

        $second = $this->postJson(self::URL, ['branch_id' => 1, 'strict' => true, 'items' => $feed['items']], $manager)->assertOk();
        $this->assertSame([8, 0, 8], [$second->json('data.accepted'), $second->json('data.rejected'), $second->json('data.unchanged')]);
    }
}
