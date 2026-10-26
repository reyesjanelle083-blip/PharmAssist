<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Mail\PasswordResetCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class AuthService
{
    public const MAX_FAILED_LOGINS = 5;
    public const LOCK_MINUTES = 15;
    public const CODE_TTL_MINUTES = 15;
    /** Wrong reset codes allowed for one email before the code in force is cancelled and a new one must be requested. */
    public const MAX_RESET_ATTEMPTS = 5;

    public function __construct(
        private readonly JwtIssuer $jwt,
        private readonly AuditLogger $audit,
    ) {}

    public static function hashCode(string $code): string
    {
        // Keyed hash: a leaked table cannot be brute-forced without the app key.
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * A customer registers with an email and a password, verifies the email with a 6-digit code, then logs in. There is no ID photo
     * and no admin approval of the account.
     */
    public function register(array $data): User
    {
        $email = strtolower(trim($data['email']));

        if (User::where('email', $email)->exists()) {
            throw $this->emailExists();
        }

        try {
            [$user, $code] = DB::transaction(function () use ($data, $email) {
                $user = User::create([
                    'email' => $email,
                    'password_hash' => Hash::make($data['password']),
                    'role' => 'customer',
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'contact_no' => $data['contact_no'],
                    // Legacy column from the old ID-approval flow: nothing reads it any more. 'approved' keeps the row
                    // valid for an older build, and a customer is never held back by it.
                    'verification_status' => 'approved',
                ]);

                $code = (string) random_int(100000, 999999);
                EmailVerificationCode::create([
                    'user_id' => $user->id,
                    'code_hash' => self::hashCode($code),
                    'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                ]);

                return [$user, $code];
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->emailExists();
        }

        $this->audit->record('register', $user->id, 'user', $user->id);

        // No email is sent yet. The code is written to the log in the local environment only.
        if (app()->environment('local')) {
            Log::info('Email verification code (local only)', ['user_id' => $user->id, 'code' => $code]);
        }

        return $user;
    }

    public function verifyEmail(string $email, string $code): void
    {
        $user = User::where('email', strtolower(trim($email)))->first();

        $record = $user
            ? EmailVerificationCode::where('user_id', $user->id)->whereNull('used_at')->orderByDesc('id')->first()
            : null;

        if (! $record || ! hash_equals($record->code_hash, self::hashCode($code))) {
            throw new ApiException('INVALID_CODE', 'The verification code is invalid.', 422);
        }

        if ($record->expires_at->isPast()) {
            throw new ApiException('CODE_EXPIRED', 'The verification code has expired.', 410);
        }

        DB::transaction(function () use ($user, $record) {
            $record->update(['used_at' => now()]);
            $user->update(['email_verified_at' => $user->email_verified_at ?? now()]);
        });
    }

    /**
     * Sends a 6-digit reset code to the email, when it belongs to an active account. The caller answers the same either way, so this method
     * never says whether the email exists. Only the hash of the code is stored (table password_resets, one open code per user: asking again
     * cancels the earlier one), and the code is never logged by the application.
     */
    public function forgotPassword(string $email): void
    {
        $email = strtolower(trim($email));
        $user = User::where('email', $email)->first();

        // The same work happens for an unknown email, so the answer does not take noticeably longer for a real one.
        $code = (string) random_int(100000, 999999);
        $hash = self::hashCode($code);

        if (! $user || ! $user->is_active) {
            return;
        }

        DB::transaction(function () use ($user, $hash) {
            PasswordReset::where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
            PasswordReset::create([
                'user_id' => $user->id,
                'token_hash' => $hash,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            ]);
        });
        RateLimiter::clear(self::resetAttemptsKey($email)); // a new code starts with a fresh set of attempts

        $this->audit->record('password_reset_requested', $user->id, 'user', $user->id);

        try {
            Mail::to($user->email)->send(new PasswordResetCodeMail($code, self::CODE_TTL_MINUTES));
        } catch (Throwable $e) {
            // A mail failure must not turn into an answer that tells an outsider this email exists. Never log the code.
            Log::error('Password reset email could not be sent', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }

    /**
     * @throws ApiException 422 INVALID_RESET_CODE (wrong, used, replaced or cancelled), 410 RESET_CODE_EXPIRED
     */
    public function resetPassword(string $email, string $code, string $password): void
    {
        $email = strtolower(trim($email));
        $user = User::where('email', $email)->first();
        $record = $user
            ? PasswordReset::where('user_id', $user->id)->whereNull('used_at')->orderByDesc('id')->first()
            : null;

        if (! $record || ! hash_equals($record->token_hash, self::hashCode($code))) {
            if ($record) {
                $key = self::resetAttemptsKey($email);
                RateLimiter::hit($key, self::CODE_TTL_MINUTES * 60);
                if (RateLimiter::attempts($key) >= self::MAX_RESET_ATTEMPTS) {
                    $record->update(['used_at' => now()]); // too many guesses: this code is dead, ask for a new one
                    RateLimiter::clear($key);
                }
            }

            throw $this->invalidResetCode();
        }

        if ($record->expires_at->isPast()) {
            throw new ApiException('RESET_CODE_EXPIRED', 'The reset code has expired. Please request a new one.', 410);
        }

        DB::transaction(function () use ($user, $record, $password) {
            // Locked and re-read so two requests with the same code cannot both succeed.
            $current = PasswordReset::whereKey($record->id)->lockForUpdate()->first();
            if (! $current || $current->used_at !== null) {
                throw $this->invalidResetCode();
            }

            PasswordReset::where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
            $user->forceFill([
                'password_hash' => Hash::make($password),
                'failed_logins' => 0,
                'locked_until' => null,
            ])->save();
        });

        RateLimiter::clear(self::resetAttemptsKey($email));
        $this->audit->record('password_reset', $user->id, 'user', $user->id);
    }

    private static function resetAttemptsKey(string $email): string
    {
        return 'password-reset:'.sha1($email);
    }

    private function invalidResetCode(): ApiException
    {
        return new ApiException('INVALID_RESET_CODE', 'The reset code is invalid or has already been used.', 422);
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: User}
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', strtolower(trim($email)))->first();

        if (! $user) {
            Hash::check($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // equalise timing
            $this->audit->record('login_failed');
            throw $this->invalidCredentials();
        }

        if ($user->locked_until && $user->locked_until->isFuture()) {
            $this->audit->record('login_failed', $user->id, 'user', $user->id);
            throw new ApiException('ACCOUNT_LOCKED', 'Account temporarily locked. Try again later.', 423, [
                'retry_after_seconds' => (int) now()->diffInSeconds($user->locked_until, false),
            ]);
        }

        if ($user->locked_until) { // lock has expired: start counting again
            $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();
        }

        if (! Hash::check($password, $user->getAuthPassword())) {
            $failures = $user->failed_logins + 1;
            $user->forceFill([
                'failed_logins' => $failures,
                'locked_until' => $failures >= self::MAX_FAILED_LOGINS ? now()->addMinutes(self::LOCK_MINUTES) : null,
            ])->save();

            $this->audit->record('login_failed', $user->id, 'user', $user->id);
            throw $this->invalidCredentials();
        }

        $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();

        $blocked = match (true) {
            $user->email_verified_at === null => ['EMAIL_NOT_VERIFIED', 'Please verify your email first.'],
            ! $user->is_active => ['ACCOUNT_INACTIVE', 'This account is inactive.'],
            default => null,
        };

        if ($blocked) {
            $this->audit->record('login_denied', $user->id, 'user', $user->id);
            throw new ApiException($blocked[0], $blocked[1], 403);
        }

        $issued = $this->jwt->issue($user);
        $this->audit->record('login_success', $user->id, 'user', $user->id);

        return [
            'access_token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_in' => $issued['expires_in'],
            'user' => $user,
        ];
    }

    public function me(int $id): User
    {
        return User::findOrFail($id);
    }

    private function invalidCredentials(): ApiException
    {
        return new ApiException('INVALID_CREDENTIALS', 'Invalid email or password', 401);
    }

    private function emailExists(): ApiException
    {
        return new ApiException('EMAIL_EXISTS', 'An account with this email already exists.', 409);
    }
}
