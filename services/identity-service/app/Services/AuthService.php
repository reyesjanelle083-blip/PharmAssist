<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AuthService
{
    public const MAX_FAILED_LOGINS = 5;
    public const LOCK_MINUTES = 15;
    public const CODE_TTL_MINUTES = 15;

    /** Private disk (storage/app/private). Files are never served by URL. */
    private const DISK = 'local';

    public function __construct(
        private readonly JwtIssuer $jwt,
        private readonly AuditLogger $audit,
    ) {}

    public static function hashCode(string $code): string
    {
        // Keyed hash: a leaked table cannot be brute-forced without the app key.
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    public function register(array $data, UploadedFile $idPhoto): User
    {
        $email = strtolower(trim($data['email']));

        if (User::where('email', $email)->exists()) {
            throw $this->emailExists();
        }

        $path = $idPhoto->store('id-documents', self::DISK); // random file name

        try {
            [$user, $code] = DB::transaction(function () use ($data, $email, $path) {
                $user = User::create([
                    'email' => $email,
                    'password_hash' => Hash::make($data['password']),
                    'role' => 'customer',
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'contact_no' => $data['contact_no'],
                    'verification_status' => 'pending',
                    'id_document_path' => $path,
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
            Storage::disk(self::DISK)->delete($path);
            throw $this->emailExists();
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
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
            $user->verification_status === 'pending' => ['ACCOUNT_PENDING', 'Your account is waiting for approval.'],
            $user->verification_status === 'rejected' => ['ACCOUNT_REJECTED', 'Your account was not approved.'],
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
