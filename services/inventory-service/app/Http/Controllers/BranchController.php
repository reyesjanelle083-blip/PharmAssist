<?php

namespace App\Http\Controllers;

use App\Http\Resources\BranchResource;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use PharmAssist\Common\Http\Envelope;

class BranchController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(): JsonResponse
    {
        return Envelope::success([
            'items' => BranchResource::collection($this->catalog->activeBranches())->resolve(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return Envelope::success((new BranchResource($this->catalog->activeBranch($id)))->resolve());
    }
}
