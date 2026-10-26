<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\AuthService;
use App\Services\JwtIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-internal-key';
    private const PASSWORD = 'Secret123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pharmassist.internal_key' => self::KEY,
            'pharmassist.service' => 'identity-service',
            'jwt.secret' => str_repeat('s', 40),
        ]);
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

    public function test_register_creates_an_unverified_customer_without_any_id_photo(): void
    {
        $response = $this->postJson('/internal/v1/auth/register', $this->registerPayload(), $this->headers());

        $response->assertStatus(201)
            ->assertJsonStructure(['status', 'service', 'request_id', 'data', 'error', 'warnings'])
            ->assertJsonPath('data.email', 'new.customer@pharmassist.test')
            ->assertJsonPath('data.role', 'customer')
            ->assertJsonPath('data.email_verified', false);

        $body = $response->getContent();
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('id_document_path', $body);
        $this->assertArrayNotHasKey('verification_status', $response->json('data'), 'there is no account approval to report');

        $user = User::where('email', 'new.customer@pharmassist.test')->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password_hash));
        $this->assertNull($user->id_document_path, 'no ID document is stored');
        $this->assertNull($user->email_verified_at);

        $code = EmailVerificationCode::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(64, strlen($code->code_hash)); // only a hash is stored
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($code->expires_at, false), 1);

        $this->assertDatabaseHas('audit_logs', ['action' => 'register', 'actor_id' => $user->id]);
    }

    public function test_register_also_accepts_a_form_post_and_ignores_a_leftover_id_photo_field(): void
    {
        $this->post('/internal/v1/auth/register', $this->registerPayload(['id_photo' => 'not-a-file']), $this->headers())
            ->assertStatus(201)->assertJsonPath('data.role', 'customer');

        $this->assertNull(User::firstOrFail()->id_document_path);
    }

    public function test_register_with_a_duplicate_email_returns_409(): void
    {
        $this->makeUser(['email' => 'new.customer@pharmassist.test']);

        $this->postJson('/internal/v1/auth/register', $this->registerPayload(), $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EMAIL_EXISTS');

        $this->assertSame(1, User::count());
        $this->assertSame(0, EmailVerificationCode::count());
    }

    public function test_register_with_a_duplicate_email_ignores_letter_case_and_spaces(): void
    {
        $this->makeUser(['email' => 'new.customer@pharmassist.test']);

        $this->postJson('/internal/v1/auth/register', $this->registerPayload(['email' => '  New.Customer@Pharmassist.test ']), $this->headers())
            ->assertStatus(409)->assertJsonPath('error.code', 'EMAIL_EXISTS');
    }

    public function test_register_validates_input_and_no_id_photo_is_needed(): void
    {
        $this->postJson('/internal/v1/auth/register', $this->registerPayload(['password' => 'short1']), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['password']]]);

        $this->postJson('/internal/v1/auth/register', $this->registerPayload(['password' => 'onlyletters']), $this->headers())
            ->assertStatus(422);

        $details = $this->postJson('/internal/v1/auth/register', ['email' => 'a@pharmassist.test'], $this->headers())
            ->assertStatus(422)->json('error.details');
        $this->assertArrayNotHasKey('id_photo', $details, 'an ID photo is no longer asked for');
        $this->assertSame(0, User::count());
    }

    public function test_a_customer_cannot_choose_a_role_when_registering(): void
    {
        $this->postJson('/internal/v1/auth/register', $this->registerPayload(['role' => 'ceo', 'branch_id' => 1, 'is_active' => false]), $this->headers())->assertStatus(201);

        $user = User::firstOrFail();
        $this->assertSame(['customer', null, true], [$user->role, $user->branch_id, $user->is_active]);
    }

    // ---------------------------------------------------------------- verify email

    public function test_verify_email_marks_the_user_verified_and_the_code_used(): void
    {
        $user = $this->makeUser(['email_verified_at' => null]);
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

    public function test_the_password_lives_in_the_password_hash_column(): void
    {
        $user = $this->makeUser();

        $this->assertSame('password_hash', $user->getAuthPasswordName());
        $this->assertSame($user->password_hash, $user->getAuthPassword());
        $this->assertTrue(Hash::check(self::PASSWORD, $user->getAuthPassword()));
        $this->assertFalse(Hash::check('Wrong12345', $user->getAuthPassword()));
    }

    public function test_a_seeded_style_approved_customer_can_log_in_and_gets_a_customer_token(): void
    {
        $user = $this->makeUser([
            'email' => 'customer.approved@pharmassist.test',
            'role' => 'customer',
            'branch_id' => null,
        ]);

        $response = $this->login(self::PASSWORD, 'customer.approved@pharmassist.test')->assertOk();

        $claims = app(JwtIssuer::class)->decode($response->json('data.access_token'));
        $this->assertSame((string) $user->id, $claims['sub']);
        $this->assertSame('customer', $claims['role']);
        $this->assertNull($claims['branch_id']);
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

    public function test_the_old_id_approval_states_never_block_a_verified_customer(): void
    {
        $user = $this->makeUser(['role' => 'customer', 'branch_id' => null, 'verification_status' => 'pending']);
        $this->login()->assertOk()->assertJsonPath('data.user.role', 'customer');

        $user->update(['verification_status' => 'rejected']);
        $this->login()->assertOk();

        $this->assertArrayNotHasKey('verification_status', $this->login()->json('data.user'));
    }

    public function test_an_inactive_account_is_still_403(): void
    {
        $this->makeUser(['is_active' => false]);
        $this->login()->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_the_whole_customer_flow_is_register_verify_email_login_with_no_admin_step(): void
    {
        $this->postJson('/internal/v1/auth/register', $this->registerPayload(), $this->headers())->assertStatus(201);
        $email = 'new.customer@pharmassist.test';

        // before the email is verified there is no way in
        $this->login(self::PASSWORD, $email)->assertStatus(403)->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        // verifying the email is the only step (the real code is only stored as a hash, so give the account a known one)
        $this->giveCode(User::where('email', $email)->firstOrFail(), '482913');
        $this->postJson('/internal/v1/auth/verify-email', ['email' => $email, 'code' => '482913'], $this->headers())->assertOk();

        // and the customer can log in straight away
        $response = $this->login(self::PASSWORD, $email)->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.user.email_verified', true);

        $claims = app(JwtIssuer::class)->decode($response->json('data.access_token'));
        $this->assertSame(['customer', null], [$claims['role'], $claims['branch_id']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login_success']);
    }

    public function test_staff_roles_and_their_branch_still_come_from_the_account_into_the_token(): void
    {
        foreach ([['pharmacy_technician', 1], ['pharmacy_assistant', 1], ['branch_manager', 2], ['ceo', null]] as $i => [$role, $branch]) {
            $user = $this->makeUser(['email' => "staff{$i}@pharmassist.test", 'role' => $role, 'branch_id' => $branch]);

            $claims = app(JwtIssuer::class)->decode(
                $this->login(self::PASSWORD, $user->email)->assertOk()->json('data.access_token')
            );

            $this->assertSame([(string) $user->id, $role, $branch], [$claims['sub'], $claims['role'], $claims['branch_id']]);
        }
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
        $user = $this->makeUser();
        $user->forceFill(['id_document_path' => 'id-documents/secret.jpg'])->save(); // old data from before ID photos were removed

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
