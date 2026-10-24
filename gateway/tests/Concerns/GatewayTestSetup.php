<?php

namespace Tests\Concerns;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;

trait GatewayTestSetup
{
    protected string $jwtSecret = 'unit-test-secret-unit-test-secret-1234';

    protected string $internalKey = 'gateway-internal-key';

    protected function setUpGateway(): void
    {
        config([
            'jwt.secret' => $this->jwtSecret,
            'pharmassist.internal_key' => $this->internalKey,
            'pharmassist.service' => 'gateway',
            'services.identity.url' => 'http://identity.test',
            'services.inventory.url' => 'http://inventory.test',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function token(array $overrides = [], ?string $secret = null): string
    {
        $now = time();

        $claims = array_merge([
            'sub' => '7',
            'role' => 'customer',
            'branch_id' => null,
            'jti' => (string) Str::uuid(),
            'iat' => $now,
            'exp' => $now + 3600,
        ], $overrides);

        return JWT::encode($claims, $secret ?? $this->jwtSecret, 'HS256');
    }

    /** @return array<string, string> */
    protected function bearer(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token())];
    }

    /** A downstream (identity-service style) envelope. */
    protected function downstream(mixed $data, int $status = 200, ?array $error = null): array
    {
        return [
            'status' => $status,
            'service' => 'identity-service',
            'request_id' => 'downstream-request-id',
            'data' => $data,
            'error' => $error,
            'warnings' => [],
        ];
    }

    protected function userData(): array
    {
        return ['id' => 7, 'email' => 'c@pharmassist.test', 'role' => 'customer'];
    }
}
