<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A durable Collections business event. The event is intentionally small and
 * carries only stable source identity and request context; consumers resolve
 * the authoritative record from the source id after commit.
 */
class CollectionsLifecycleEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $eventName,
        public readonly int $companyId,
        public readonly string $documentType,
        public readonly string $documentId,
        public readonly ?int $actorId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $businessDate = null,
    ) {}
}
