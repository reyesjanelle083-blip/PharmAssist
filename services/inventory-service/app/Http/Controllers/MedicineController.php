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

    public function categories(): JsonResponse
    {
        return Envelope::success(['items' => $this->catalog->categories()]);
    }

    /**
     * Plain-language guidance, from the medicine's own counseling_summary. Only text that is stored for the medicine is returned:
     * how_to_use and warnings are not stored separately yet, so they are null (the contract allows that). An empty summary is null.
     */
    public function counseling(int $id): JsonResponse
    {
        $medicine = $this->catalog->medicine($id);
        $summary = trim((string) $medicine->counseling_summary);

        return Envelope::success([
            'medicine_id' => $medicine->id,
            'summary' => $summary === '' ? null : $summary,
            'how_to_use' => null,
            'warnings' => null,
            'source' => 'internal',
        ]);
    }
}
