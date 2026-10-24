<?php

namespace Tests\Feature;

use App\Models\BranchInventory;
use App\Models\StockAlert;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryTestHelpers;
use Tests\TestCase;

/**
 * None of these tests rely on the MySQL-only CHECK constraint: the rules are enforced by the service.
 */
class InventoryTest extends TestCase
{
    use InventoryTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
    }

    private function url(int $branchId, ?int $medicineId = null): string
    {
        return "/internal/v1/branches/{$branchId}/inventory".($medicineId ? "/{$medicineId}" : '');
    }

    private function validBody(array $overrides = []): array
    {
        return array_merge([
            'stock_quantity' => 55,
            'price' => '12.50',
            'min_threshold' => 60,
            'batch_no' => 'B-NEW',
            'expiration_date' => now()->addYears(2)->toDateString(),
            'version' => 1,
        ], $overrides);
    }

    // ------------------------------------------------------------ authentication

    public function test_missing_internal_key_returns_401(): void
    {
        $branch = $this->makeBranch();
        $this->getJson($this->url($branch->id))->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
        $this->putJson($this->url($branch->id, 1), $this->validBody())->assertStatus(401);
    }

    public function test_missing_or_malformed_user_headers_return_401(): void
    {
        $branch = $this->makeBranch();
        $keyOnly = ['X-Internal-Key' => $this->internalKey, 'Accept' => 'application/json'];

        $this->getJson($this->url($branch->id), $keyOnly)->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->putJson($this->url($branch->id, 1), $this->validBody(), $keyOnly)->assertStatus(401);

        $this->getJson($this->url($branch->id), $this->as('branch_manager', $branch->id, 7, ['X-User-Id' => 'abc']))->assertStatus(401);
        $this->getJson($this->url($branch->id), $this->as('branch_manager', $branch->id, 7, ['X-User-Role' => 'superadmin']))->assertStatus(401);
        $this->getJson($this->url($branch->id), $this->as('branch_manager', $branch->id, 7, ['X-User-Branch' => '1; DROP']))->assertStatus(401);
    }

    // ------------------------------------------------------------ authorization

    public function test_roles_that_may_not_read_inventory_get_403(): void
    {
        $branch = $this->makeBranch();

        $this->getJson($this->url($branch->id), $this->as('customer'))->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_only_technician_and_branch_manager_may_update(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $this->makeInventory($branch, $medicine);

        foreach (['customer' => null, 'pharmacy_assistant' => $branch->id, 'ceo' => null] as $role => $branchId) {
            $this->putJson($this->url($branch->id, $medicine->id), $this->validBody(), $this->as($role, $branchId))
                ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        }

        $this->assertSame(40, BranchInventory::first()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_staff_of_another_branch_get_403_for_read_and_update(): void
    {
        $own = $this->makeBranch('Own');
        $other = $this->makeBranch('Other');
        $medicine = $this->makeMedicine();
        $this->makeInventory($other, $medicine);

        foreach (['pharmacy_technician', 'pharmacy_assistant', 'branch_manager'] as $role) {
            $this->getJson($this->url($other->id), $this->as($role, $own->id))->assertStatus(403);
        }
        foreach (['pharmacy_technician', 'branch_manager'] as $role) {
            $this->putJson($this->url($other->id, $medicine->id), $this->validBody(), $this->as($role, $own->id))->assertStatus(403);
        }

        // a staff member without any branch cannot use any branch
        $this->getJson($this->url($own->id), $this->as('pharmacy_technician', null))->assertStatus(403);

        // an unknown branch is also 403 for staff: its existence is not revealed
        $this->getJson($this->url(9999), $this->as('pharmacy_technician', $own->id))->assertStatus(403);
    }

    public function test_authorization_runs_before_validation(): void
    {
        $own = $this->makeBranch('Own');
        $other = $this->makeBranch('Other');

        $this->putJson($this->url($other->id, 1), ['stock_quantity' => -5], $this->as('pharmacy_technician', $own->id))->assertStatus(403);
        $this->putJson($this->url($own->id, 1), ['stock_quantity' => -5], $this->as('customer'))->assertStatus(403);
    }

    public function test_ceo_can_read_any_branch(): void
    {
        $a = $this->makeBranch('A');
        $b = $this->makeBranch('B');
        $this->makeInventory($a, $this->makeMedicine('Amoxicillin'));
        $this->makeInventory($b, $this->makeMedicine('Ibuprofen', 'Advil', 'Pain Relievers', false, '200mg'));

        $this->getJson($this->url($a->id), $this->as('ceo'))->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson($this->url($b->id), $this->as('ceo'))->assertOk()->assertJsonPath('data.items.0.medicine.generic_name', 'Ibuprofen');
    }

    // ------------------------------------------------------------ read

    public function test_inventory_rows_include_available_quantity_and_the_contract_shape(): void
    {
        $branch = $this->makeBranch();
        $this->makeInventory($branch, $this->makeMedicine(), 40, 12, 20, '10.00');

        $response = $this->getJson($this->url($branch->id), $this->as('pharmacy_assistant', $branch->id))->assertOk();
        $item = $response->json('data.items.0');

        $this->assertSame(28, $item['available_quantity']);
        $this->assertSame(40, $item['stock_quantity']);
        $this->assertSame(12, $item['reserved_quantity']);
        $this->assertSame('10.00', $item['price']);
        $this->assertSame('available', $item['status']);
        $this->assertSame(1, $item['version']);
        $this->assertSame('Amoxicillin', $item['medicine']['generic_name']);
        $this->assertNull($item['medicine']['brand_name']);
        $this->assertSame(
            ['available_quantity', 'batch_no', 'branch_id', 'expiration_date', 'id', 'medicine', 'min_threshold', 'price', 'reserved_quantity', 'status', 'stock_quantity', 'version'],
            collect(array_keys($item))->sort()->values()->all()
        );
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1], $response->json('data.meta'));
    }

    public function test_status_filter_pagination_and_unknown_branch(): void
    {
        $branch = $this->makeBranch();
        $this->makeInventory($branch, $this->makeMedicine('A Med'), 50, 0, 20);   // available
        $this->makeInventory($branch, $this->makeMedicine('B Med'), 5, 0, 20);    // low_stock
        $this->makeInventory($branch, $this->makeMedicine('C Med'), 0, 0, 20);    // out_of_stock
        $headers = $this->as('branch_manager', $branch->id);

        $low = $this->getJson($this->url($branch->id).'?status=low_stock', $headers)->assertOk();
        $this->assertSame(['B Med'], array_column(array_column($low->json('data.items'), 'medicine'), 'generic_name'));

        $this->getJson($this->url($branch->id).'?status=out_of_stock', $headers)->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson($this->url($branch->id).'?status=bogus', $headers)->assertStatus(422);

        $paged = $this->getJson($this->url($branch->id).'?per_page=2', $headers)->assertOk();
        $this->assertCount(2, $paged->json('data.items'));
        $this->assertSame(2, $paged->json('data.meta.last_page'));

        // CEO asking for an unknown branch gets 404
        $this->getJson($this->url(9999), $this->as('ceo'))->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // ------------------------------------------------------------ update

    public function test_update_changes_stock_bumps_version_recomputes_status_and_writes_a_movement(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $row = $this->makeInventory($branch, $medicine, 40, 0, 20); // available
        $headers = $this->as('pharmacy_technician', $branch->id, 42, ['X-Request-Id' => 'req-update-0001']);

        $response = $this->putJson($this->url($branch->id, $medicine->id), $this->validBody(['stock_quantity' => 55, 'min_threshold' => 60, 'version' => 1]), $headers)
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 55)
            ->assertJsonPath('data.price', '12.50')
            ->assertJsonPath('data.min_threshold', 60)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.status', 'low_stock')   // 55 < threshold 60
            ->assertJsonPath('data.available_quantity', 55)
            ->assertJsonPath('data.batch_no', 'B-NEW')
            ->assertJsonPath('request_id', 'req-update-0001');

        $this->assertNull($response->json('error'));

        $fresh = $row->fresh();
        $this->assertSame(55, $fresh->stock_quantity);
        $this->assertSame(2, $fresh->version);
        $this->assertSame('low_stock', $fresh->status);

        $movement = StockMovement::firstOrFail();
        $this->assertSame($row->id, $movement->branch_inventory_id);
        $this->assertSame(15, $movement->delta);           // 55 - 40
        $this->assertSame('manual', $movement->reason);
        $this->assertSame(42, $movement->actor_id);         // from X-User-Id
        $this->assertSame('req-update-0001', $movement->request_id);

        $this->assertSame(0, StockAlert::count(), 'alerts are not created in this step');
    }

    public function test_status_is_recomputed_for_zero_low_and_available(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $this->makeInventory($branch, $medicine, 40, 0, 20);
        $headers = $this->as('branch_manager', $branch->id);
        $url = $this->url($branch->id, $medicine->id);

        $this->putJson($url, $this->validBody(['stock_quantity' => 0, 'min_threshold' => 20, 'version' => 1]), $headers)
            ->assertOk()->assertJsonPath('data.status', 'out_of_stock')->assertJsonPath('data.version', 2);
        $this->putJson($url, $this->validBody(['stock_quantity' => 19, 'min_threshold' => 20, 'version' => 2]), $headers)
            ->assertOk()->assertJsonPath('data.status', 'low_stock');
        $this->putJson($url, $this->validBody(['stock_quantity' => 20, 'min_threshold' => 20, 'version' => 3]), $headers)
            ->assertOk()->assertJsonPath('data.status', 'available')->assertJsonPath('data.version', 4);

        $this->assertSame([-40, 19, 1], StockMovement::orderBy('id')->pluck('delta')->all());
    }

    public function test_a_stale_version_returns_409_and_changes_nothing(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $row = $this->makeInventory($branch, $medicine, 40, 0, 20, '10.00', 3);

        $this->putJson($this->url($branch->id, $medicine->id), $this->validBody(['version' => 2]), $this->as('pharmacy_technician', $branch->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STALE_VERSION')
            ->assertJsonPath('error.details.current_version', 3)
            ->assertJsonPath('data', null);

        $this->assertSame(40, $row->fresh()->stock_quantity);
        $this->assertSame(3, $row->fresh()->version);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_two_updates_with_the_same_version_only_one_wins(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $this->makeInventory($branch, $medicine, 40, 0, 20);
        $headers = $this->as('pharmacy_technician', $branch->id);
        $url = $this->url($branch->id, $medicine->id);

        $this->putJson($url, $this->validBody(['stock_quantity' => 50, 'version' => 1]), $headers)->assertOk();
        $this->putJson($url, $this->validBody(['stock_quantity' => 99, 'version' => 1]), $headers)->assertStatus(409);

        $this->assertSame(50, BranchInventory::first()->stock_quantity);
    }

    public function test_stock_below_the_reserved_quantity_is_rejected(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $row = $this->makeInventory($branch, $medicine, 40, 15, 20);

        $this->putJson($this->url($branch->id, $medicine->id), $this->validBody(['stock_quantity' => 10]), $this->as('branch_manager', $branch->id))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['stock_quantity']]]);

        $this->assertSame(40, $row->fresh()->stock_quantity);
        $this->assertSame(1, $row->fresh()->version);

        // exactly the reserved amount is allowed
        $this->putJson($this->url($branch->id, $medicine->id), $this->validBody(['stock_quantity' => 15]), $this->as('branch_manager', $branch->id))
            ->assertOk()->assertJsonPath('data.available_quantity', 0);
    }

    public function test_input_validation(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $this->makeInventory($branch, $medicine);
        $headers = $this->as('pharmacy_technician', $branch->id);
        $url = $this->url($branch->id, $medicine->id);

        $cases = [
            'negative stock' => [['stock_quantity' => -1], 'stock_quantity'],
            'non-integer stock' => [['stock_quantity' => 'many'], 'stock_quantity'],
            'zero price' => [['price' => '0'], 'price'],
            'negative price' => [['price' => '-3.00'], 'price'],
            'too many decimals' => [['price' => '1.999'], 'price'],
            'text price' => [['price' => 'free'], 'price'],
            'past expiry' => [['expiration_date' => now()->subDay()->toDateString()], 'expiration_date'],
            'today expiry' => [['expiration_date' => now()->toDateString()], 'expiration_date'],
            'bad date' => [['expiration_date' => '31/12/2030'], 'expiration_date'],
            'negative threshold' => [['min_threshold' => -1], 'min_threshold'],
            'long batch' => [['batch_no' => str_repeat('x', 51)], 'batch_no'],
            'zero version' => [['version' => 0], 'version'],
        ];

        foreach ($cases as $name => [$override, $field]) {
            $response = $this->putJson($url, $this->validBody($override), $headers)->assertStatus(422);
            $this->assertArrayHasKey($field, $response->json('error.details'), $name);
        }

        $missing = $this->putJson($url, ['version' => 1], $headers)->assertStatus(422);
        $this->assertArrayHasKey('stock_quantity', $missing->json('error.details'));
        $this->assertArrayHasKey('price', $missing->json('error.details'));

        // the version is required when the row already exists
        $noVersion = $this->validBody();
        unset($noVersion['version']);
        $this->putJson($url, $noVersion, $headers)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['version']]]);

        $this->assertSame(0, StockMovement::count());
    }

    public function test_unknown_branch_or_medicine_returns_404(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $headers = $this->as('pharmacy_technician', $branch->id);

        $this->putJson($this->url($branch->id, 9999), $this->validBody(), $headers)
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        // a manager of branch 9999 reaches the service check; the branch does not exist
        $this->putJson($this->url(9999, $medicine->id), $this->validBody(), $this->as('branch_manager', 9999))
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_first_stock_entry_for_a_medicine_creates_the_row_at_version_one(): void
    {
        $branch = $this->makeBranch();
        $medicine = $this->makeMedicine();
        $body = ['stock_quantity' => 30, 'price' => '8.00'];

        $this->putJson($this->url($branch->id, $medicine->id), $body, $this->as('pharmacy_technician', $branch->id))
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.reserved_quantity', 0)
            ->assertJsonPath('data.min_threshold', 10)
            ->assertJsonPath('data.status', 'available');

        $this->assertSame(1, BranchInventory::count());
        $this->assertSame(30, StockMovement::firstOrFail()->delta);
    }

    public function test_a_json_error_never_leaks_internals(): void
    {
        $branch = $this->makeBranch();
        $server = $this->transformHeadersToServerVars($this->as('pharmacy_technician', $branch->id) + ['Content-Type' => 'application/json']);
        $response = $this->call('PUT', $this->url($branch->id, 1), [], [], [], $server, '{"stock_quantity": ');

        // Laravel reads an unparsable JSON body as empty input, so the required fields are reported as missing.
        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        foreach (['trace', 'vendor', '.php', 'Illuminate', 'SQLSTATE'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }
}
