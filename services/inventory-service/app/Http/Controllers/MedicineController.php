<?php

namespace App\Http\Controllers;

use App\Http\Requests\MedicineIndexRequest;
use App\Http\Resources\MedicineResource;
use App\Services\CatalogService;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use PharmAssist\Common\Http\Envelope;

class MedicineController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(MedicineIndexRequest $request): JsonResponse
    {
        $page = $this->catalog->searchMedicines($request->validated());

        return Envelope::success([
            'items' => MedicineResource::collection($page->items())->resolve(),
            'meta' => Pagination::meta($page),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return Envelope::success((new MedicineResource($this->catalog->medicine($id)))->resolve());
    }
}
