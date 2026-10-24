<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncInventoryRequest;
use App\Services\InventorySyncService;
use App\Support\UserContext;
use Illuminate\Http\JsonResponse;
use PharmAssist\Common\Http\Envelope;

class InventorySyncController extends Controller
{
    public function __construct(private readonly InventorySyncService $sync) {}

    public function sync(SyncInventoryRequest $request): JsonResponse
    {
        /** @var UserContext $user set by forwarded.user */
        $user = $request->attributes->get(UserContext::ATTRIBUTE);

        $result = $this->sync->sync(
            (int) $request->validated('branch_id'),
            $request->validated('items'),
            (bool) $request->validated('strict', false),
            $user->id,
            $request->attributes->get('request_id'),
        );

        return Envelope::success($result);
    }
}
