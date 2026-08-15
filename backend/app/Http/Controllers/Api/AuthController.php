<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function login(Request $request)
    {
        $input = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($input['email']))->first();
        if (! $user || ! Hash::check($input['password'], $user->password)) {
            return ApiResponse::error('The provided credentials are incorrect.', 422);
        }
        if ($user->status !== 'active') {
            return ApiResponse::error('This account is not active.', 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record($request, 'auth.login', null, null, [], ['user_id' => $user->id], null, 'Signed in', 'An authenticated user signed in.');

        return ApiResponse::success([
            'user' => $this->userPayload($user),
            'companies' => $user->companies()->wherePivot('status', 'active')->get()->map(fn ($company) => $this->companyPayload($company))->values(),
            'preferred_company_id' => $user->preferred_company_id,
            'token' => $user->createToken('simplebiz-web', ['*'], now()->addHours(8))->plainTextToken,
        ]);
    }

    public function me(Request $request)
    {
        return ApiResponse::success([
            'user' => $this->userPayload($request->user()),
            'companies' => $request->user()->companies()->wherePivot('status', 'active')->get()->map(fn ($company) => $this->companyPayload($company))->values(),
            'preferred_company_id' => $request->user()->preferred_company_id,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $this->audit->record($request, 'auth.logout', null, null, [], ['user_id' => $user?->id], null, 'Signed out', 'An authenticated user signed out.');
        PersonalAccessToken::findToken((string) $request->bearerToken())?->delete();

        return ApiResponse::success(['message' => 'Signed out.']);
    }

    private function userPayload(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->status];
    }

    private function companyPayload($company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'currency' => $company->currency,
            'timezone' => $company->timezone,
            'locale' => $company->locale,
            'date_format' => $company->date_format,
            'number_format' => $company->number_format,
            'paper_size' => $company->paper_size,
            'settings_version' => $company->settings_version,
            'setup_status' => $company->setup_status,
            'membership_status' => $company->pivot?->status,
            'is_owner' => (bool) $company->pivot?->is_owner,
        ];
    }
}
