<?php

namespace App\Support;

use App\Models\ActivityEvent;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditService
{
    public function record(?Request $request, string $action, ?Model $record = null, ?int $companyId = null, array $before = [], array $after = [], ?string $reason = null, ?string $title = null, ?string $description = null): void
    {
        $correlationId = $request?->attributes->get('correlation_id');
        $actorId = $request?->user()?->id;
        $companyId ??= $request?->attributes->get('company')?->id;
        $entityType = $record ? $record::class : null;
        $entityId = $record?->getKey();
        $safeBefore = $this->sanitize($before);
        $safeAfter = $this->sanitize($after);

        AuditLog::create([
            'company_id' => $companyId,
            'user_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $safeBefore ?: null,
            'after' => $safeAfter ?: null,
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);

        if ($title) {
            ActivityEvent::create([
                'company_id' => $companyId,
                'user_id' => $actorId,
                'event' => $action,
                'title' => $title,
                'description' => $description,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'correlation_id' => $correlationId,
                'occurred_at' => now(),
            ]);
        }
    }

    private function sanitize(array $values): array
    {
        $blocked = ['password', 'password_confirmation', 'token', 'token_hash', 'secret', 'api_key', 'access_token'];
        foreach ($blocked as $key) {
            unset($values[$key]);
        }

        return $values;
    }
}
