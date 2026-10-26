<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\AuditLog;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-internal-key';
    private const OLD = 'OldPassword1';
    private const NEW = 'NewPassword1';
    private const EMAIL = 'reset.me@pharmassist.test';

    /** @var list<string> everything the app logged during a test, for the "never logged" checks */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pharmassist.internal_key' => self::KEY,
            'pharmassist.service' => 'identity-service',
            'jwt.secret' => str_repeat('s', 40),
        ]);
        Mail::fake();
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    // ---------------------------------------------------------------- helpers

    private function headers(): array
    {
        return ['X-Internal-Key' => self::KEY, 'Accept' => 'application/json'];
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => self::EMAIL,
            'password_hash' => Hash::make(self::OLD),
            'role' => 'customer',
            'first_name' => 'Rita',
            'last_name' => 'Reset',
            'email_verified_at' => now(),
            'is_active' => true,
        ], $overrides));
    }

    private function forgot(string $email = self::EMAIL)
    {
        return $this->postJson('/internal/v1/auth/forgot-password', ['email' => $email], $this->headers());
    }

    private function reset(string $code, string $password = self::NEW, ?string $confirmation = null, string $email = self::EMAIL)
    {
        return $this->postJson('/internal/v1/auth/reset-password', [
            'email' => $email,
            'code' => $code,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ], $this->headers());
    }

    private function login(string $password, string $email = self::EMAIL)
    {
        return $this->postJson('/internal/v1/auth/login', ['email' => $email, 'password' => $password], $this->headers());
    }

    /** The code that was emailed most recently (the app keeps only its hash). */
    private function emailedCode(): string
    {
        $mails = Mail::sent(PasswordResetCodeMail::class);
        $this->assertNotEmpty($mails, 'a reset email was expected');

        return $mails->last()->code;
    }

    private function wrongCode(string $real): string
    {
        return $real === '000000' ? '000001' : '000000';
    }

    // ---------------------------------------------------------------- forgot-password

    public function test_forgot_password_gives_the_same_answer_for_an_existing_and_an_unknown_email(): void
    {
        $this->makeUser();
        $this->makeUser(['email' => 'gone@pharmassist.test', 'is_active' => false]);

        $known = $this->forgot()->assertOk();
        $unknown = $this->forgot('nobody@pharmassist.test')->assertOk();
        $inactive = $this->forgot('gone@pharmassist.test')->assertOk();

        $strip = fn ($response) => array_diff_key($response->json(), ['request_id' => 1]);
        $this->assertSame($strip($known), $strip($unknown));
        $this->assertSame($strip($known), $strip($inactive));
        $this->assertNull($known->json('data'));
        $this->assertNull($known->json('error'));
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());

        Mail::assertSent(PasswordResetCodeMail::class, 1); // only the active, existing account got an email
        Mail::assertSent(PasswordResetCodeMail::class, fn ($mail) => $mail->hasTo(self::EMAIL));
        $this->assertSame(1, PasswordReset::count());
    }

    public function test_forgot_password_ignores_letter_case_and_spaces_in_the_email(): void
    {
        $this->makeUser();

        $this->forgot('  Reset.Me@Pharmassist.TEST ')->assertOk();

        Mail::assertSent(PasswordResetCodeMail::class, fn ($mail) => $mail->hasTo(self::EMAIL));
    }

    public function test_the_code_is_six_digits_stored_only_as_a_hash_and_expires_in_fifteen_minutes(): void
    {
        $user = $this->makeUser();

        $response = $this->forgot()->assertOk();
        $code = $this->emailedCode();

        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $row = PasswordReset::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(64, strlen($row->token_hash));
        $this->assertNotSame($code, $row->token_hash);
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($row->expires_at, false), 1);
        $this->assertNull($row->used_at);

        // not in the database, not in the answer, not in anything the app logged
        $this->assertStringNotContainsString($code, json_encode(DB::table('password_resets')->get()->all()));
        $this->assertStringNotContainsString($code, $response->getContent());
        $this->assertStringNotContainsString($code, implode("\n", $this->logged));
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset_requested', 'actor_id' => $user->id]);
    }

    public function test_the_email_contains_the_code_and_the_minutes_but_not_the_password(): void
    {
        $this->makeUser();
        $this->forgot()->assertOk();

        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) {
            $text = $mail->render();

            return str_contains($text, $mail->code) && str_contains($text, '15 minutes') && ! str_contains($text, self::OLD);
        });
    }

    public function test_a_mail_failure_does_not_change_the_answer_or_log_the_code(): void
    {
        $this->makeUser();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->forgot()->assertOk()->assertJsonPath('data', null);

        $this->assertStringContainsString('could not be sent', implode("\n", $this->logged));
        $this->assertSame(1, PasswordReset::count());
    }

    public function test_forgot_password_validates_the_email(): void
    {
        $this->postJson('/internal/v1/auth/forgot-password', [], $this->headers())->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->forgot('not-an-email')->assertStatus(422);
        Mail::assertNothingSent();
    }

    // ---------------------------------------------------------------- reset-password

    public function test_a_valid_code_resets_the_password(): void
    {
        $user = $this->makeUser();
        $this->forgot();
        $code = $this->emailedCode();

        $response = $this->reset($code)->assertOk()->assertJsonPath('data', null)->assertJsonPath('error', null);

        $this->assertStringNotContainsString($code, $response->getContent());
        $this->assertStringNotContainsString(self::NEW, $response->getContent());
        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password_hash));
        $this->assertNotNull(PasswordReset::firstOrFail()->used_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset', 'actor_id' => $user->id]);
        $this->assertStringNotContainsString($code, implode("\n", $this->logged));
        $this->assertStringNotContainsString(self::NEW, implode("\n", $this->logged));
    }

    public function test_after_a_reset_the_old_password_fails_and_the_new_one_logs_in(): void
    {
        $this->makeUser();
        $this->forgot();
        $this->reset($this->emailedCode())->assertOk();

        $this->login(self::OLD)->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->login(self::NEW)->assertOk()->assertJsonPath('data.user.email', self::EMAIL)->assertJsonStructure(['data' => ['access_token']]);
    }

    public function test_a_reset_clears_the_failed_login_counter_and_the_lock(): void
    {
        $this->makeUser(['failed_logins' => 5, 'locked_until' => now()->addMinutes(10)]);
        $this->login(self::NEW)->assertStatus(423); // locked

        $this->forgot();
        $this->reset($this->emailedCode())->assertOk();

        $user = User::firstOrFail();
        $this->assertSame(0, $user->failed_logins);
        $this->assertNull($user->locked_until);
        $this->login(self::NEW)->assertOk();
    }

    public function test_a_reset_works_for_staff_accounts_and_keeps_their_role_and_branch(): void
    {
        $this->makeUser(['email' => 'manager@pharmassist.test', 'role' => 'branch_manager', 'branch_id' => 2]);
        $this->forgot('manager@pharmassist.test');

        $this->reset($this->emailedCode(), self::NEW, null, 'manager@pharmassist.test')->assertOk();

        $this->login(self::NEW, 'manager@pharmassist.test')->assertOk()->assertJsonPath('data.user.role', 'branch_manager')->assertJsonPath('data.user.branch_id', 2);
    }

    public function test_the_code_works_only_once(): void
    {
        $this->makeUser();
        $this->forgot();
        $code = $this->emailedCode();

        $this->reset($code)->assertOk();
        $this->reset($code, 'Another1Pass')->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RESET_CODE');

        $this->login(self::NEW)->assertOk();
        $this->login('Another1Pass')->assertStatus(401);
    }

    public function test_a_wrong_code_and_an_unknown_email_both_give_invalid_reset_code(): void
    {
        $user = $this->makeUser();
        $this->forgot();
        $code = $this->emailedCode();

        $this->reset($this->wrongCode($code))->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RESET_CODE')->assertJsonPath('data', null);
        $this->reset($code, self::NEW, null, 'nobody@pharmassist.test')->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RESET_CODE');

        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password_hash), 'the password did not change');
        $this->reset($code)->assertOk(); // and the real code is still good after the failed guesses
    }

    public function test_an_account_that_never_asked_for_a_reset_has_no_valid_code(): void
    {
        $this->makeUser();

        $this->reset('123456')->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RESET_CODE');
    }

    public function test_an_expired_code_gives_reset_code_expired_and_changes_nothing(): void
    {
        $user = $this->makeUser();
        $this->forgot();
        $code = $this->emailedCode();

        $this->travel(16)->minutes();

        $this->reset($code)->assertStatus(410)->assertJsonPath('error.code', 'RESET_CODE_EXPIRED');
        $this->reset($this->wrongCode($code))->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RESET_CODE'); // a wrong code is not "expired"
        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password_hash));
        $this->assertNull(PasswordReset::firstOrFail()->used_at);
    }

    public function test_a_code_that_is_still_inside_fifteen_minutes_works(): void
    {
        $this->makeUser();
        $this->forgot();
        $code = $this->emailedCode();

        $this->travel(14)->minutes();

        $this->reset($code)->assertOk();
    }

    public function test_a_new_code_cancels_the_previous_one(): void
    {
        $this->makeUser();
        $this->forgot();
        $first = $this->emailedCode();
        $this->forgot();
        $second = $this->emailedCode();

        $this->assertNotSame($first, $second, 'codes are drawn fresh each time');
        $this->assertSame(1, PasswordReset::whereNull('used_at')->count(), 'only the newest code is open');

        $this->reset($first)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RESET_CODE');
        $this->reset($second)->assertOk();
    }

    public function test_five_wrong_guesses_cancel_the_code(): void
    {
        $this->makeUser();
        $this->forgot();
        $code = $this->emailedCode();
        $wrong = $this->wrongCode($code);

        for ($i = 0; $i < 5; $i++) {
            $this->reset($wrong)->assertStatus(422);
        }

        $this->reset($code)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RESET_CODE');

        // asking again gives a fresh code and a fresh set of attempts
        $this->forgot();
        $this->reset($this->emailedCode())->assertOk();
    }

    public function test_a_weak_or_mismatched_password_is_rejected_and_the_code_is_not_used_up(): void
    {
        $user = $this->makeUser();
        $this->forgot();
        $code = $this->emailedCode();

        foreach (['short1', 'onlyletters', '12345678', ''] as $weak) {
            $this->reset($code, $weak)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['password']]]);
        }

        $this->reset($code, self::NEW, 'Different1Pass')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['password']]]);

        $this->postJson('/internal/v1/auth/reset-password', ['email' => self::EMAIL, 'code' => $code, 'password' => self::NEW], $this->headers())
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['password']]]); // no confirmation at all

        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password_hash));
        $this->assertNull(PasswordReset::firstOrFail()->used_at, 'validation failures do not use the code up');
        $this->reset($code)->assertOk();
    }

    public function test_the_code_and_email_formats_are_validated(): void
    {
        $this->makeUser();

        foreach (['12345', '1234567', 'abcdef', ''] as $badCode) {
            $this->reset($badCode)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['code']]]);
        }
        $this->reset('123456', self::NEW, null, 'not-an-email')->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    // ---------------------------------------------------------------- access

    public function test_both_routes_need_the_internal_key_and_the_gateway_is_the_only_caller(): void
    {
        $this->postJson('/internal/v1/auth/forgot-password', ['email' => self::EMAIL])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_INTERNAL_KEY');
        $this->postJson('/internal/v1/auth/reset-password', [], ['X-Internal-Key' => 'wrong'])->assertStatus(401);
        Mail::assertNothingSent();
    }

    public function test_the_answers_carry_the_response_envelope_and_a_request_id(): void
    {
        $this->makeUser();

        $this->forgot()->assertJsonStructure(['status', 'service', 'request_id', 'data', 'error', 'warnings'])->assertJsonPath('service', 'identity-service');
        $this->reset('000000')->assertJsonStructure(['status', 'service', 'request_id', 'data', 'error' => ['code', 'message', 'details'], 'warnings']);
        $this->assertSame(0, AuditLog::where('action', 'password_reset')->count());
    }
}
