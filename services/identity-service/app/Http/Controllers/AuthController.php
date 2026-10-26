<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use PharmAssist\Common\Http\Envelope;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->validated());

        return Envelope::success((new UserResource($user))->resolve(), 201);
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $this->auth->verifyEmail($request->validated('email'), $request->validated('code'));

        return Envelope::success(null);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->validated('email'), $request->validated('password'));
        $result['user'] = (new UserResource($result['user']))->resolve();

        return Envelope::success($result);
    }
}
