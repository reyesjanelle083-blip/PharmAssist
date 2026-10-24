<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidTokenException;
use App\Exceptions\TokenExpiredException;
use App\Services\JwtVerifier;
use Closure;
use Illuminate\Http\Request;
use PharmAssist\Common\Http\Envelope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: auth.jwt. Authentication = proving who you are.
 * Puts the verified claims on the request as attribute "jwt_claims".
 */
class AuthenticateJwt
{
    public const ATTRIBUTE = 'jwt_claims';

    public function __construct(private readonly JwtVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->headers->get('Authorization');

        if (! is_string($header) || ! preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return $this->unauthenticated('UNAUTHENTICATED', 'Authentication is required.');
        }

        try {
            $claims = $this->verifier->verify($m[1]);
        } catch (TokenExpiredException) {
            return $this->unauthenticated('TOKEN_EXPIRED', 'The access token has expired.');
        } catch (InvalidTokenException) {
            return $this->unauthenticated('UNAUTHENTICATED', 'Authentication is required.');
        }
        // A missing/weak JWT_SECRET throws RuntimeException: rendered as a generic 500 (fail closed).

        $request->attributes->set(self::ATTRIBUTE, $claims);

        return $next($request);
    }

    private function unauthenticated(string $code, string $message): Response
    {
        $response = Envelope::error($code, $message, 401);
        $response->headers->set('WWW-Authenticate', 'Bearer');

        return $response;
    }
}
