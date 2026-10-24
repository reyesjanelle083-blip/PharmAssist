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
        // Forward only the known fields and the ID photo, nothing else the client sent.
        $fields = array_map(
            fn ($value) => is_scalar($value) ? (string) $value : null,
            $request->only(self::REGISTER_FIELDS)
        );

        $files = $request->file('id_photo') instanceof \Illuminate\Http\UploadedFile && $request->file('id_photo')->isValid()
            ? ['id_photo' => $request->file('id_photo')]
            : [];

        return $this->services->postMultipart('identity', '/internal/v1/auth/register', $fields, $files);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        return $this->services->postJson('identity', '/internal/v1/auth/verify-email', $request->only(['email', 'code']));
    }

    public function login(Request $request): JsonResponse
    {
        return $this->services->postJson('identity', '/internal/v1/auth/login', $request->only(['email', 'password']));
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
