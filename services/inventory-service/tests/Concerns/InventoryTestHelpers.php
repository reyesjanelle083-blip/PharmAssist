<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;

trait InventoryTestHelpers
{
    protected string $internalKey = 'inventory-test-key';

    protected function setUpInventory(): void
    {
        config([
            'pharmassist.internal_key' => $this->internalKey,
            'pharmassist.service' => 'inventory-service',
        ]);
    }

    /** Headers the gateway would forward for an authenticated user. */
    protected function as(string $role, ?int $branchId = null, int $userId = 7, array $extra = []): array
    {
        return array_merge([
            'X-Internal-Key' => $this->internalKey,
            'X-User-Id' => (string) $userId,
            'X-User-Role' => $role,
            'X-User-Branch' => $branchId === null ? '' : (string) $branchId,
            'Accept' => 'application/json',
        ], $extra);
    }

    /** Headers for the public catalog routes (internal key only, no user). */
    protected function internal(): array
    {
        return ['X-Internal-Key' => $this->internalKey, 'Accept' => 'application/json'];
    }

    protected function makeBranch(string $name = 'Branch One', bool $active = true): Branch
    {
        return Branch::create([
            'name' => $name,
            'address' => '1 Test St.',
            'latitude' => 14.6,
            'longitude' => 121.0,
            'is_active' => $active,
        ]);
    }

    protected function makeMedicine(string $generic = 'Amoxicillin', string $brand = '', string $category = 'Antibiotics', bool $rx = true, string $strength = '500mg', string $form = 'Capsule'): Medicine
    {
        return Medicine::create([
            'generic_name' => $generic,
            'brand_name' => $brand,
            'category' => $category,
            'strength' => $strength,
            'dosage_form' => $form,
            'prescription_required' => $rx,
            'counseling_summary' => 'Take as directed.',
        ]);
    }

    protected function makeInventory(Branch $branch, Medicine $medicine, int $stock = 40, int $reserved = 0, int $threshold = 20, string $price = '10.00', int $version = 1): BranchInventory
    {
        return BranchInventory::create([
            'branch_id' => $branch->id,
            'medicine_id' => $medicine->id,
            'price' => $price,
            'stock_quantity' => $stock,
            'reserved_quantity' => $reserved,
            'min_threshold' => $threshold,
            'batch_no' => 'B-1',
            'expiration_date' => now()->addYear()->toDateString(),
            'status' => BranchInventory::statusFor($stock, $threshold),
            'version' => $version,
        ]);
    }
}
