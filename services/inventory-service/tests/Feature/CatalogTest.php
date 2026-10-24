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
