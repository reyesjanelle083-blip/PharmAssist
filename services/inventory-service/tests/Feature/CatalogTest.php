<?php

namespace Tests\Feature;

use App\Models\Medicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryTestHelpers;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use InventoryTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
    }

    public function test_missing_or_wrong_internal_key_returns_401(): void
    {
        $this->getJson('/internal/v1/medicines')->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
        $this->getJson('/internal/v1/branches', ['X-Internal-Key' => 'wrong'])->assertStatus(401);
        $this->getJson('/internal/v1/medicines/1', ['X-Internal-Key' => 'wrong'])->assertStatus(401);
    }

    public function test_search_matches_generic_and_brand_name(): void
    {
        $this->makeMedicine('Amoxicillin');
        $this->makeMedicine('Ibuprofen', 'Advil', 'Pain Relievers', false, '200mg');
        $this->makeMedicine('Paracetamol', 'Biogesic', 'Pain Relievers', false);

        $byGeneric = $this->getJson('/internal/v1/medicines?q=amox', $this->internal())->assertOk();
        $this->assertSame(['Amoxicillin'], array_column($byGeneric->json('data.items'), 'generic_name'));

        $byBrand = $this->getJson('/internal/v1/medicines?q=advil', $this->internal())->assertOk();
        $this->assertSame(['Ibuprofen'], array_column($byBrand->json('data.items'), 'generic_name'));
        $this->assertSame('Advil', $byBrand->json('data.items.0.brand_name'));

        $none = $this->getJson('/internal/v1/medicines?q=nothingmatches', $this->internal())->assertOk();
        $this->assertSame([], $none->json('data.items'));
        $this->assertSame(0, $none->json('data.meta.total'));
    }

    public function test_search_treats_like_wildcards_as_plain_text(): void
    {
        $this->makeMedicine('Amoxicillin');

        $this->getJson('/internal/v1/medicines?q=%25', $this->internal())->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson('/internal/v1/medicines?q=_', $this->internal())->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_filters_by_category_and_prescription(): void
    {
        $this->makeMedicine('Amoxicillin', '', 'Antibiotics', true);
        $this->makeMedicine('Ibuprofen', 'Advil', 'Pain Relievers', false, '200mg');
        $this->makeMedicine('Mefenamic Acid', '', 'Pain Relievers', true);

        $category = $this->getJson('/internal/v1/medicines?category=Pain%20Relievers', $this->internal())->assertOk();
        $this->assertEqualsCanonicalizing(['Ibuprofen', 'Mefenamic Acid'], array_column($category->json('data.items'), 'generic_name'));

        $rx = $this->getJson('/internal/v1/medicines?rx=true', $this->internal())->assertOk();
        $this->assertEqualsCanonicalizing(['Amoxicillin', 'Mefenamic Acid'], array_column($rx->json('data.items'), 'generic_name'));

        $otc = $this->getJson('/internal/v1/medicines?rx=false', $this->internal())->assertOk();
        $this->assertSame(['Ibuprofen'], array_column($otc->json('data.items'), 'generic_name'));

        $both = $this->getJson('/internal/v1/medicines?rx=true&category=Pain%20Relievers', $this->internal())->assertOk();
        $this->assertSame(['Mefenamic Acid'], array_column($both->json('data.items'), 'generic_name'));
    }

    public function test_pagination_returns_items_and_meta(): void
    {
        foreach (range(1, 25) as $i) {
            $this->makeMedicine(sprintf('Medicine %02d', $i));
        }

        $page1 = $this->getJson('/internal/v1/medicines?per_page=10', $this->internal())->assertOk();
        $this->assertCount(10, $page1->json('data.items'));
        $this->assertSame(['page' => 1, 'per_page' => 10, 'total' => 25, 'last_page' => 3], $page1->json('data.meta'));

        $page3 = $this->getJson('/internal/v1/medicines?per_page=10&page=3', $this->internal())->assertOk();
        $this->assertCount(5, $page3->json('data.items'));
        $this->assertSame(3, $page3->json('data.meta.page'));

        $default = $this->getJson('/internal/v1/medicines', $this->internal())->assertOk();
        $this->assertSame(20, $default->json('data.meta.per_page'));
    }

    public function test_invalid_query_values_return_422(): void
    {
        $this->getJson('/internal/v1/medicines?q='.str_repeat('a', 101), $this->internal())
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['q']]]);
        $this->getJson('/internal/v1/medicines?per_page=101', $this->internal())->assertStatus(422);
        $this->getJson('/internal/v1/medicines?page=0', $this->internal())->assertStatus(422);
        $this->getJson('/internal/v1/medicines?rx=maybe', $this->internal())->assertStatus(422);
    }

    public function test_empty_brand_name_is_returned_as_null_and_the_shape_matches_the_contract(): void
    {
        $unbranded = $this->makeMedicine('Losartan', '', 'Hypertension', true, '50mg', 'Tablet');

        $response = $this->getJson('/internal/v1/medicines/'.$unbranded->id, $this->internal())->assertOk();

        $this->assertNull($response->json('data.brand_name'));
        $this->assertSame(
            ['brand_name', 'category', 'dosage_form', 'generic_name', 'id', 'image_url', 'prescription_required', 'strength'],
            collect(array_keys($response->json('data')))->sort()->values()->all()
        );
        $this->assertTrue($response->json('data.prescription_required'));
        $this->assertSame('', Medicine::find($unbranded->id)->brand_name); // stored as '' in the database

        $list = $this->getJson('/internal/v1/medicines', $this->internal())->assertOk();
        $this->assertNull($list->json('data.items.0.brand_name'));
    }

    public function test_unknown_medicine_and_non_numeric_ids_return_404(): void
    {
        $this->getJson('/internal/v1/medicines/9999', $this->internal())->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/internal/v1/medicines/abc', $this->internal())->assertStatus(404);
        $this->getJson('/internal/v1/branches/9999', $this->internal())->assertStatus(404);
    }

    public function test_counseling_returns_the_stored_summary_with_the_contract_shape(): void
    {
        $medicine = $this->makeMedicine('Paracetamol', 'Biogesic', 'Pain Relievers', false);
        $medicine->update(['counseling_summary' => 'Relieves mild pain and fever.']);

        $this->getJson('/internal/v1/medicines/'.$medicine->id.'/counseling', $this->internal())
            ->assertOk()
            ->assertJsonPath('data.medicine_id', $medicine->id)
            ->assertJsonPath('data.summary', 'Relieves mild pain and fever.')
            ->assertJsonPath('data.how_to_use', null)
            ->assertJsonPath('data.warnings', null)
            ->assertJsonPath('data.source', 'internal');
    }

    public function test_counseling_is_null_not_an_error_when_the_medicine_has_no_text(): void
    {
        $none = $this->makeMedicine('Amoxicillin');
        $none->update(['counseling_summary' => null]);
        $blank = $this->makeMedicine('Ibuprofen', 'Advil', 'Pain Relievers', false, '200mg');
        $blank->update(['counseling_summary' => '   ']);

        $this->getJson('/internal/v1/medicines/'.$none->id.'/counseling', $this->internal())->assertOk()->assertJsonPath('data.summary', null);
        $this->getJson('/internal/v1/medicines/'.$blank->id.'/counseling', $this->internal())->assertOk()->assertJsonPath('data.summary', null);
    }

    public function test_counseling_for_an_unknown_or_non_numeric_id_is_404_and_needs_the_internal_key(): void
    {
        $this->getJson('/internal/v1/medicines/9999/counseling', $this->internal())->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/internal/v1/medicines/abc/counseling', $this->internal())->assertStatus(404);
        $medicine = $this->makeMedicine();
        $this->getJson('/internal/v1/medicines/'.$medicine->id.'/counseling')->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
    }

    public function test_categories_lists_each_category_once_in_order(): void
    {
        $this->makeMedicine('Amoxicillin', '', 'Antibiotics');
        $this->makeMedicine('Ibuprofen', 'Advil', 'Pain Relievers', false, '200mg');
        $this->makeMedicine('Mefenamic Acid', '', 'Pain Relievers');

        $this->getJson('/internal/v1/medicines/categories', $this->internal())
            ->assertOk()->assertJsonPath('data.items', ['Antibiotics', 'Pain Relievers']);
    }

    // ---------------------------------------------------------------- branch-aware browse (include=availability, branch_id)

    /** Amoxicillin: stocked at open1 (35 free of 40), open2 (low), not free at full (all reserved), inactive and expired. Ibuprofen: nowhere. */
    private function stockedCatalog(): array
    {
        $open1 = $this->makeBranch('Open One');
        $open2 = $this->makeBranch('Open Two');
        $full = $this->makeBranch('Full');
        $closed = $this->makeBranch('Closed', false);
        $old = $this->makeBranch('Old Batch');

        $amox = $this->makeMedicine('Amoxicillin');
        $ibu = $this->makeMedicine('Ibuprofen', 'Advil', 'Pain Relievers', false);

        $this->makeInventory($open1, $amox, 40, 5);
        $this->makeInventory($open2, $amox, 8, 0, 20); // low stock
        $this->makeInventory($full, $amox, 10, 10);
        $this->makeInventory($closed, $amox, 50);
        $this->makeInventory($old, $amox, 50)->update(['expiration_date' => now()->subDay()->toDateString()]);
        $this->makeInventory($open1, $ibu, 0, 0); // out of stock where it is listed

        return compact('open1', 'open2', 'amox', 'ibu');
    }

    public function test_the_catalog_has_no_availability_unless_asked_for(): void
    {
        $this->stockedCatalog();

        $items = $this->getJson('/internal/v1/medicines', $this->internal())->assertOk()->json('data.items');

        $this->assertCount(2, $items);
        $this->assertArrayNotHasKey('availability', $items[0]);
    }

    public function test_include_availability_counts_only_branches_with_free_stock_that_are_active_and_unexpired(): void
    {
        $s = $this->stockedCatalog();

        $items = collect($this->getJson('/internal/v1/medicines?include=availability', $this->internal())->assertOk()->json('data.items'))->keyBy('generic_name');

        $amox = $items['Amoxicillin']['availability'];
        $this->assertSame(2, $amox['branch_count']);
        $this->assertSame(43, $amox['total_available']); // 35 + 8
        $this->assertSame([
            ['branch_id' => $s['open1']->id, 'available_quantity' => 35, 'status' => 'available'],
            ['branch_id' => $s['open2']->id, 'available_quantity' => 8, 'status' => 'low_stock'],
        ], $amox['branches']);

        $this->assertSame(['branch_count' => 0, 'total_available' => 0, 'branches' => []], $items['Ibuprofen']['availability']);
    }

    public function test_availability_never_exposes_stock_or_reserved_counts(): void
    {
        $this->stockedCatalog();

        $raw = $this->getJson('/internal/v1/medicines?include=availability', $this->internal())->assertOk()->getContent();

        $this->assertStringNotContainsString('reserved', $raw);
        $this->assertStringNotContainsString('stock_quantity', $raw);
    }

    public function test_the_branch_filter_keeps_only_medicines_that_branch_has_free_stock_of(): void
    {
        $s = $this->stockedCatalog();

        $open1 = $this->getJson('/internal/v1/medicines?branch_id='.$s['open1']->id, $this->internal())->assertOk();
        $this->assertSame(['Amoxicillin'], array_column($open1->json('data.items'), 'generic_name')); // Ibuprofen is 0 there
        $this->assertSame(1, $open1->json('data.meta.total'));

        // the filter does not hide where else it is stocked
        $withAvailability = $this->getJson('/internal/v1/medicines?include=availability&branch_id='.$s['open2']->id, $this->internal())->assertOk();
        $this->assertSame(2, $withAvailability->json('data.items.0.availability.branch_count'));
    }

    public function test_the_branch_filter_gives_nothing_for_branches_with_no_sellable_stock(): void
    {
        $this->stockedCatalog();
        $ids = \App\Models\Branch::whereIn('name', ['Full', 'Closed', 'Old Batch'])->pluck('id');

        foreach ([...$ids, 9999] as $id) {
            $this->getJson("/internal/v1/medicines?branch_id={$id}", $this->internal())->assertOk()->assertJsonPath('data.meta.total', 0);
        }
    }

    public function test_the_branch_filter_combines_with_the_other_filters(): void
    {
        $s = $this->stockedCatalog();

        $this->getJson("/internal/v1/medicines?branch_id={$s['open1']->id}&rx=false", $this->internal())->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson("/internal/v1/medicines?branch_id={$s['open1']->id}&rx=true&q=amox", $this->internal())->assertOk()->assertJsonPath('data.meta.total', 1);
    }

    public function test_bad_availability_options_are_422(): void
    {
        $this->getJson('/internal/v1/medicines?include=everything', $this->internal())->assertStatus(422);
        $this->getJson('/internal/v1/medicines?branch_id=0', $this->internal())->assertStatus(422);
        $this->getJson('/internal/v1/medicines?branch_id=abc', $this->internal())->assertStatus(422);
    }

    public function test_inactive_branches_are_hidden_from_public_lists_and_detail(): void
    {
        $active = $this->makeBranch('Active Branch');
        $hidden = $this->makeBranch('Closed Branch', false);

        $list = $this->getJson('/internal/v1/branches', $this->internal())->assertOk();
        $this->assertSame(['Active Branch'], array_column($list->json('data.items'), 'name'));

        $this->getJson('/internal/v1/branches/'.$active->id, $this->internal())
            ->assertOk()->assertJsonPath('data.name', 'Active Branch')->assertJsonPath('data.latitude', 14.6);
        $this->getJson('/internal/v1/branches/'.$hidden->id, $this->internal())->assertStatus(404);
    }
}
