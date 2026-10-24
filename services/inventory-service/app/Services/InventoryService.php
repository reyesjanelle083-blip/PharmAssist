<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;
use App\Models\StockMovement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    private const DEFAULT_PER_PAGE = 20;
    private const DEFAULT_MIN_THRESHOLD = 10;

    /**
     * @param array{status?: ?string, page?: ?int, per_page?: ?int} $filters
     */
    public function list(int $branchId, array $filters): LengthAwarePaginator
    {
        Branch::findOrFail($branchId); // 404 for an unknown branch (inactive ones are still readable by staff)

        $query = BranchInventory::query()
            ->select('branch_inventory.*')
            ->join('medicines', 'medicines.id', '=', 'branch_inventory.medicine_id')
            ->with('medicine')
            ->where('branch_inventory.branch_id', $branchId);

        if (! empty($filters['status'])) {
            $query->where('branch_inventory.status', $filters['status']);
        }

        return $query
            ->orderBy('medicines.generic_name')->orderBy('medicines.brand_name')->orderBy('branch_inventory.id')
            ->paginate((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    /**
     * Sets stock, price and thresholds of one medicine at one branch.
     * Optimistic locking: the caller sends the version it last read; a stale value is 409 STALE_VERSION.
     *
     * @param array<string, mixed> $data validated request data
     */
    public function update(int $branchId, int $medicineId, array $data, int $actorId, ?string $requestId): BranchInventory
    {
        Branch::findOrFail($branchId);
        Medicine::findOrFail($medicineId);

        $stock = (int) $data['stock_quantity'];
        $sentVersion = array_key_exists('version', $data) ? (int) $data['version'] : null;

        try {
            return DB::transaction(function () use ($branchId, $medicineId, $data, $stock, $sentVersion, $actorId, $requestId) {
                $row = BranchInventory::where(['branch_id' => $branchId, 'medicine_id' => $medicineId])
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    $row = $this->createRow($branchId, $medicineId, $data, $stock);
                    $delta = $stock;
                } else {
                    if ($sentVersion === null) {
                        throw ValidationException::withMessages([
                            'version' => ['The version is required when updating an existing row.'],
                        ]);
                    }
                    if ($sentVersion !== $row->version) {
                        throw new ApiException('STALE_VERSION', 'This record was changed by someone else. Reload it and try again.', 409, [
                            'current_version' => $row->version,
                        ]);
                    }
                    if ($stock < $row->reserved_quantity) {
                        throw ValidationException::withMessages([
                            'stock_quantity' => ["Stock cannot be lower than the {$row->reserved_quantity} units currently reserved."],
                        ]);
                    }

                    $threshold = array_key_exists('min_threshold', $data) ? (int) $data['min_threshold'] : $row->min_threshold;

                    $changed = BranchInventory::whereKey($row->id)->where('version', $sentVersion)->update([
                        'price' => $data['price'],
                        'stock_quantity' => $stock,
                        'min_threshold' => $threshold,
                        'batch_no' => array_key_exists('batch_no', $data) ? $data['batch_no'] : $row->batch_no,
                        'expiration_date' => array_key_exists('expiration_date', $data) ? $data['expiration_date'] : $row->expiration_date,
                        'status' => BranchInventory::statusFor($stock, $threshold),
                        'version' => $sentVersion + 1,
                    ]);

                    if ($changed !== 1) { // lost a race despite the row lock (for example no lock support)
                        throw new ApiException('STALE_VERSION', 'This record was changed by someone else. Reload it and try again.', 409);
                    }

                    $delta = $stock - $row->stock_quantity;
                    $row->refresh();
                }

                StockMovement::create([
                    'branch_inventory_id' => $row->id,
                    'delta' => $delta,
                    'reason' => 'manual',
                    'actor_id' => $actorId,
                    'request_id' => $requestId,
                ]);

                return $row->load('medicine');
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests tried to create the same (branch, medicine) row at once.
            throw new ApiException('CONFLICT', 'This record was just created by someone else. Reload it and try again.', 409);
        }
    }

    /** First stock entry for a medicine at a branch (no version yet). */
    private function createRow(int $branchId, int $medicineId, array $data, int $stock): BranchInventory
    {
        $threshold = array_key_exists('min_threshold', $data) ? (int) $data['min_threshold'] : self::DEFAULT_MIN_THRESHOLD;

        $row = new BranchInventory([
            'branch_id' => $branchId,
            'medicine_id' => $medicineId,
            'price' => $data['price'],
            'stock_quantity' => $stock,
            'reserved_quantity' => 0,
            'min_threshold' => $threshold,
            'batch_no' => $data['batch_no'] ?? null,
            'expiration_date' => $data['expiration_date'] ?? null,
            'status' => BranchInventory::statusFor($stock, $threshold),
            'version' => 1,
        ]);
        $row->save();

        return $row;
    }
}
