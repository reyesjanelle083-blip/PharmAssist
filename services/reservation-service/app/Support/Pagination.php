<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

final class Pagination
{
    /** @return array{page: int, per_page: int, total: int, last_page: int} */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
