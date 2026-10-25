<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryTestHelpers;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use InventoryTestHelpers;
    use RefreshDatabase;

    private const URL = '/internal/v1/inventory/availability';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
    }

    private function find(string $query)
    {
        return $this->getJson(self::URL.'?'.$query, $this->internal());
    }

    private function stock(Branch $branch, Medicine $medicine, int $stock = 10, int $reserved = 0, string $price = '10.00', ?string $expires = null): BranchInventory
    {
        $row = $this->makeInventory($branch, $medicine, $stock, $reserved, 2, $price);
        if ($expires !== null) {
            $row->update(['expiration_date' => $expires]);
        }

        return $row;
    }

    public function test_only_free_stock_is_listed_with_the_documented_fields(): void
    {
        $branch = $this->makeBranch('Alpha');
        $medicine = $this->makeMedicine('Paracetamol', 'Biogesic', 'Pain', false, '500mg', 'Tablet');
        $this->stock($branch, $medicine, 10, 4, '12.50');

        $response = $this->find('medicine_id='.$medicine->id)->assertOk();

        $this->assertEquals([[
            'branch_id' => $branch->id,
            'branch_name' => 'Alpha',
            'address' => '1 Test St.',
            'latitude' => 14.6,
            'longitude' => 121.0,
            'medicine_id' => $medicine->id,
            'medicine_name' => 'Paracetamol (Biogesic)',
            'strength' => '500mg',
            'dosage_form' => 'Tablet',
            'prescription_required' => false,
            'price' => '12.50',
            'available_quantity' => 6,
            'status' => 'available',
        ]], $response->json('data.items'));
    }

    public function test_no_stock_counts_reserved_amounts_or_versions_are_ever_returned(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $this->stock($branch, $medicine, 99, 3);

        $raw = $this->find('medicine_id='.$medicine->id)->assertOk()->getContent();

        foreach (['reserved', 'stock_quantity', 'version', 'min_threshold', 'batch_no', 'expiration'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertStringNotContainsString('"96"', $raw);
    }

    public function test_a_row_with_everything_reserved_is_excluded(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $this->stock($branch, $medicine, 5, 5);

        $this->find('medicine_id='.$medicine->id)->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_inactive_branches_are_excluded(): void
    {
        $active = $this->makeBranch('Active');
        $inactive = $this->makeBranch('Closed', false);
        $medicine = $this->makeMedicine();
        $this->stock($active, $medicine);
        $this->stock($inactive, $medicine);

        $items = $this->find('medicine_id='.$medicine->id)->assertOk()->json('data.items');

        $this->assertSame([$active->id], array_column($items, 'branch_id'));
    }

    public function test_expired_stock_is_excluded_but_today_is_still_good(): void
    {
        $medicine = $this->makeMedicine();
        $expired = $this->makeBranch('Expired');
        $today = $this->makeBranch('Today');
        $future = $this->makeBranch('Future');
        $this->stock($expired, $medicine, 10, 0, '10.00', now()->subDay()->toDateString());
        $this->stock($today, $medicine, 10, 0, '10.00', now()->toDateString());
        $this->stock($future, $medicine, 10, 0, '10.00', now()->addYear()->toDateString());

        $items = $this->find('medicine_id='.$medicine->id)->assertOk()->json('data.items');

        $this->assertSame([$today->id, $future->id], array_column($items, 'branch_id'));
    }

    public function test_search_by_name_matches_generic_and_brand_case_insensitively(): void
    {
        $branch = $this->makeBranch();
        $biogesic = $this->makeMedicine('Paracetamol', 'Biogesic', 'Pain', false);
        $amox = $this->makeMedicine('Amoxicillin', '', 'Antibiotics', true);
        $this->stock($branch, $biogesic);
        $this->stock($branch, $amox);

        $this->assertSame([$biogesic->id], array_column($this->find('q=biogesic')->assertOk()->json('data.items'), 'medicine_id'));
        $this->assertSame([$biogesic->id], array_column($this->find('q=PARACET')->assertOk()->json('data.items'), 'medicine_id'));
        $this->assertSame([$amox->id], array_column($this->find('q=amoxi')->assertOk()->json('data.items'), 'medicine_id'));
        $this->find('q=nothing-like-this')->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_like_wildcards_in_q_are_literal(): void
    {
        $branch = $this->makeBranch();
        $this->stock($branch, $this->makeMedicine('Paracetamol'));

        $this->find('q='.urlencode('%'))->assertOk()->assertJsonPath('data.items', []);
        $this->find('q='.urlencode('_'))->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_medicine_id_wins_over_q_and_an_unknown_id_is_404(): void
    {
        $branch = $this->makeBranch();
        $a = $this->makeMedicine('Alpha');
        $b = $this->makeMedicine('Beta');
        $this->stock($branch, $a);
        $this->stock($branch, $b);

        $this->assertSame([$a->id], array_column($this->find("medicine_id={$a->id}&q=Beta")->assertOk()->json('data.items'), 'medicine_id'));
        $this->find('medicine_id=99999')->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_a_known_medicine_nobody_stocks_is_200_with_an_empty_list(): void
    {
        $medicine = $this->makeMedicine();

        $this->find('medicine_id='.$medicine->id)->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_at_most_five_medicines_are_searched(): void
    {
        $branch = $this->makeBranch();
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $letter) {
            $this->stock($branch, $this->makeMedicine("Common {$letter}"));
        }

        $items = $this->find('q=Common&limit=20')->assertOk()->json('data.items');

        $this->assertSame(['Common A', 'Common B', 'Common C', 'Common D', 'Common E'], array_column($items, 'medicine_name'));
    }

    public function test_the_limit_defaults_to_ten_and_is_capped_at_twenty(): void
    {
        $medicine = $this->makeMedicine();
        for ($i = 1; $i <= 25; $i++) {
            $this->stock($this->makeBranch(sprintf('Branch %02d', $i)), $medicine);
        }

        $this->assertCount(10, $this->find('medicine_id='.$medicine->id)->assertOk()->json('data.items'));
        $this->assertCount(3, $this->find('medicine_id='.$medicine->id.'&limit=3')->assertOk()->json('data.items'));
        $this->assertCount(20, $this->find('medicine_id='.$medicine->id.'&limit=20')->assertOk()->json('data.items'));
        $this->find('medicine_id='.$medicine->id.'&limit=21')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->find('medicine_id='.$medicine->id.'&limit=0')->assertStatus(422);
    }

    public function test_the_order_is_medicine_name_then_price_then_branch_id(): void
    {
        $b1 = $this->makeBranch('One');
        $b2 = $this->makeBranch('Two');
        $b3 = $this->makeBranch('Three');
        $zeta = $this->makeMedicine('Zeta Cough');
        $alpha = $this->makeMedicine('Alpha Cough');
        $this->stock($b1, $zeta, 10, 0, '5.00');
        $this->stock($b1, $alpha, 10, 0, '9.00');
        $this->stock($b2, $alpha, 10, 0, '7.00');
        $this->stock($b3, $alpha, 10, 0, '7.00');

        $items = $this->find('q=Cough')->assertOk()->json('data.items');

        $this->assertSame(
            [[$alpha->id, $b2->id, '7.00'], [$alpha->id, $b3->id, '7.00'], [$alpha->id, $b1->id, '9.00'], [$zeta->id, $b1->id, '5.00']],
            array_map(fn ($i) => [$i['medicine_id'], $i['branch_id'], $i['price']], $items)
        );
        $this->assertEquals($items, $this->find('q=Cough')->json('data.items'), 'stable between calls');
    }

    public function test_low_stock_is_reported_as_such(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $this->stock($branch, $medicine, 1, 0); // below the threshold of 2

        $this->find('medicine_id='.$medicine->id)->assertOk()->assertJsonPath('data.items.0.status', 'low_stock')->assertJsonPath('data.items.0.available_quantity', 1);
    }

    public function test_one_of_medicine_id_or_q_is_required_and_q_is_capped(): void
    {
        $this->find('')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->find('limit=5')->assertStatus(422);
        $this->find('q=')->assertStatus(422);
        $this->find('medicine_id=abc')->assertStatus(422);
        $this->find('medicine_id=0')->assertStatus(422);
        $this->find('q='.str_repeat('a', 101))->assertStatus(422);
        $this->find('q='.str_repeat('a', 100))->assertOk();
    }

    public function test_the_internal_key_is_required(): void
    {
        $medicine = $this->makeMedicine();

        $this->getJson(self::URL.'?medicine_id='.$medicine->id)->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
        $this->getJson(self::URL.'?medicine_id='.$medicine->id, ['X-Internal-Key' => 'wrong'])->assertStatus(401);
    }
}
