<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class CatalogService
{
    private const DEFAULT_PER_PAGE = 20;

    /**
     * The medicine catalog is global. Two optional additions look at stock without changing that:
     *  - branch_id keeps only the medicines that branch has free stock of right now;
     *  - include=availability adds, to each medicine of the page, where it is in stock (one query for the whole page).
     *
     * @param array{q?: ?string, category?: ?string, rx?: ?bool, branch_id?: ?int, include?: ?string, page?: ?int, per_page?: ?int} $filters
     */
    public function searchMedicines(array $filters): LengthAwarePaginator
    {
        $query = Medicine::query();

        $q = isset($filters['q']) ? trim($filters['q']) : '';
        if ($q !== '') {
            // "!" is the LIKE escape character so user input cannot act as a wildcard (works on MySQL and SQLite).
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(function ($inner) use ($like) {
                $inner->whereRaw("generic_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("brand_name LIKE ? ESCAPE '!'", [$like]);
            });
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (array_key_exists('rx', $filters) && $filters['rx'] !== null) {
            $query->where('prescription_required', (bool) $filters['rx']);
        }

        if (! empty($filters['branch_id'])) {
            $branchId = (int) $filters['branch_id'];
            $query->whereExists(fn ($stock) => $this->inStock($stock->selectRaw('1')->from('branch_inventory'))
                ->whereColumn('branch_inventory.medicine_id', 'medicines.id')
                ->where('branch_inventory.branch_id', $branchId));
        }

        $page = $query
            ->orderBy('generic_name')->orderBy('brand_name')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), ['*'], 'page', (int) ($filters['page'] ?? 1));

        if (($filters['include'] ?? null) === 'availability') {
            $this->attachAvailability($page->getCollection());
        }

        return $page;
    }

    /**
     * The same rule as the public availability search: the branch is active, free stock (stock minus reserved) is above zero and the
     * batch has not expired (today is still good). Applied to a query that already selects from branch_inventory.
     */
    private function inStock($query)
    {
        return $query
            ->join('branches', 'branches.id', '=', 'branch_inventory.branch_id')
            ->where('branches.is_active', true)
            ->whereRaw('branch_inventory.stock_quantity - branch_inventory.reserved_quantity > 0')
            ->whereDate('branch_inventory.expiration_date', '>=', now()->toDateString());
    }

    /** @param Collection<int, Medicine> $medicines */
    private function attachAvailability($medicines): void
    {
        $byMedicine = $this->inStock(BranchInventory::query())
            ->whereIn('branch_inventory.medicine_id', $medicines->pluck('id')->all())
            ->orderBy('branch_inventory.branch_id')
            ->get(['branch_inventory.medicine_id', 'branch_inventory.branch_id', 'branch_inventory.stock_quantity', 'branch_inventory.reserved_quantity', 'branch_inventory.status'])
            ->groupBy('medicine_id');

        foreach ($medicines as $medicine) {
            $rows = $byMedicine->get($medicine->id, collect());
            $branches = $rows->map(fn ($r) => [
                'branch_id' => (int) $r->branch_id,
                'available_quantity' => (int) $r->stock_quantity - (int) $r->reserved_quantity,
                'status' => $r->status,
            ])->values()->all();

            $medicine->setAttribute('availability', [
                'branch_count' => count($branches),
                'total_available' => array_sum(array_column($branches, 'available_quantity')),
                'branches' => $branches,
            ]);
        }
    }

    public function medicine(int $id): Medicine
    {
        return Medicine::findOrFail($id);
    }

    /** The categories that exist in the catalog, for the search filter. */
    public function categories(): array
    {
        return Medicine::query()->distinct()->orderBy('category')->pluck('category')->all();
    }

    /** Public lists never include inactive branches. */
    public function activeBranches(): Collection
    {
        return Branch::where('is_active', true)->orderBy('id')->get();
    }

    public function activeBranch(int $id): Branch
    {
        return Branch::where('is_active', true)->findOrFail($id);
    }
}
