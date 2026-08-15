<?php

namespace App\Services;

use App\Models\Company;
use App\Models\MembershipAccessHistory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserAccessService
{
    public function __construct(private readonly AuditService $audit) {}

    public function invite(Request $request, Company $company, array $input)
    {
        return DB::transaction(function () use ($request, $company, $input) {
            $email = strtolower($input['email']);
            $role = Role::where('company_id', $company->id)->whereKey($input['role_id'])->where('status', 'active')->first();
            if (! $role || $role->is_protected) {
                return ApiResponse::error('That role is not available for invitation.', 422);
            }
            $existing = User::where('email', $email)->whereHas('companies', fn ($query) => $query->whereKey($company->id)->wherePivot('status', 'active'))->exists();
            if ($existing) {
                return ApiResponse::error('This user already has active access to the company.', 409);
            }
            $pending = UserInvitation::where('company_id', $company->id)->where('email', $email)->whereIn('status', ['pending', 'resent'])->where('expires_at', '>', now())->exists();
            if ($pending) {
                return ApiResponse::error('An active invitation already exists for this email.', 409);
            }

            $rawToken = Str::random(64);
            $invitation = UserInvitation::create([
                'company_id' => $company->id, 'invited_by' => $request->user()->id, 'role_id' => $role->id,
                'email' => $email, 'token_hash' => hash('sha256', $rawToken), 'status' => 'pending',
                'expires_at' => now()->addDays(7), 'delivery_status' => config('mail.default') === 'log' ? 'logged' : 'not_configured',
            ]);
            $this->audit->record($request, 'user.invitation.created', $invitation, $company->id, [], ['email' => $email, 'role_id' => $role->id], null, 'User invitation created', "An invitation was created for {$email}.");

            $data = ['id' => $invitation->id, 'email' => $invitation->email, 'role' => ['id' => $role->id, 'name' => $role->name], 'status' => $invitation->status, 'expires_at' => $invitation->expires_at, 'delivery_status' => $invitation->delivery_status];
            if (app()->environment('local', 'testing')) {
                $data['development_token'] = $rawToken;
            }

            return ApiResponse::success($data, 201);
        });
    }

    public function resend(Request $request, Company $company, UserInvitation $invitation)
    {
        return DB::transaction(function () use ($request, $company, $invitation) {
            $invitation = UserInvitation::where('company_id', $company->id)->whereKey($invitation->id)->lockForUpdate()->first();
            if (! $invitation || ! in_array($invitation->status, ['pending', 'resent'], true) || $invitation->expires_at->isPast()) {
                return ApiResponse::error('This invitation cannot be resent.', 409);
            }
            $before = $invitation->only(['status', 'expires_at']);
            $rawToken = Str::random(64);
            $invitation->update(['token_hash' => hash('sha256', $rawToken), 'status' => 'resent', 'expires_at' => now()->addDays(7), 'delivery_status' => config('mail.default') === 'log' ? 'logged' : 'not_configured']);
            $this->audit->record($request, 'user.invitation.resent', $invitation, $company->id, $before, $invitation->fresh()->only(['status', 'expires_at']), null, 'User invitation resent', "The invitation for {$invitation->email} was resent.");
            $data = ['id' => $invitation->id, 'status' => $invitation->status, 'expires_at' => $invitation->expires_at, 'delivery_status' => $invitation->delivery_status];
            if (app()->environment('local', 'testing')) {
                $data['development_token'] = $rawToken;
            }

            return ApiResponse::success($data);
        });
    }

    public function cancel(Request $request, Company $company, UserInvitation $invitation)
    {
        return DB::transaction(function () use ($request, $company, $invitation) {
            $invitation = UserInvitation::where('company_id', $company->id)->whereKey($invitation->id)->lockForUpdate()->first();
            if (! $invitation || ! in_array($invitation->status, ['pending', 'resent'], true)) {
                return ApiResponse::error('This invitation cannot be cancelled.', 409);
            }
            $before = $invitation->only(['status']);
            $invitation->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->audit->record($request, 'user.invitation.cancelled', $invitation, $company->id, $before, ['status' => 'cancelled'], null, 'User invitation cancelled', "The invitation for {$invitation->email} was cancelled.");

            return ApiResponse::success(['id' => $invitation->id, 'status' => $invitation->status]);
        });
    }

    public function accept(Request $request, string $rawToken, array $input)
    {
        return DB::transaction(function () use ($request, $rawToken, $input) {
            $invitation = UserInvitation::where('token_hash', hash('sha256', $rawToken))->lockForUpdate()->first();
            if (! $invitation || ! in_array($invitation->status, ['pending', 'resent'], true) || $invitation->expires_at->isPast()) {
                return ApiResponse::error('This invitation is invalid or expired.', 422);
            }
            $user = User::where('email', $invitation->email)->first();
            if ($user) {
                if (! Hash::check($input['password'], $user->password)) {
                    return ApiResponse::error('The invitation could not be accepted with the supplied credentials.', 422);
                }
                if ($user->companies()->whereKey($invitation->company_id)->exists()) {
                    return ApiResponse::error('This user already has a membership for the company.', 409);
                }
            } else {
                $user = User::create(['name' => $input['name'], 'email' => $invitation->email, 'password' => Hash::make($input['password']), 'status' => 'active']);
            }
            $user->companies()->attach($invitation->company_id, ['status' => 'active', 'is_owner' => false, 'last_active_at' => now()]);
            DB::table('role_user')->insert(['role_id' => $invitation->role_id, 'user_id' => $user->id, 'company_id' => $invitation->company_id, 'created_at' => now(), 'updated_at' => now()]);
            $invitation->update(['status' => 'accepted', 'accepted_at' => now(), 'accepted_by' => $user->id]);
            $request->setUserResolver(fn () => $user);
            $company = Company::findOrFail($invitation->company_id);
            $this->audit->record($request, 'user.invitation.accepted', $invitation, $company->id, ['status' => 'pending'], ['status' => 'accepted', 'user_id' => $user->id], null, 'User invitation accepted', "{$user->email} accepted a company invitation.");

            return ApiResponse::success(['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email], 'company' => ['id' => $company->id, 'name' => $company->name], 'token' => $user->createToken('simplebiz-web', ['*'], now()->addHours(8))->plainTextToken]);
        });
    }

    public function changeRole(Request $request, Company $company, User $user, Role $role)
    {
        return DB::transaction(function () use ($request, $company, $user, $role) {
            $membership = $user->companies()->whereKey($company->id)->first();
            if (! $membership || $role->company_id !== $company->id || $role->is_protected || $membership->pivot->is_owner) {
                return ApiResponse::error('This role change is not permitted.', 403);
            }
            $before = $user->roles()->wherePivot('company_id', $company->id)->get(['roles.id', 'roles.name'])->toArray();
            DB::table('role_user')->where('company_id', $company->id)->where('user_id', $user->id)->delete();
            DB::table('role_user')->insert(['role_id' => $role->id, 'user_id' => $user->id, 'company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->history($request, $company, $user, 'role.changed', $before, ['role_id' => $role->id], 'Role changed');

            return ApiResponse::success(['user_id' => $user->id, 'role' => ['id' => $role->id, 'name' => $role->name]]);
        });
    }

    public function changeStatus(Request $request, Company $company, User $user, string $status, ?string $reason = null)
    {
        if (! in_array($status, ['active', 'suspended', 'deactivated'], true)) {
            return ApiResponse::error('The membership status is invalid.', 422);
        }

        return DB::transaction(function () use ($request, $company, $user, $status) {
            $membership = $user->companies()->whereKey($company->id)->first();
            if (! $membership || $user->id === $request->user()->id && $status !== 'active') {
                return ApiResponse::error('This membership change is not permitted.', 403);
            }
            if ($membership->pivot->is_owner && $status !== 'active') {
                return ApiResponse::error('The protected Business Owner membership cannot be deactivated.', 403);
            }
            $before = ['status' => $membership->pivot->status];
            $user->companies()->updateExistingPivot($company->id, ['status' => $status]);
            $this->history($request, $company, $user, 'membership.'.$status, $before, ['status' => $status], 'Membership status changed', $reason);

            return ApiResponse::success(['user_id' => $user->id, 'status' => $status]);
        });
    }

    private function history(Request $request, Company $company, User $user, string $action, array $before, array $after, string $title, ?string $reason = null): void
    {
        MembershipAccessHistory::create(['company_id' => $company->id, 'user_id' => $user->id, 'actor_id' => $request->user()?->id, 'action' => $action, 'previous_state' => $before, 'new_state' => $after, 'reason' => $reason, 'source_channel' => 'api', 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now()]);
        $this->audit->record($request, 'user.access.'.$action, $user, $company->id, $before, $after, $reason, $title, "Access for {$user->email} changed.");
    }
}
