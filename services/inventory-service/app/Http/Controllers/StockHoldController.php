<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateHoldRequest;
use App\Services\StockHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PharmAssist\Common\Http\Envelope;

/** Service-to-service only (reservation-service). Protected by InternalKey; no gateway route exists. */
class StockHoldController extends Controller
{
    public function __construct(private readonly StockHoldService $holds) {}

    public function store(CreateHoldRequest $request): JsonResponse
    {
        $result = $this->holds->create(
            $request->validated('reservation_ref'),
            (int) $request->validated('branch_id'),
            $request->validated('items'),
            $request->validated('ttl_minutes') !== null ? (int) $request->validated('ttl_minutes') : null,
        );

        // 201 for a new hold, 200 when the same request was replayed (nothing was reserved again).
        return Envelope::success($result['data'], $result['replayed'] ? 200 : 201);
    }

    public function show(string $reservationRef): JsonResponse
    {
        return Envelope::success($this->holds->show($reservationRef));
    }

    public function commit(Request $request, string $reservationRef): JsonResponse
    {
        return Envelope::success($this->holds->commit($reservationRef, $request->attributes->get('request_id')));
    }

    public function release(string $reservationRef): JsonResponse
    {
        return Envelope::success($this->holds->release($reservationRef));
    }
}
