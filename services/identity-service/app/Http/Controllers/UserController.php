<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use PharmAssist\Common\Http\Envelope;

class UserController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function show(int $id): JsonResponse
    {
        return Envelope::success((new UserResource($this->auth->me($id)))->resolve());
    }
}
