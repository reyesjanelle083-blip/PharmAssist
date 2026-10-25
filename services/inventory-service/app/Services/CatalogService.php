<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Medicine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class CatalogService
{
    private const DEFAULT_PER_PAGE = 20;

    /**
     * @param array{q?: ?string, category?: ?string, rx?: ?bool, page?: ?int, per_page?: ?int} $filters
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

        return $query
            ->orderBy('generic_name')->orderBy('brand_name')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), ['*'], 'page', (int) ($filters['page'] ?? 1));
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
