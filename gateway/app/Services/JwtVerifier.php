<?php

namespace App\Services;

use App\Exceptions\InvalidTokenException;
use App\Exceptions\TokenExpiredException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

class JwtVerifier
{
    private const ALGORITHM = 'HS256';

    /**
     * Verifies signature and expiry and returns the claims.
     *
     * @return array{sub: string, role: string, branch_id: int|null, jti: string}
     *
     * @throws TokenExpiredException when the token has expired
     * @throws InvalidTokenException when it is malformed, badly signed or missing claims
     * @throws RuntimeException when JWT_SECRET is not configured (fail closed)
     */
    public function verify(string $token): array
    {
        $secret = $this->secret();

        try {
            // Pinning the algorithm in Key prevents alg=none and algorithm-confusion tokens.
            $claims = (array) JWT::decode($token, new Key($secret, self::ALGORITHM));
        } catch (ExpiredException) {
            throw new TokenExpiredException('Token expired.');
        } catch (Throwable) {
            throw new InvalidTokenException('Token invalid.');
        }

        foreach (['sub', 'role', 'jti'] as $required) {
            if (! isset($claims[$required]) || ! is_string($claims[$required]) || $claims[$required] === '') {
                throw new InvalidTokenException('Token claims incomplete.');
            }
        }

        $branch = $claims['branch_id'] ?? null;
        if ($branch !== null && ! is_int($branch)) {
            throw new InvalidTokenException('Token claims invalid.');
        }

        return [
            'sub' => $claims['sub'],
            'role' => $claims['role'],
            'branch_id' => $branch,
            'jti' => $claims['jti'],
        ];
    }

    private function secret(): string
    {
        $secret = config('jwt.secret');

        if (! is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET is missing or shorter than 32 characters.');
        }

        return $secret;
    }
}
