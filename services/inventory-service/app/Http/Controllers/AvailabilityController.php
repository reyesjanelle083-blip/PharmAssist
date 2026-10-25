<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailabilityRequest;
use App\Services\AvailabilityService;
use Illuminate\Http\JsonResponse;
use PharmAssist\Common\Http\Envelope;

/** Called by the gateway's availability aggregator (public data, so no user identity is needed). Protected by InternalKey. */
class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function index(AvailabilityRequest $request): JsonResponse
    {
        return Envelope::success(['items' => $this->availability->find($request->validated())]);
    }
}
