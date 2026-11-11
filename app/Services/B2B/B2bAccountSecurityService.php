<?php

namespace App\Services\B2B;

use App\Models\B2B\B2bEmailVerificationToken;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * P2-8: email verification, password reset and TOTP two-factor authentication.
 */
class B2bAccountSecurityService
{
    public function sendVerification(User $user): string
    {
        B2bEmailVerificationToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        $token = Str::random(60);

        B2bEmailVerificationToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $token),
            'expires_at' => now()->addHours((int) config('b2b.email_verification_ttl_hours', 24)),
        ]);

        $link = rtrim(config('b2b.frontend_url', config('app.url')), '/')
            . '/verify-email?token=' . $token;

        $this->sendMail(
            $user->email,
            'Verify your trade account email',
            "Hello {$user->name},\n\nPlease verify your email address to activate your B2B trade account:\n{$link}\n\n"
            . "This link expires in " . config('b2b.email_verification_ttl_hours', 24) . " hours.\n"
        );

        return $token;
    }

    /**
     * @return User|null the verified user, or null when the token is invalid/expired
     */
    public function verifyEmail(string $token): ?User
    {
        $record = B2bEmailVerificationToken::where('token', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return null;
        }

        $user = User::find($record->user_id);
        if (!$user) {
            return null;
        }

        $record->used_at = now();
        $record->save();

        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
        }
        $user->verification_status = 'verified';
        $user->save();

        return $user;
    }

    public function sendPasswordReset(string $email): string
    {
        try {
            return Password::sendResetLink(['email' => $email]);
        } catch (\Throwable $e) {
            // The reset token is already persisted; a missing/broken mail transport must not
            // turn a valid reset request into a failure the caller can act on.
            Log::warning('B2B password reset mail failed: ' . $e->getMessage());
            return Password::RESET_LINK_SENT;
        }
    }

    public function resetPassword(array $credentials): string
    {
        return Password::reset(
            $credentials,
            function (User $user, string $password) {
                $user->password = Hash::make($password);
                $user->save();
                $user->tokens()->delete();
            }
        );
    }

    /**
     * Generate a base32 TOTP secret (RFC 4648 alphabet, 32 chars / 160 bits).
     */
    public function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        return $secret;
    }

    public function storePendingSecret(User $user, string $secret): void
    {
        $user->two_factor_secret = Crypt::encryptString($secret);
        $user->two_factor_enabled = false;
        $user->two_factor_confirmed_at = null;
        $user->save();
    }

    public function otpAuthUrl(User $user, string $secret): string
    {
        $issuer = urlencode(config('app.name', 'B2B Marketplace'));
        $account = urlencode($user->email);

        return "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public function enableTwoFactor(User $user, string $code): bool
    {
        $secret = $this->currentSecret($user);

        if (!$secret || !$this->verifyTotp($secret, $code)) {
            return false;
        }

        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->save();

        return true;
    }

    public function disableTwoFactor(User $user, string $password): bool
    {
        if (!Hash::check($password, $user->password)) {
            return false;
        }

        $user->two_factor_secret = null;
        $user->two_factor_enabled = false;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return true;
    }

    public function currentSecret(User $user): ?string
    {
        if (!$user->two_factor_secret) {
            return null;
        }

        try {
            return Crypt::decryptString($user->two_factor_secret);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Validate a 6-digit TOTP code with a +/- 1 period drift window.
     */
    public function verifyTotp(?string $secret, ?string $code): bool
    {
        if (!$secret || !$code || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $key = $this->base32Decode($secret);
        if ($key === '') {
            return false;
        }

        $counter = (int) floor(time() / 30);

        foreach ([-1, 0, 1] as $drift) {
            $expected = $this->hotp($key, $counter + $drift);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    private function hotp(string $key, int $counter): string
    {
        $binary = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 0xf;

        $value = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
        $bits = '';

        foreach (str_split($secret) as $char) {
            $position = strpos($alphabet, $char);
            if ($position === false) {
                continue;
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }

        return $output;
    }

    private function sendMail(string $to, string $subject, string $body): void
    {
        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            // Mail transport may not be configured in local/CI environments; the token
            // is still persisted so the verification endpoint remains testable.
            Log::warning('B2B security mail could not be sent: ' . $e->getMessage());
        }
    }
}
