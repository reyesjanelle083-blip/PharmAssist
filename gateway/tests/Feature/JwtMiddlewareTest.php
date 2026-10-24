<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PharmAssist\Common\Http\Envelope;
use Tests\Concerns\GatewayTestSetup;
use Tests\TestCase;

class JwtMiddlewareTest extends TestCase
{
    use GatewayTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGateway();
    }

    private function fakeIdentityUser(): void
    {
        Http::fake(['identity.test/*' => Http::response($this->downstream($this->userData()), 200)]);
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function test_no_token_returns_401(): void
    {
        Http::fake();

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('service', 'gateway')
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('data', null);

        Http::assertNothingSent();
    }

    public function test_malformed_authorization_headers_return_401(): void
    {
        Http::fake();

        foreach (['Token abc', 'Bearer', 'Bearer a b', 'Basic dXNlcjpwYXNz', 'Bearer not.a.jwt'] as $header) {
            $this->getJson('/api/v1/auth/me', ['Authorization' => $header])
                ->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        }
        Http::assertNothingSent();
    }

    public function test_tampered_wrong_secret_and_alg_none_tokens_return_401(): void
    {
        Http::fake();

        $good = $this->token();
        [$h, $p, $s] = explode('.', $good);
        $flipped = $s[0] === 'A' ? 'B' : 'A';
        $tampered = "$h.$p.".$flipped.substr($s, 1);

        $forgedPayload = $this->base64url(json_encode(['sub' => '1', 'role' => 'ceo', 'branch_id' => null, 'jti' => 'x', 'exp' => time() + 3600]));
        $algNone = $this->base64url('{"alg":"none","typ":"JWT"}').'.'.$forgedPayload.'.';

        $cases = [
            'tampered signature' => $tampered,
            'other secret' => $this->token([], str_repeat('x', 40)),
            'alg none' => $algNone,
            'payload swapped' => "$h.".$this->base64url(json_encode(['sub' => '1', 'role' => 'ceo', 'branch_id' => null, 'jti' => 'x', 'exp' => time() + 3600])).".$s",
        ];

        foreach ($cases as $name => $token) {
            $this->getJson('/api/v1/auth/me', $this->bearer($token))
                ->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        }
        Http::assertNothingSent();
    }

    public function test_tokens_missing_required_claims_are_rejected(): void
    {
        Http::fake();

        $this->getJson('/api/v1/auth/me', $this->bearer($this->token(['role' => '']))) ->assertStatus(401);
        $this->getJson('/api/v1/auth/me', $this->bearer($this->token(['jti' => ''])))->assertStatus(401);
    }

    public function test_expired_token_returns_401_token_expired(): void
    {
        Http::fake();

        $expired = $this->token(['iat' => time() - 7200, 'exp' => time() - 10]);

        $this->getJson('/api/v1/auth/me', $this->bearer($expired))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_EXPIRED')
            ->assertJsonPath('service', 'gateway');
        Http::assertNothingSent();
    }

    public function test_valid_token_on_me_returns_200_using_the_id_from_the_token(): void
    {
        $this->fakeIdentityUser();

        $this->getJson('/api/v1/auth/me?id=1', $this->bearer($this->token(['sub' => '7'])))
            ->assertOk()
            ->assertJsonPath('data.id', 7)
            ->assertJsonPath('error', null);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'http://identity.test/internal/v1/users/7');
    }

    public function test_a_non_numeric_subject_is_rejected_without_calling_identity(): void
    {
        Http::fake();

        $this->getJson('/api/v1/auth/me', $this->bearer($this->token(['sub' => '7/../../admin'])))
            ->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_a_missing_or_short_jwt_secret_fails_closed(): void
    {
        Http::fake();
        $token = $this->token(); // signed with a good secret

        foreach ([null, '', 'short'] as $weak) {
            config(['jwt.secret' => $weak]);
            $this->getJson('/api/v1/auth/me', $this->bearer($token))
                ->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        }
        Http::assertNothingSent();
    }

    public function test_role_middleware_allows_only_listed_roles(): void
    {
        Route::middleware(['auth.jwt', 'role:ceo,branch_manager'])
            ->get('/api/v1/_test/staff-only', fn () => Envelope::success(['ok' => true]));

        $this->getJson('/api/v1/_test/staff-only')->assertStatus(401);

        $this->getJson('/api/v1/_test/staff-only', $this->bearer($this->token(['role' => 'customer'])))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('service', 'gateway');

        $this->getJson('/api/v1/_test/staff-only', $this->bearer($this->token(['role' => 'ceo'])))
            ->assertOk()->assertJsonPath('data.ok', true);

        $this->getJson('/api/v1/_test/staff-only', $this->bearer($this->token(['role' => 'branch_manager', 'branch_id' => 1])))
            ->assertOk();

        $this->getJson('/api/v1/_test/staff-only', $this->bearer($this->token(['role' => 'pharmacy_technician', 'branch_id' => 1])))
            ->assertStatus(403);
    }

    public function test_ceo_only_route_returns_403_for_customer_and_200_for_ceo(): void
    {
        Route::middleware(['auth.jwt', 'role:ceo'])
            ->get('/api/v1/_test/ceo-only', fn () => Envelope::success(['ok' => true]));

        $this->getJson('/api/v1/_test/ceo-only', $this->bearer($this->token(['role' => 'customer'])))->assertStatus(403);
        $this->getJson('/api/v1/_test/ceo-only', $this->bearer($this->token(['role' => 'ceo'])))->assertOk();
    }
}
