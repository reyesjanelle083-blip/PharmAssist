<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Requests\ChangeStatusRequest;
use App\Http\Requests\CreateReservationRequest;
use App\Http\Requests\ListReservationsRequest;
use App\Services\ReservationQueryService;
use App\Services\ReservationService;
use App\Services\ReservationStatusService;
use App\Support\UserContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PharmAssist\Common\Http\Envelope;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ReservationStatusService $statuses,
        private readonly ReservationQueryService $queries,
    ) {}

    public function store(CreateReservationRequest $request): JsonResponse
    {
        [$status, $data] = $this->reservations->create(
            $request->attributes->get(UserContext::ATTRIBUTE),
            $request->safe()->only(['branch_id', 'items', 'notes']),
            $request->file('prescription'),
            $request->attributes->get(RequireIdempotencyKey::ATTRIBUTE),
            $request->attributes->get('request_id'),
        );

        // openapi: "If OCR is unavailable the reservation still succeeds with ocr_status: unavailable and a
        // warning." Derived here, from the answer itself, so a replay of an earlier request carries the same
        // warning it originally did, with nothing extra to store alongside the idempotency record.
        $warnings = ($data['ocr_status'] ?? null) === 'unavailable' ? ['OCR_UNAVAILABLE'] : [];

        return Envelope::success($data, $status, $warnings);
    }

    public function index(ListReservationsRequest $request): JsonResponse
    {
        return Envelope::success($this->queries->list($request->attributes->get(UserContext::ATTRIBUTE), $request->validated()));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return Envelope::success($this->reservations->show($request->attributes->get(UserContext::ATTRIBUTE), $id));
    }

    public function status(ChangeStatusRequest $request, int $id): JsonResponse
    {
        return Envelope::success($this->statuses->change(
            $request->attributes->get(UserContext::ATTRIBUTE),
            $id,
            $request->validated('action'),
            $request->validated('reason'),
            $request->validated('claim_code'),
            $request->attributes->get('request_id'),
        ));
    }

    /** The only non-envelope success response: the image itself. Never exposes the stored path or file name. */
    public function prescription(Request $request, int $id): Response
    {
        $file = $this->queries->prescription($request->attributes->get(UserContext::ATTRIBUTE), $id);

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'], // from the detected content, not from the name
            'Content-Disposition' => 'inline; filename=prescription.'.$file['extension'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'X-Request-Id' => (string) $request->attributes->get('request_id'),
        ]);
    }
}
