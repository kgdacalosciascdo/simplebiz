<?php

return [
    'retention_days' => (int) env('REPORT_RETENTION_DAYS', 30),
    // Export formats are classified by the server as queue-capable work. The
    // request author cannot force a workload classification from the client.
    'queue' => [
        'enabled' => (bool) env('REPORT_QUEUE_ENABLED', true),
        'async_output_types' => ['pdf', 'xlsx', 'csv'],
        'tries' => (int) env('REPORT_QUEUE_TRIES', 3),
        'backoff_seconds' => [30, 120],
        'stale_after_seconds' => (int) env('REPORT_QUEUE_STALE_AFTER', 900),
    ],
    'delivery' => [
        'in_app_enabled' => true,
        'external_provider' => false,
    ],
];
