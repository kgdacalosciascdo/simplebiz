<?php

return [
    // MDS-1100 may replace this default with a company policy when checkbook
    // configuration is available. The server remains authoritative meanwhile.
    'check_stale_days' => (int) env('SIMPLEBIZ_CHECK_STALE_DAYS', 180),
];
