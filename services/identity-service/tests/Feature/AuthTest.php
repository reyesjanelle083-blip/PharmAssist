<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\AuthService;
use App\Services\JwtIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-internal-key';
    private const PASSWORD = 'Secret123';
    // 1x1 transparent PNG (no GD extension needed to build a valid image upload)
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pharmassist.internal_key' => self::KEY,
            'pharmassist.service' => 'identity-service',
            'jwt.secret' => str_repeat('s', 40),
        ]);
        Storage::fake('local');
    }

    // ---------------------------------------------------------------- helpers

    private function headers(): array
    {
        return ['X-Internal-Key' => self::KEY, 'Accept' => 'application/json'];
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'new.customer@pharmassist.test',
            'password' => self::PASSWORD,
            'first_name' => 'New',
            'last_name' => 'Customer',
            'contact_no' => '09171234567',
            'id_photo' => UploadedFile::fake()->createWithContent('id.png', base64_decode(self::PNG_1X1)),
        ], $overrides);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'user@pharmassist.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'branch_manager',
            'first_name' => 'Test',
            'last_name' => 'User',
            'branch_id' => 1,
            'email_verified_at' => now(),
            'verification_status' => 'approved',
            'is_active' => true,
        ], $overrides));
    }

    private function giveCode(User $user, string $code, int $minutesFromNow = 15): void
    {
        EmailVerificationCode::create([
            'user_id' => $user->id,
            'code_hash' => AuthService::hashCode($code),
            'expires_at' => now()->addMinutes($minutesFromNow),
        ]);
    }

    private function login(string $password = self::PASSWORD, string $email = 'user@pharmassist.test')
    {
        return $this->postJson('/internal/v1/auth/login', ['email' => $email, 'password' => $password], $this->headers());
    }

    // ---------------------------------------------------------------- internal key

    public function test_every_auth_route_rejects_a_missing_or_wrong_internal_key(): void
    {
        $this->postJson('/internal/v1/auth/register', [])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
        $this->postJson('/internal/v1/auth/verify-email', [], ['X-Internal-Key' => 'wrong'])->assertStatus(401);
        $this->postJson('/internal/v1/auth/login', [], ['X-Internal-Key' => 'wrong'])->assertStatus(401);
        $this->getJson('/internal/v1/users/1')->assertStatus(401);
    }

    // ---------------------------------------------------------------- register

    public function test_register_creates_a_pending_customer_and_stores_the_id_privately(): void
    {
        $response = $this->post('/internal/v1/auth/register', $this->registerPayload(), $this->headers());

        $response->assertStatus(201)
            ->assertJsonStructure(['status', 'service', 'request_id', 'data', 'error', 'warnings'])
            ->assertJsonPath('data.email', 'new.customer@pharmassist.test')
            ->assertJsonPath('data.role', 'customer')
            ->assertJsonPath('data.verification_status', 'pending')
            ->assertJsonPath('data.email_verified', false);

        $body = $response->getContent();
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('id_document_path', $body);

        $user = User::where('email', 'new.customer@pharmassist.test')->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password_hash));
        Storage::disk('local')->assertExists($user->id_document_path);
        $this->assertStringStartsWith('id-documents/', $user->id_document_path);
        $this->assertStringNotContainsString('id.png', $user->id_document_path);

        $code = EmailVerificationCode::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(64, strlen($code->code_hash)); // only a hash is stored
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($code->expires_at, false), 1);

        $this->assertDatabaseHas('audit_logs', ['action' => 'register', 'actor_id' => $user->id]);
    }

    public function test_register_with_a_duplicate_email_returns_409(): void
    {
        $this->makeUser(['email' => 'new.customer@pharmassist.test']);

        $this->post('/internal/v1/auth/register', $this->registerPayload(), $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EMAIL_EXISTS');

        $this->assertSame(1, User::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_register_validates_input(): void
    {
        $this->post('/internal/v1/auth/register', $this->registerPayload(['password' => 'short1', 'id_photo' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf')]), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['password', 'id_photo']]]);

        $this->post('/internal/v1/auth/register', $this->registerPayload(['password' => 'onlyletters']), $this->headers())
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------- verify email

    public function test_verify_email_marks_the_user_verified_and_the_code_used(): void
    {
        $user = $this->makeUser(['email_verified_at' => null, 'verification_status' => 'pending']);
        $this->giveCode($user, '123456');

        $this->postJson('/internal/v1/auth/verify-email', ['email' => $user->email, 'code' => '123456'], $this->headers())
            ->assertOk()->assertJsonPath('data', null)->assertJsonPath('error', null);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull(EmailVerificationCode::first()->used_at);

        // a used code cannot be replayed
        $this->postJson('/internal/v1/auth/verify-email', ['email' => $user->email, 'code' => '123456'], $this->headers())
            ->assertStatus(422);
    }

    public function test_verify_email_with_a_wrong_code_returns_422(): void
    {
        $user = $this->makeUser(['email_verified_at' => null]);
        $this->giveCode($user, '123456');

        $this->postJson('/internal/v1/auth/verify-email', ['email' => $user->email, 'code' => '654321'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CODE');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_email_with_an_expired_code_returns_410(): void
    {
        $user = $this->makeUser(['email_verified_at' => null]);
        $this->giveCode($user, '123456', -1);

        $this->postJson('/internal/v1/auth/verify-email', ['email' => $user->email, 'code' => '123456'], $this->headers())
            ->assertStatus(410)->assertJsonPath('error.code', 'CODE_EXPIRED');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ---------------------------------------------------------------- login

    public function test_login_returns_a_token_with_the_expected_claims(): void
    {
        $user = $this->makeUser();

        $response = $this->login()->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_in', 3600)
            ->assertJsonPath('data.user.email', 'user@pharmassist.test');

        $this->assertStringNotContainsString('password', $response->getContent());

        $claims = app(JwtIssuer::class)->decode($response->json('data.access_token'));
        $this->assertSame((string) $user->id, $claims['sub']);
        $this->assertSame('branch_manager', $claims['role']);
        $this->assertSame(1, $claims['branch_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $claims['jti']);
        $this->assertEqualsWithDelta(3600, $claims['exp'] - $claims['iat'], 1);
        $this->assertEqualsWithDelta(time() + 3600, $claims['exp'], 5);

        $this->assertDatabaseHas('audit_logs', ['action' => 'login_success', 'actor_id' => $user->id]);
    }

    public function test_wrong_password_and_unknown_email_give_the_same_generic_401(): void
    {
        $this->makeUser();

        $wrong = $this->login('Wrong12345')->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $unknown = $this->login(self::PASSWORD, 'nobody@pharmassist.test')->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertSame($wrong->json('error.message'), $unknown->json('error.message'));
        $this->assertSame('Invalid email or password', $wrong->json('error.message'));
        $this->assertSame(2, AuditLog::where('action', 'login_failed')->count());
    }

    public function test_unverified_email_is_403(): void
    {
        $this->makeUser(['email_verified_at' => null]);
        $this->login()->assertStatus(403)->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    }

    public function test_pending_rejected_and_inactive_accounts_are_403(): void
    {
        $user = $this->makeUser(['verification_status' => 'pending']);
        $this->login()->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_PENDING');

        $user->update(['verification_status' => 'rejected']);
        $this->login()->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_REJECTED');

        $user->update(['verification_status' => 'approved', 'is_active' => false]);
        $this->login()->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_account_locks_after_five_failures_and_unlocks_after_fifteen_minutes(): void
    {
        $user = $this->makeUser();

        for ($i = 1; $i <= 5; $i++) {
            $this->login('Wrong12345')->assertStatus(401);
        }
        $this->assertSame(5, $user->fresh()->failed_logins);
        $this->assertNotNull($user->fresh()->locked_until);

        // locked: even the correct password is refused
        $this->login('Wrong12345')->assertStatus(423)->assertJsonPath('error.code', 'ACCOUNT_LOCKED');
        $this->login()->assertStatus(423)->assertJsonPath('error.code', 'ACCOUNT_LOCKED');

        $this->travel(16)->minutes();
        $this->login()->assertOk();
        $this->assertSame(0, $user->fresh()->failed_logins);
        $this->assertNull($user->fresh()->locked_until);
    }

    public function test_a_successful_login_resets_the_failure_counter(): void
    {
        $user = $this->makeUser();
        $this->login('Wrong12345')->assertStatus(401);
        $this->login('Wrong12345')->assertStatus(401);
        $this->assertSame(2, $user->fresh()->failed_logins);

        $this->login()->assertOk();
        $this->assertSame(0, $user->fresh()->failed_logins);
    }

    // ---------------------------------------------------------------- me / jwt

    public function test_get_user_returns_the_user_without_sensitive_fields_or_404(): void
    {
        $user = $this->makeUser(['id_document_path' => 'id-documents/secret.jpg']);

        $response = $this->getJson('/internal/v1/users/'.$user->id, $this->headers())
            ->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('secret.jpg', $response->getContent());

        $this->getJson('/internal/v1/users/9999', $this->headers())
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_jwt_issuer_fails_closed_without_a_strong_secret(): void
    {
        $user = $this->makeUser();

        foreach ([null, '', 'too-short'] as $secret) {
            config(['jwt.secret' => $secret]);
            try {
                app(JwtIssuer::class)->issue($user);
                $this->fail('JwtIssuer issued a token with a weak secret.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('JWT_SECRET', $e->getMessage());
            }
        }
    }

    public function test_login_returns_a_generic_500_envelope_when_the_secret_is_missing(): void
    {
        $this->makeUser();
        config(['jwt.secret' => null]);

        $this->login()->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
    }
}
