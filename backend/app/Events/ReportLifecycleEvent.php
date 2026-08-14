<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class ReportLifecycleEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $eventCode,
        public readonly int $companyId,
        public readonly ?string $requestId,
        public readonly ?string $definitionKey,
        public readonly ?int $definitionVersion,
        public readonly ?int $actorId,
        public readonly ?string $correlationId,
    ) {}
}
