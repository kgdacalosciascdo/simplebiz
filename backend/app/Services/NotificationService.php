<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class NotificationService
{
    public function __construct(private readonly AuditService $audit) {}

    public function inbox(Request $request, Company $company, User $user): array
    {
        $query = Notification::where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->where(function ($builder): void {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        $items = (clone $query)->latest()->limit(30)->get()->map(fn (Notification $notification): array => $this->payload($notification))->values();

        return [
            'items' => $items,
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
            'delivery' => ['state' => 'in_app_only', 'source' => 'Core notification inbox', 'external_channels' => 'unavailable'],
        ];
    }

    public function markRead(Request $request, Company $company, User $user, string $id): array
    {
        $notification = Notification::where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->whereKey($id)
            ->firstOrFail();

        if (! $notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
            $this->audit->record($request, 'core.notification.read', $notification, $company->id, ['read_at' => null], ['read_at' => $notification->read_at], null, 'Notification read', 'An in-app notification was marked as read.');
        }

        return $this->payload($notification->fresh());
    }

    public function markAllRead(Request $request, Company $company, User $user): array
    {
        $count = Notification::where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        if ($count > 0) {
            $this->audit->record($request, 'core.notifications.read_all', null, $company->id, ['unread_count' => $count], ['read_count' => $count], null, 'Notifications read', 'The authenticated user marked in-app notifications as read.');
        }

        return ['marked_read' => $count];
    }

    public function publish(
        Request $request,
        Company $company,
        User $user,
        string $eventKey,
        string $sourceModule,
        string $title,
        string $body,
        string $notificationKey,
        ?string $route = null,
        array $payload = [],
        string $severity = 'info',
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?\DateTimeInterface $expiresAt = null,
    ): Notification {
        return Notification::firstOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id, 'notification_key' => $notificationKey],
            [
                'id' => (string) Str::uuid(),
                'event_key' => $eventKey,
                'source_module' => $sourceModule,
                'title' => $title,
                'body' => $body,
                'severity' => $severity,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'route' => $route,
                'payload' => $payload,
                'expires_at' => $expiresAt,
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]
        );
    }

    private function payload(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'event_key' => $notification->event_key,
            'source_module' => $notification->source_module,
            'title' => $notification->title,
            'body' => $notification->body,
            'severity' => $notification->severity,
            'source_type' => $notification->source_type,
            'source_id' => $notification->source_id,
            'route' => $notification->route,
            'payload' => $notification->payload ?? [],
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ];
    }
}
