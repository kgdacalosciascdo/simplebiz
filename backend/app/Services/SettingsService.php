<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AdminExportRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyOwnershipTransfer;
use App\Models\ConfigurationChangeSet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class SettingsService
{
    public function __construct(private readonly AuditService $audit) {}

    public function workspace(Request $request, Company $company): array
    {
        $user = $request->user();
        $definitions = $this->destinationDefinitions();
        $destinations = collect($definitions)->filter(fn (array $definition) => $user?->hasPermission($definition['permission'], $company->id))->values();
        $required = [
            'name' => $company->name,
            'currency' => $company->currency,
            'timezone' => $company->timezone,
            'locale' => $company->locale,
            'fiscal_year_start_month' => $company->fiscal_year_start_month,
        ];
        $completed = collect($required)->filter(fn ($value) => filled($value))->count();
        $pendingInvitations = $company->users()->wherePivot('status', 'active')->count() > 0
            ? DB::table('user_invitations')->where('company_id', $company->id)->whereIn('status', ['pending', 'resent'])->where('expires_at', '>', now())->count()
            : 0;
        $pendingChanges = ConfigurationChangeSet::where('company_id', $company->id)->whereIn('status', ['draft', 'for_review', 'for_approval', 'scheduled', 'partially_applied'])->count();

        return [
            'destinations' => $destinations,
            'searchable_destinations' => $destinations->map(fn (array $definition) => [
                'title' => $definition['title'],
                'description' => $definition['description'],
                'route' => $definition['route'],
                'category' => $definition['category'],
                'permission' => $definition['permission'],
            ])->values(),
            'plan' => $this->planState(),
            'setup_progress' => [
                'completed' => $completed,
                'total' => count($required),
                'percentage' => (int) round(($completed / max(1, count($required))) * 100),
                'status' => $completed === count($required) ? 'complete' : 'incomplete',
                'missing' => collect($required)->filter(fn ($value) => blank($value))->keys()->values(),
            ],
            'needs_attention' => array_values(array_filter([
                $completed < count($required) ? ['key' => 'setup', 'severity' => 'high', 'title' => 'Company setup is incomplete', 'detail' => 'Complete the required company profile before relying on all defaults.', 'route' => '/settings/business-setup'] : null,
                $pendingInvitations > 0 ? ['key' => 'invitations', 'severity' => 'medium', 'title' => 'Pending invitations', 'detail' => "{$pendingInvitations} invitation(s) still need attention.", 'route' => '/settings/users-access'] : null,
                $pendingChanges > 0 ? ['key' => 'changes', 'severity' => 'medium', 'title' => 'Configuration changes need attention', 'detail' => "{$pendingChanges} governed change(s) are not yet fully published.", 'route' => '/settings/configuration'] : null,
            ])),
            'recently_used' => ActivityEvent::where('company_id', $company->id)->where('user_id', $user?->id)->latest('occurred_at')->limit(5)->get(['event', 'title', 'occurred_at']),
            'recent_activity' => ActivityEvent::where('company_id', $company->id)->latest('occurred_at')->limit(8)->get(['id', 'event', 'title', 'description', 'occurred_at']),
        ];
    }

    public function account(Request $request, Company $company): array
    {
        $user = $request->user();
        $preference = UserPreference::where('user_id', $user->id)->where('company_id', $company->id)->first();
        $companies = $user->companies()->wherePivot('status', 'active')->get()->map(function (Company $accessibleCompany) use ($user) {
            return [
                'id' => $accessibleCompany->id,
                'name' => $accessibleCompany->name,
                'status' => $accessibleCompany->status,
                'is_owner' => (bool) $accessibleCompany->pivot->is_owner,
                'membership_status' => $accessibleCompany->pivot->status,
                'roles' => $user->roles()->wherePivot('company_id', $accessibleCompany->id)->where('status', 'active')->get(['roles.id', 'roles.name', 'roles.slug', 'roles.system_key'])->values(),
            ];
        })->values();

        return [
            'my_profile' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->status, 'last_login_at' => $user->last_login_at],
            'preferences' => $preference ? $preference->only(['locale', 'timezone', 'date_format', 'number_format', 'paper_size', 'accessibility', 'notification_preferences', 'display_preferences', 'preferred_branch_id']) : [],
            'active_company' => ['id' => $company->id, 'name' => $company->name, 'timezone' => $company->timezone, 'currency' => $company->currency, 'locale' => $company->locale],
            'company_access' => $companies,
            'default_context' => ['company_id' => $user->preferred_company_id, 'branch_id' => $preference?->preferred_branch_id, 'source' => 'user_preference', 'authorization' => 'existing_access_only'],
            'subscription' => $this->planState(),
            'usage' => ['state' => 'unavailable', 'source' => 'Usage service', 'message' => 'No authoritative Usage service is configured in this deployment; usage is not presented as zero.', 'as_of' => null],
            'billing' => ['state' => 'unavailable', 'source' => 'Commerce service', 'message' => 'Billing administration is available through the authoritative Commerce service when configured; no payment credentials are stored here.'],
            'connected_products' => $this->connectedProducts(),
            'security' => ['state' => 'available', 'owner' => 'MDS-000/Core', 'route' => '/settings/security'],
        ];
    }

    public function saveProfile(Request $request, array $input): array
    {
        $user = $request->user();
        $before = $user->only(['name']);
        $user->forceFill(['name' => $input['name']])->save();
        $this->audit->record($request, 'settings.user.profile.updated', $user, $request->attributes->get('company')?->id, $before, $user->fresh()->only(['name']), $input['reason'] ?? null, 'Personal profile updated', 'The authenticated user updated permitted profile fields.');

        return $user->fresh()->only(['id', 'name', 'email', 'status', 'last_login_at']);
    }

    public function savePreferences(Request $request, Company $company, array $input): array
    {
        $user = $request->user();
        if (! empty($input['preferred_branch_id']) && ! DB::table('branches')->where('company_id', $company->id)->where('id', $input['preferred_branch_id'])->where('status', 'active')->exists()) {
            return ['error' => 'The preferred branch is not active in the current company scope.'];
        }
        $preference = UserPreference::firstOrNew(['user_id' => $user->id, 'company_id' => $company->id]);
        $before = $preference->exists ? $preference->only(array_keys($input)) : [];
        $preference->fill(collect($input)->except('reason')->all());
        $preference->save();
        $this->audit->record($request, 'settings.user.preferences.updated', $preference, $company->id, $before, $preference->fresh()->only(array_keys(collect($input)->except('reason')->all())), $input['reason'] ?? null, 'Personal preferences updated', 'A user preference was updated without changing company masters.');

        return $preference->fresh()->only(['locale', 'timezone', 'date_format', 'number_format', 'paper_size', 'accessibility', 'notification_preferences', 'display_preferences', 'preferred_branch_id']);
    }

    public function access(Request $request, Company $company): array
    {
        $user = $request->user();
        $roles = Role::where('company_id', $company->id)->where('status', 'active')->with(['permissions:id,key,name,module'])->orderBy('name')->get(['id', 'name', 'slug', 'description', 'status', 'is_protected', 'system_key']);
        $permissions = Permission::orderBy('module')->orderBy('key')->get(['id', 'key', 'name', 'module', 'description']);
        $assignments = $user->roles()->wherePivot('company_id', $company->id)->get(['roles.id', 'roles.name', 'roles.slug', 'roles.system_key'])->values();

        return [
            'current_user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'roles' => $roles,
            'permissions' => $permissions,
            'assigned_roles' => $assignments,
            'scope' => ['company_id' => $company->id, 'branch_scope' => 'basic/free-edition', 'custom_roles' => 'excluded', 'self_escalation' => 'blocked_server_side'],
        ];
    }

    public function sessions(Request $request): array
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        return PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $request->user()->id)->latest('last_used_at')->latest('created_at')->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])->map(fn (PersonalAccessToken $token) => [
            'id' => $token->id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at,
            'expires_at' => $token->expires_at,
            'created_at' => $token->created_at,
            'is_current' => $token->id === $currentId,
        ])->values()->all();
    }

    public function terminateSession(Request $request, int $tokenId): array
    {
        $token = PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $request->user()->id)->whereKey($tokenId)->first();
        if (! $token) {
            return ['error' => 'The requested session was not found.'];
        }
        $token->delete();
        $this->audit->record($request, 'security.session.terminated', null, $request->attributes->get('company')?->id, ['token_id' => $tokenId], ['terminated' => true], null, 'Session terminated', 'An authenticated session was terminated.');

        return ['id' => $tokenId, 'terminated' => true];
    }

    public function terminateOtherSessions(Request $request): array
    {
        $currentId = $request->user()->currentAccessToken()?->id;
        $query = PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $request->user()->id);
        if ($currentId) {
            $query->where('id', '!=', $currentId);
        }
        $count = $query->count();
        $query->delete();
        $this->audit->record($request, 'security.sessions.terminated_other', null, $request->attributes->get('company')?->id, ['count' => $count], ['terminated' => $count], null, 'Other sessions terminated', 'Other authenticated sessions were terminated.');

        return ['terminated' => $count];
    }

    public function configuration(Company $company, ?UserPreference $preference = null): array
    {
        $preference ??= UserPreference::where('company_id', $company->id)->first();
        $profile = [
            'name' => ['value' => $company->name, 'source' => 'company', 'version' => $company->settings_version],
            'legal_name' => ['value' => $company->legal_name, 'source' => 'company', 'version' => $company->settings_version],
            'currency' => ['value' => $company->currency, 'source' => 'company', 'version' => $company->settings_version],
            'timezone' => ['value' => $company->timezone, 'source' => 'company', 'version' => $company->settings_version],
            'locale' => ['value' => $company->locale, 'source' => 'company', 'version' => $company->settings_version],
            'date_format' => ['value' => $company->date_format ?? 'locale-default', 'source' => $company->date_format ? 'company' : 'platform-default', 'version' => $company->settings_version],
            'number_format' => ['value' => $company->number_format ?? 'locale-default', 'source' => $company->number_format ? 'company' : 'platform-default', 'version' => $company->settings_version],
            'paper_size' => ['value' => $company->paper_size ?? 'A4', 'source' => $company->paper_size ? 'company' : 'platform-default', 'version' => $company->settings_version],
            'fiscal_year_start_month' => ['value' => $company->fiscal_year_start_month, 'source' => $company->fiscal_year_start_month ? 'company' : 'setup-default', 'version' => $company->settings_version],
            'module_preferences' => ['value' => $company->module_preferences ?? [], 'source' => 'company', 'version' => $company->settings_version],
            'notification_defaults' => ['value' => $company->notification_defaults ?? $this->mandatoryNotificationDefaults(), 'source' => $company->notification_defaults ? 'company' : 'safe-default', 'version' => $company->settings_version],
        ];

        return [
            'company_id' => $company->id,
            'profile_version' => $company->settings_version,
            'effective' => $profile,
            'user_preference_source' => $preference?->only(['locale', 'timezone', 'date_format', 'number_format', 'paper_size', 'preferred_branch_id']),
            'precedence' => ['platform_default', 'edition', 'company', 'branch_module_document', 'role_user_preference'],
            'changes' => ConfigurationChangeSet::where('company_id', $company->id)->latest()->limit(30)->get(),
        ];
    }

    public function recordPublishedChange(Request $request, Company $company, array $before, array $after, ?string $reason = null): ConfigurationChangeSet
    {
        $nextVersion = ((int) $company->settings_version) + 1;
        $previous = ConfigurationChangeSet::where('company_id', $company->id)->where('domain', 'company')->where('setting_key', 'company.profile')->where('status', 'published')->latest('version')->first();
        $change = ConfigurationChangeSet::create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'created_by' => $request->user()?->id,
            'domain' => 'company',
            'setting_key' => 'company.profile',
            'version' => $nextVersion,
            'status' => 'published',
            'scope' => ['company_id' => $company->id],
            'proposed_values' => $after,
            'previous_values' => $before,
            'effective_values' => $after,
            'validation_result' => ['state' => 'valid', 'checked_at' => now()->toISOString()],
            'approval_result' => ['state' => 'not_required', 'edition' => 'Free'],
            'publication_result' => ['state' => 'published', 'atomic' => true],
            'reason' => $reason,
            'correlation_id' => $request->attributes->get('correlation_id'),
            'source_channel' => 'api',
            'effective_at' => now(),
            'published_at' => now(),
        ]);
        if ($previous) {
            $previous->update(['status' => 'superseded', 'superseded_at' => now()]);
        }
        $company->forceFill(['settings_version' => $nextVersion, 'settings_updated_at' => now()])->save();
        $this->audit->record($request, 'settings.configuration.published', $change, $company->id, $before, $after, $reason, 'Configuration published', 'A company configuration version was published and superseded the previous effective version.');

        return $change;
    }

    public function modules(Company $company): array
    {
        $modules = [
            ['key' => 'sales', 'name' => 'Sales & Receivables', 'owner' => 'MDS-200', 'state' => 'included', 'enabled' => true, 'editable' => false],
            ['key' => 'collections', 'name' => 'Collections & Receipts', 'owner' => 'MDS-300', 'state' => 'included', 'enabled' => true, 'editable' => false],
            ['key' => 'purchases', 'name' => 'Purchases & Payables', 'owner' => 'MDS-400', 'state' => 'included', 'enabled' => true, 'editable' => false],
            ['key' => 'payments', 'name' => 'Payments & Disbursements', 'owner' => 'MDS-500', 'state' => 'included', 'enabled' => true, 'editable' => false],
            ['key' => 'inventory', 'name' => 'Inventory', 'owner' => 'MDS-600', 'state' => 'included', 'enabled' => true, 'editable' => false],
            ['key' => 'cash-accounts', 'name' => 'Cash Accounts', 'owner' => 'MDS-700', 'state' => 'included', 'enabled' => true, 'editable' => false],
            ['key' => 'expenses', 'name' => 'Expenses', 'owner' => 'MDS-800', 'state' => 'included', 'enabled' => true, 'editable' => false],
            ['key' => 'reports', 'name' => 'Reports & Analytics', 'owner' => 'MDS-900', 'state' => 'included', 'enabled' => true, 'editable' => false],
            ['key' => 'master-registries', 'name' => 'Master Registries', 'owner' => 'MDS-1000', 'state' => 'included', 'enabled' => true, 'editable' => false],
        ];

        return ['company_id' => $company->id, 'edition' => 'Free', 'modules' => $modules, 'preferences' => $company->module_preferences ?? [], 'disable_behavior' => 'Edition-gated changes preserve all operational records and history.'];
    }

    public function notifications(Company $company, User $user): array
    {
        $preference = UserPreference::where('company_id', $company->id)->where('user_id', $user->id)->first();

        return [
            'company_defaults' => $company->notification_defaults ?? $this->mandatoryNotificationDefaults(),
            'user_preferences' => $preference?->notification_preferences ?? ['in_app' => true, 'email' => false],
            'mandatory' => $this->mandatoryNotificationDefaults(),
            'delivery' => ['state' => 'in_app_only', 'source' => 'Notification transport', 'message' => 'External email, SMS, and push delivery are not configured in this deployment.'],
        ];
    }

    public function saveNotifications(Request $request, Company $company, array $input): array
    {
        $user = $request->user();
        $preference = UserPreference::firstOrNew(['company_id' => $company->id, 'user_id' => $user->id]);
        $before = $preference->notification_preferences ?? [];
        $next = array_merge($before, $input['user_preferences'] ?? []);
        $next['in_app'] = true;
        $preference->notification_preferences = $next;
        $preference->save();
        $this->audit->record($request, 'settings.notifications.updated', $preference, $company->id, $before, $next, $input['reason'] ?? null, 'Notification preferences updated', 'User notification preferences were updated.');

        return $this->notifications($company, $user);
    }

    public function audit(Request $request, Company $company): array
    {
        $query = AuditLog::where('company_id', $company->id)->latest('created_at');
        if ($search = $request->query('search')) {
            $term = '%'.strtolower((string) $search).'%';
            $query->where(fn (Builder $builder) => $builder->whereRaw('LOWER(action) LIKE ?', [$term])->orWhereRaw('LOWER(reason) LIKE ?', [$term]));
        }
        $logs = $query->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

        return ['items' => $logs->items(), 'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()]];
    }

    public function createExport(Request $request, Company $company, array $input): AdminExportRequest
    {
        $user = $request->user();
        $payload = [
            'manifest' => ['export_type' => $input['export_type'], 'company_id' => $company->id, 'generated_at' => now()->toISOString(), 'scope' => $input['scope'] ?? ['administration' => true]],
            'company' => $company->only(['id', 'name', 'legal_name', 'currency', 'timezone', 'locale', 'fiscal_year_start_month', 'date_format', 'number_format', 'paper_size', 'settings_version']),
            'users' => $company->users()->wherePivot('status', '!=', 'removed')->get()->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email, 'status' => $member->status, 'membership_status' => $member->pivot->status, 'is_owner' => (bool) $member->pivot->is_owner, 'roles' => $member->roles()->wherePivot('company_id', $company->id)->pluck('roles.slug')])->values(),
            'roles' => Role::where('company_id', $company->id)->with('permissions:id,key')->get(['id', 'name', 'slug', 'status', 'is_protected'])->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name, 'slug' => $role->slug, 'status' => $role->status, 'is_protected' => (bool) $role->is_protected, 'permissions' => $role->permissions->pluck('key')])->values(),
            'configuration_changes' => ConfigurationChangeSet::where('company_id', $company->id)->latest()->limit(100)->get(),
            'administrative_activity' => AuditLog::where('company_id', $company->id)->latest('created_at')->limit(200)->get(),
        ];
        $export = AdminExportRequest::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'requested_by' => $user->id, 'export_type' => $input['export_type'], 'scope' => $input['scope'] ?? ['administration' => true], 'purpose' => $input['purpose'], 'status' => 'ready', 'package_payload' => $payload, 'ready_at' => now(), 'expires_at' => now()->addDay(), 'correlation_id' => $request->attributes->get('correlation_id')]);
        $this->audit->record($request, 'settings.export.generated', $export, $company->id, [], ['id' => $export->id, 'status' => 'ready', 'expires_at' => $export->expires_at], $input['purpose'], 'Administrative export generated', 'A scoped administrative export was generated without operational transaction payloads.');

        return $export;
    }

    public function requestOwnershipTransfer(Request $request, Company $company, array $input): CompanyOwnershipTransfer|array
    {
        $currentOwner = $request->user();
        $membership = $currentOwner->companies()->whereKey($company->id)->wherePivot('is_owner', true)->wherePivot('status', 'active')->first();
        $proposed = User::whereKey($input['user_id'])->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id)->where('company_user.status', 'active')->where('company_user.is_owner', false))->first();
        if (! $membership || ! $proposed || ! Hash::check($input['current_password'], $currentOwner->password) || ($input['confirmation'] ?? '') !== 'TRANSFER OWNERSHIP') {
            return ['error' => 'Ownership transfer requires the current owner password, a valid active member, and exact confirmation.'];
        }
        if (CompanyOwnershipTransfer::where('company_id', $company->id)->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
            return ['error' => 'A pending ownership transfer already exists for this company.'];
        }

        $transfer = CompanyOwnershipTransfer::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'current_owner_id' => $currentOwner->id, 'proposed_owner_id' => $proposed->id, 'requested_by' => $currentOwner->id, 'status' => 'pending', 'reason' => $input['reason'], 'expires_at' => now()->addDay(), 'correlation_id' => $request->attributes->get('correlation_id')]);
        $this->audit->record($request, 'company.ownership.transfer.requested', $transfer, $company->id, [], ['proposed_owner_id' => $proposed->id, 'status' => 'pending'], $input['reason'], 'Ownership transfer requested', 'A protected ownership transfer was requested and awaits receiving-owner acceptance.');

        return $transfer;
    }

    public function acceptOwnershipTransfer(Request $request, Company $company, CompanyOwnershipTransfer $transfer, array $input): array
    {
        if ((int) $transfer->company_id !== (int) $company->id || $transfer->status !== 'pending' || ($transfer->expires_at && $transfer->expires_at->isPast()) || (int) $transfer->proposed_owner_id !== (int) $request->user()->id || ($input['confirmation'] ?? '') !== 'ACCEPT OWNERSHIP') {
            return ['error' => 'This ownership transfer is not available for acceptance.'];
        }
        if (! Hash::check($input['current_password'], $request->user()->password)) {
            return ['error' => 'Receiving-owner password verification failed.'];
        }

        DB::transaction(function () use ($request, $company, $transfer) {
            $transfer = CompanyOwnershipTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $oldOwner = User::findOrFail($transfer->current_owner_id);
            $newOwner = User::findOrFail($transfer->proposed_owner_id);
            $ownerRole = Role::where('company_id', $company->id)->where('system_key', 'business_owner')->firstOrFail();
            $administratorRole = Role::where('company_id', $company->id)->where('system_key', 'administrator')->first();
            $company->users()->updateExistingPivot($oldOwner->id, ['is_owner' => false]);
            $company->users()->updateExistingPivot($newOwner->id, ['is_owner' => true]);
            DB::table('role_user')->where('company_id', $company->id)->whereIn('user_id', [$oldOwner->id, $newOwner->id])->delete();
            DB::table('role_user')->insert(['role_id' => $ownerRole->id, 'user_id' => $newOwner->id, 'company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);
            if ($administratorRole) {
                DB::table('role_user')->insert(['role_id' => $administratorRole->id, 'user_id' => $oldOwner->id, 'company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $transfer->update(['status' => 'completed', 'accepted_at' => now(), 'completed_at' => now()]);
            $this->audit->record($request, 'company.ownership.transfer.completed', $transfer, $company->id, ['owner_id' => $oldOwner->id], ['owner_id' => $newOwner->id, 'status' => 'completed'], $transfer->reason, 'Company ownership transferred', 'Company ownership was transferred after protected verification and acceptance.');
        });

        return ['id' => $transfer->id, 'status' => 'completed', 'owner_id' => $request->user()->id];
    }

    public function changeSets(Company $company): array
    {
        return ConfigurationChangeSet::where('company_id', $company->id)->latest()->limit(100)->get()->all();
    }

    public function destinationDefinitions(): array
    {
        return [
            ['key' => 'account', 'category' => 'Account & Subscription', 'title' => 'Account & Subscription', 'description' => 'My Profile, preferences, company access, edition, limits, billing references, connected products, and security handoff.', 'route' => '/settings/account', 'permission' => 'settings.account.view'],
            ['key' => 'business', 'category' => 'Business Setup', 'title' => 'Business Setup', 'description' => 'Company identity, branding, locale, currency, fiscal defaults, and document preferences.', 'route' => '/settings/business-setup', 'permission' => 'settings.company.view'],
            ['key' => 'users', 'category' => 'Users & Access', 'title' => 'Users & Access', 'description' => 'Invitations, predefined roles, company access, status lifecycle, and ownership safeguards.', 'route' => '/settings/users-access', 'permission' => 'settings.users.view'],
            ['key' => 'financial', 'category' => 'Financial & Documents', 'title' => 'Financial & Documents', 'description' => 'Effective company financial defaults and standard numbering references.', 'route' => '/settings/configuration', 'permission' => 'settings.configuration.view'],
            ['key' => 'workflow', 'category' => 'Modules & Workflow', 'title' => 'Modules & Workflow', 'description' => 'Free-edition module state and honest discovery for advanced approval policies.', 'route' => '/settings/modules', 'permission' => 'settings.modules.view'],
            ['key' => 'notifications', 'category' => 'Notifications', 'title' => 'Notifications', 'description' => 'User notification preferences and mandatory in-app notices.', 'route' => '/settings/notifications', 'permission' => 'settings.notifications.view'],
            ['key' => 'data', 'category' => 'Data & Integrations', 'title' => 'Data & Integrations', 'description' => 'Scoped administrative export and honest discovery for unavailable external connections.', 'route' => '/settings/data', 'permission' => 'settings.account.view'],
            ['key' => 'security', 'category' => 'Security & Audit', 'title' => 'Security & Audit', 'description' => 'Core-owned sessions, administrative activity, high-risk controls, and immutable history.', 'route' => '/settings/security', 'permission' => 'settings.sessions.view'],
        ];
    }

    private function planState(): array
    {
        return ['edition' => 'Free', 'state' => 'configured', 'subscription_status' => 'unavailable', 'source' => 'SimpleBIZ edition contract / Commerce service', 'as_of' => null, 'message' => 'The Free edition boundary is configured locally. Commercial subscription status and billing facts are not fabricated without the authoritative Commerce service.'];
    }

    private function connectedProducts(): array
    {
        return [
            ['key' => 'simplebiz-core', 'name' => 'SimpleBIZ Core', 'state' => 'included', 'owner' => 'MDS-000/Core'],
            ['key' => 'simplebiz-reports', 'name' => 'Reports & Analytics', 'state' => 'included', 'owner' => 'MDS-900'],
            ['key' => 'external-products', 'name' => 'External SimpleBIZ products', 'state' => 'unavailable', 'owner' => 'Product/Entitlement service', 'message' => 'No external product entitlement service is configured.'],
        ];
    }

    private function mandatoryNotificationDefaults(): array
    {
        return ['security' => true, 'ownership' => true, 'billing' => true, 'legal' => true, 'data_lifecycle' => true];
    }
}
