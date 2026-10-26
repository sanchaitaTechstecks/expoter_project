<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ExporterAuthController extends B2bBaseController
{
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
            'phone' => 'nullable|string|max:20',
            'company_name' => 'required|string|max:255',
            'country' => 'required|string|max:100',
            'iec_code' => 'required|string|max:50', // Exporter needs IEC (Import Export Code)
            'tax_number' => 'nullable|string|max:50',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:50',
            'swift_bic' => 'nullable|string|max:30',
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
            'user_type' => 'seller',
            'email_verified_at' => now(),
            'country' => $request->country,
        ]);

        $company = Company::create([
            'user_id' => $user->id,
            'name' => $request->company_name,
            'legal_name' => $request->company_name,
            'company_type' => 'exporter',
            'trade_type' => $request->trade_type ?: 'both',
            'country' => $request->country,
            'iec_code' => $request->iec_code,
            'tax_number' => $request->tax_number,
            'contact_email' => $request->email,
            'contact_phone' => $request->phone,
            'bank_name' => $request->bank_name,
            'bank_account_number' => $request->bank_account_number,
            'swift_bic' => $request->swift_bic,
            'verification_status' => 'pending',
        ]);

        $token = $user->createToken('exporter_auth_token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type,
            ],
            'company' => $company,
        ], 'Exporter registered successfully', 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Invalid email or password credentials', 401);
        }

        $company = Company::where('user_id', $user->id)->first();

        $token = $user->createToken('exporter_auth_token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type,
            ],
            'company' => $company,
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
            ['name' => $user->name . ' Exports']
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
