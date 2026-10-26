<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateJwt;
use App\Services\ServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PharmAssist\Common\Http\Envelope;

/**
 * Thin controller: the gateway authenticates and routes; identity-service validates and decides.
 */
class AuthController extends Controller
{
    private const REGISTER_FIELDS = ['email', 'password', 'first_name', 'last_name', 'contact_no'];

    public function __construct(private readonly ServiceClient $services) {}

    public function register(Request $request): JsonResponse
    {
        // Plain JSON registration. Forward only the known fields, nothing else the client sent (an old client's id_photo is ignored).
        $fields = array_map(
            fn ($value) => is_scalar($value) ? (string) $value : null,
            $request->only(self::REGISTER_FIELDS)
        );

        return $this->services->postJson(
            'identity',
            '/internal/v1/auth/register',
            $fields,
            $this->slowIdentityTimeout(),
        );
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        return $this->services->postJson('identity', '/internal/v1/auth/verify-email', $request->only(['email', 'code']));
    }

    /** Same answer for any email; identity-service decides, and sends the email, so the call gets the longer budget. */
    public function forgotPassword(Request $request): JsonResponse
    {
        return $this->services->postJson('identity', '/internal/v1/auth/forgot-password', $this->scalars($request, ['email']), $this->slowIdentityTimeout());
    }

    public function resetPassword(Request $request): JsonResponse
    {
        return $this->services->postJson(
            'identity',
            '/internal/v1/auth/reset-password',
            $this->scalars($request, ['email', 'code', 'password', 'password_confirmation']),
            $this->slowIdentityTimeout(),
        );
    }

    /** Only the named fields, only plain values (an array or object sent in their place is dropped, so identity-service reports it missing). */
    private function scalars(Request $request, array $names): array
    {
        return array_filter(
            array_map(fn ($value) => is_scalar($value) ? (string) $value : null, $request->only($names)),
            fn ($value) => $value !== null,
        );
    }

    /** Same budget as login: these routes hash a password or send an email, which takes longer than the 3 s of a plain read. */
    private function slowIdentityTimeout(): float
    {
        return (float) config('services.identity.login_timeout', 10);
    }

    public function login(Request $request): JsonResponse
    {
        return $this->services->postJson('identity', '/internal/v1/auth/login', $request->only(['email', 'password']), (float) config('services.identity.login_timeout', 10));
    }

    public function me(Request $request): JsonResponse
    {
        // The id comes from the verified token only, never from the client.
        $sub = $request->attributes->get(AuthenticateJwt::ATTRIBUTE)['sub'];

        if (! ctype_digit($sub)) {
            return Envelope::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        return $this->services->get('identity', '/internal/v1/users/'.$sub);
    }
}
