<?php

namespace App\Http\Controllers\Api\V1\Importer;

use App\Http\Controllers\Api\V1\B2B\Concerns\HandlesAccountSecurity;
use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\Company;
use App\Models\User;
use App\Services\B2B\B2bAccountSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ImporterAuthController extends B2bBaseController
{
    use HandlesAccountSecurity;

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
            'phone' => 'nullable|string|max:20',
            'company_name' => 'required|string|max:255',
            'country' => 'required|string|max:100',
            'iec_code' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:50',
            'trade_type' => 'nullable|in:domestic,international,both',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'user_type' => 'customer',
            // P2-8: email must be verified through a signed token, not auto-trusted.
            'email_verified_at' => null,
            'verification_status' => 'unverified',
            'country' => $request->country,
        ]);

        $verificationToken = app(B2bAccountSecurityService::class)->sendVerification($user);

        $company = Company::create([
            'user_id' => $user->id,
            'name' => $request->company_name,
            'legal_name' => $request->company_name,
            'company_type' => 'importer',
            'trade_type' => $request->trade_type ?: 'both',
            'country' => $request->country,
            'iec_code' => $request->iec_code,
            'tax_number' => $request->tax_number,
            'contact_email' => $request->email,
            'contact_phone' => $request->phone,
            'verification_status' => 'pending',
        ]);

        $token = $user->createToken('importer_auth_token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type,
            ],
            'company' => $company,
            'email_verified' => false,
            'verification_pending' => true,
            // Exposed only so automated tests and local setups without SMTP can verify.
            'verification_token' => app()->environment('local', 'testing') ? $verificationToken : null,
        ], 'Importer registered successfully; please verify your email', 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
            'two_factor_code' => 'nullable|digits:6',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Invalid email or password credentials', 401);
        }

        $security = app(B2bAccountSecurityService::class);

        // P2-8: second factor is mandatory once the user enables TOTP.
        if ($user->two_factor_enabled) {
            if (!$request->filled('two_factor_code')) {
                return response()->json([
                    'success' => false,
                    'message' => 'A two-factor authentication code is required',
                    'errors' => ['two_factor_required' => true],
                ], 401);
            }

            if (!$security->verifyTotp($security->currentSecret($user), $request->two_factor_code)) {
                return $this->errorResponse('Invalid two-factor authentication code', 401);
            }
        }

        if ($user->banned) {
            return $this->errorResponse('This account has been suspended', 403);
        }

        $company = Company::where('user_id', $user->id)->first();

        $user->last_login_at = now();
        $user->saveQuietly();

        $token = $user->createToken('importer_auth_token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type,
            ],
            'company' => $company,
            'email_verified' => (bool) $user->email_verified_at,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
        ], 'Login successful');
    }

    public function getProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = Company::where('user_id', $user->id)->first();

        return $this->successResponse([
            'user' => $user,
            'company' => $company,
        ], 'Profile retrieved successfully');
    }

    public function updateCompanyProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = Company::firstOrCreate(
            ['user_id' => $user->id],
            ['name' => $user->name . ' Co.']
        );

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:100',
            'iec_code' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:50',
            'registration_number' => 'nullable|string|max:100',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:30',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_name' => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:50',
            'swift_bic' => 'nullable|string|max:30',
            'iban' => 'nullable|string|max:50',
            'bank_branch' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company->update($request->only([
            'name',
            'legal_name',
            'country',
            'iec_code',
            'tax_number',
            'registration_number',
            'contact_email',
            'contact_phone',
            'website',
            'address',
            'city',
            'state',
            'postal_code',
            'bank_name',
            'bank_account_name',
            'bank_account_number',
            'swift_bic',
            'iban',
            'bank_branch',
        ]));

        return $this->successResponse($company, 'Company profile updated successfully');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(null, 'Logged out successfully');
    }
}
