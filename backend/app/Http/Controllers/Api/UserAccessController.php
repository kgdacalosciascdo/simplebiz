<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\MembershipAccessHistory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\UserAccessService;
use App\Support\ApiResponse;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class UserAccessController extends Controller
{
    public function __construct(private readonly UserAccessService $service, private readonly IdempotencyService $idempotency) {}

    public function index(Request $request)
    {
        $company = $request->attributes->get('company');
        $query = User::query()->whereHas('companies', fn ($builder) => $builder->whereKey($company->id)->wherePivot('status', '!=', 'removed'));
        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $term = '%'.strtolower($search).'%';
            $query->where(fn ($builder) => $builder->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(email) LIKE ?', [$term]));
        }
        $status = $request->query('status');
        if (is_string($status) && in_array($status, ['active', 'suspended', 'deactivated'], true)) {
            $query->whereHas('companies', fn ($builder) => $builder->whereKey($company->id)->wherePivot('status', $status));
        }

        $users = $query->orderBy('name')->paginate(min((int) $request->query('per_page', 25), 100));
        $data = collect($users->items())->map(function (User $user) use ($company) {
            $membership = $user->companies()->whereKey($company->id)->first();
            $roles = $user->roles()->wherePivot('company_id', $company->id)->get(['roles.id', 'roles.name', 'roles.slug', 'roles.is_protected']);

            return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'account_status' => $user->status, 'membership_status' => $membership?->pivot?->status, 'is_owner' => (bool) $membership?->pivot?->is_owner, 'roles' => $roles, 'last_login_at' => $user->last_login_at];
        });

        return ApiResponse::success($data, 200, ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()]);
    }

    public function roles(Request $request)
    {
        return ApiResponse::success(Role::where('company_id', $request->attributes->get('company')->id)->where('status', 'active')->where('is_protected', false)->orderBy('name')->get(['id', 'name', 'slug']));
    }

    public function invitations(Request $request)
    {
        return ApiResponse::success(UserInvitation::with('role:id,name')->where('company_id', $request->attributes->get('company')->id)->latest()->limit(50)->get(['id', 'email', 'role_id', 'status', 'expires_at', 'accepted_at', 'cancelled_at', 'delivery_status', 'created_at']));
    }

    public function invite(Request $request)
    {
        $input = $request->validate(['email' => ['required', 'email', 'max:255'], 'role_id' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:500']]);
        $company = $request->attributes->get('company');

        return $this->idempotency->run($request, 'settings.users.invite', $company->id, fn () => $this->service->invite($request, $company, $input));
    }

    public function resend(Request $request, UserInvitation $invitation)
    {
        $company = $request->attributes->get('company');

        return $this->idempotency->run($request, 'settings.users.invitation.resend', $company->id, fn () => $this->service->resend($request, $company, $invitation));
    }

    public function cancel(Request $request, UserInvitation $invitation)
    {
        $company = $request->attributes->get('company');

        return $this->idempotency->run($request, 'settings.users.invitation.cancel', $company->id, fn () => $this->service->cancel($request, $company, $invitation));
    }

    public function accept(Request $request)
    {
        $input = $request->validate(['token' => ['required', 'string'], 'name' => ['required', 'string', 'max:120'], 'password' => ['required', 'string', 'min:8']]);

        return $this->idempotency->run($request, 'settings.users.invitation.accept', null, fn () => $this->service->accept($request, $input['token'], $input));
    }

    public function changeRole(Request $request, User $user)
    {
        $input = $request->validate(['role_id' => ['required', 'integer']]);
        $company = $request->attributes->get('company');
        $role = Role::where('company_id', $company->id)->find($input['role_id']);
        if (! $role) {
            return ApiResponse::error('The requested role was not found in the active company.', 404);
        }

        return $this->idempotency->run($request, 'settings.users.role.change', $company->id, fn () => $this->service->changeRole($request, $company, $user, $role));
    }

    public function changeStatus(Request $request, User $user, string $status)
    {
        $input = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $company = $request->attributes->get('company');

        return $this->idempotency->run($request, 'settings.users.membership.status', $company->id, fn () => $this->service->changeStatus($request, $company, $user, $status, $input['reason'] ?? 'Administrative access status change'));
    }

    public function history(Request $request, User $user)
    {
        $company = $request->attributes->get('company');
        if (! $user->companies()->whereKey($company->id)->exists()) {
            return ApiResponse::error('The requested user is outside the active company scope.', 404);
        }

        return ApiResponse::success(MembershipAccessHistory::where('company_id', $company->id)->where('user_id', $user->id)->latest()->limit(100)->get());
    }

    public function activity(Request $request)
    {
        $events = ActivityEvent::where('company_id', $request->attributes->get('company')->id)->whereIn('event', ['setup.completed', 'company.profile.updated', 'user.invitation.created', 'user.invitation.resent', 'user.invitation.cancelled', 'user.invitation.accepted', 'user.access.role.changed', 'user.access.membership.active', 'user.access.membership.suspended', 'user.access.membership.deactivated', 'company.context.changed'])->latest('occurred_at')->limit(30)->get(['id', 'event', 'title', 'description', 'entity_type', 'entity_id', 'occurred_at']);

        return ApiResponse::success($events);
    }
}
