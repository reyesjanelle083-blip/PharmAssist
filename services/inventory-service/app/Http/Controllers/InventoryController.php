<?php

namespace App\Http\Controllers;

use App\Http\Requests\InventoryIndexRequest;
use App\Http\Requests\UpdateInventoryRequest;
use App\Http\Resources\InventoryItemResource;
use App\Services\InventoryService;
use App\Support\Pagination;
use App\Support\UserContext;
use Illuminate\Http\JsonResponse;
use PharmAssist\Common\Http\Envelope;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function index(InventoryIndexRequest $request, int $id): JsonResponse
    {
        $page = $this->inventory->list($id, $request->validated());

        return Envelope::success([
            'items' => InventoryItemResource::collection($page->items())->resolve(),
            'meta' => Pagination::meta($page),
        ]);
    }

    public function update(UpdateInventoryRequest $request, int $id, int $medicineId): JsonResponse
    {
        /** @var UserContext $user set by forwarded.user */
        $user = $request->attributes->get(UserContext::ATTRIBUTE);

        $row = $this->inventory->update(
            $id,
            $medicineId,
            $request->validated(),
            $user->id,
            $request->attributes->get('request_id'),
        );

        return Envelope::success((new InventoryItemResource($row))->resolve());
    }
}
