<?php

namespace App\Http\Controllers\Api\V1\B2B\Concerns;

use App\Services\B2B\B2bAccountSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

/**
 * P2-8: shared email verification, password reset and MFA endpoints so the
 * importer and exporter APIs stay behaviourally identical.
 */
trait HandlesAccountSecurity
{
    protected function security(): B2bAccountSecurityService
    {
        return app(B2bAccountSecurityService::class);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = $this->security()->verifyEmail($request->token);

        if (!$user) {
            return $this->errorResponse('Verification link is invalid or has expired', 400);
        }

        return $this->successResponse([
            'email_verified' => true,
            'email_verified_at' => $user->email_verified_at,
        ], 'Email verified successfully');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return $this->errorResponse('Email is already verified', 409);
        }

        $this->security()->sendVerification($user);

        return $this->successResponse([
            'sent' => true,
            'note' => 'If mail delivery is not configured, ask an administrator to read the verification token from the database.',
        ], 'Verification email sent');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $status = $this->security()->sendPasswordReset($request->email);

        if ($status === Password::RESET_LINK_SENT) {
            return $this->successResponse(['sent' => true], 'Password reset link sent');
        }

        if ($status === Password::RESET_THROTTLED) {
            return $this->errorResponse('Please wait before requesting another reset link', 429);
        }

        // Do not reveal whether the address exists.
        return $this->successResponse(['sent' => true], 'If the address exists, a reset link has been sent');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $status = $this->security()->resetPassword($request->only('email', 'password', 'password_confirmation', 'token'));

        if ($status === Password::PASSWORD_RESET) {
            return $this->successResponse(['reset' => true], 'Password reset successfully; all sessions were revoked');
        }

        return $this->errorResponse('Password reset failed: ' . __($status), 422);
    }

    public function twoFactorStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'enabled' => (bool) $user->two_factor_enabled,
            'confirmed_at' => $user->two_factor_confirmed_at,
            'pending_setup' => !$user->two_factor_enabled && (bool) $user->two_factor_secret,
        ], 'Two-factor status retrieved');
    }

    /**
     * Generate a pending TOTP secret and provisioning URI (not yet enabled).
     */
    public function twoFactorSetup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->two_factor_enabled) {
            return $this->errorResponse('Two-factor authentication is already enabled', 409);
        }

        $secret = $this->security()->generateSecret();
        $this->security()->storePendingSecret($user, $secret);

        return $this->successResponse([
            'secret' => $secret,
            'otpauth_url' => $this->security()->otpAuthUrl($user, $secret),
            'next_step' => 'Scan the URI, then confirm with POST two-factor/enable and a 6-digit code.',
        ], 'Two-factor secret generated; confirm with a code to enable');
    }

    public function twoFactorEnable(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|digits:6',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        if (!$this->security()->enableTwoFactor($request->user(), $request->code)) {
            return $this->errorResponse('Invalid authentication code', 422);
        }

        return $this->successResponse(['enabled' => true], 'Two-factor authentication enabled');
    }

    public function twoFactorDisable(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        if (!$this->security()->disableTwoFactor($request->user(), $request->password)) {
            return $this->errorResponse('Incorrect password', 422);
        }

        return $this->successResponse(['enabled' => false], 'Two-factor authentication disabled');
    }
}
