<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Requests\CreateReservationRequest;
use App\Services\ReservationService;
use App\Support\UserContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PharmAssist\Common\Http\Envelope;

class ReservationController extends Controller
{
    public function __construct(private readonly ReservationService $reservations) {}

    public function store(CreateReservationRequest $request): JsonResponse
    {
        [$status, $data] = $this->reservations->create(
            $request->attributes->get(UserContext::ATTRIBUTE),
            $request->safe()->only(['branch_id', 'items', 'notes']),
            $request->file('prescription'),
            $request->attributes->get(RequireIdempotencyKey::ATTRIBUTE),
            $request->attributes->get('request_id'),
        );

        return Envelope::success($data, $status);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return Envelope::success($this->reservations->show($request->attributes->get(UserContext::ATTRIBUTE), $id));
    }
}
