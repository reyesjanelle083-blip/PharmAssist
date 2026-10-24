<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use RuntimeException;

class JwtIssuer
{
    private const ALGORITHM = 'HS256';

    /**
     * @return array{token: string, expires_in: int}
     */
    public function issue(User $user): array
    {
        $secret = $this->secret();
        $issuedAt = time();
        $ttl = (int) config('jwt.ttl_minutes', 60) * 60;

        $payload = [
            'sub' => (string) $user->id,
            'role' => $user->role,
            'branch_id' => $user->branch_id,
            'jti' => (string) Str::uuid(),
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttl,
        ];

        return [
            'token' => JWT::encode($payload, $secret, self::ALGORITHM),
            'expires_in' => $ttl,
        ];
    }

    /**
     * Verifies signature and expiry. Throws a Firebase\JWT exception when invalid.
     *
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        return (array) JWT::decode($token, new Key($this->secret(), self::ALGORITHM));
    }

    /** Fail closed: no usable secret means no tokens are issued or accepted. */
    private function secret(): string
    {
        $secret = config('jwt.secret');

        if (! is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET is missing or shorter than 32 characters.');
        }

        return $secret;
    }
}
