<?php

namespace App\Services;

use App\Models\BranchInventory;
use App\Models\Medicine;

/**
 * Where is a medicine in stock right now? Public data (plan F2, step 1 of the mashup): the gateway adds distances.
 *
 * A row is listed only when the branch is active, free stock (stock_quantity - reserved_quantity) is above zero and the
 * batch has not expired. Stock counts, reserved counts and versions are never part of the answer.
 */
class AvailabilityService
{
    public const MAX_MEDICINES = 5;

    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 20;

    /**
     * @param array{medicine_id?: ?int, q?: ?string, limit?: ?int} $filters
     * @return list<array<string, mixed>>
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException for an unknown medicine_id
     */
    public function find(array $filters): array
    {
        $medicineIds = $this->medicineIds($filters);
        if ($medicineIds === []) {
            return [];
        }

        $limit = min(self::MAX_LIMIT, max(1, (int) ($filters['limit'] ?? self::DEFAULT_LIMIT)));

        $rows = BranchInventory::query()
            ->join('branches', 'branches.id', '=', 'branch_inventory.branch_id')
            ->join('medicines', 'medicines.id', '=', 'branch_inventory.medicine_id')
            ->whereIn('branch_inventory.medicine_id', $medicineIds)
            ->where('branches.is_active', true)
            ->whereRaw('branch_inventory.stock_quantity - branch_inventory.reserved_quantity > 0')
            ->whereDate('branch_inventory.expiration_date', '>=', now()->toDateString()) // today is still good
            // medicine name, then price, then branch id: a stable order
            ->orderBy('medicines.generic_name')->orderBy('medicines.brand_name')->orderBy('medicines.id')
            ->orderBy('branch_inventory.price')->orderBy('branches.id')
            ->limit($limit)
            ->get([
                'branches.id as branch_id', 'branches.name as branch_name', 'branches.address', 'branches.latitude', 'branches.longitude',
                'medicines.id as medicine_id', 'medicines.generic_name', 'medicines.brand_name', 'medicines.strength', 'medicines.dosage_form', 'medicines.prescription_required',
                'branch_inventory.price', 'branch_inventory.status',
                'branch_inventory.stock_quantity', 'branch_inventory.reserved_quantity',
            ]);

        return $rows->map(fn ($r) => [
            'branch_id' => (int) $r->branch_id,
            'branch_name' => $r->branch_name,
            'address' => $r->address,
            'latitude' => (float) $r->latitude,
            'longitude' => (float) $r->longitude,
            'medicine_id' => (int) $r->medicine_id,
            'medicine_name' => $r->brand_name === '' ? $r->generic_name : "{$r->generic_name} ({$r->brand_name})",
            'strength' => $r->strength,
            'dosage_form' => $r->dosage_form,
            'prescription_required' => (bool) $r->prescription_required,
            'price' => number_format((float) $r->price, 2, '.', ''),
            'available_quantity' => (int) $r->stock_quantity - (int) $r->reserved_quantity,
            'status' => $r->status,
        ])->all();
    }

    /**
     * medicine_id wins when both are given (an unknown id is a 404). A search that matches nothing is not an error.
     *
     * @return list<int>
     */
    private function medicineIds(array $filters): array
    {
        if (! empty($filters['medicine_id'])) {
            return [Medicine::findOrFail((int) $filters['medicine_id'])->id];
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q === '') {
            return [];
        }

        // "!" is the LIKE escape character so user input cannot act as a wildcard (works on MySQL and SQLite).
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';

        return Medicine::query()
            ->where(fn ($inner) => $inner->whereRaw("generic_name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("brand_name LIKE ? ESCAPE '!'", [$like]))
            ->orderBy('generic_name')->orderBy('brand_name')->orderBy('id')
            ->limit(self::MAX_MEDICINES)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
