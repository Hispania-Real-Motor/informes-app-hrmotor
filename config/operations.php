<?php

return [
    // Null preserves the existing catalog schedule. An explicitly empty value
    // disables every scheduled task; otherwise only listed keys may run.
    'scheduled_task_keys' => env('OPERATIONS_SCHEDULED_TASK_KEYS'),
    'operational_interest_current_sync_enabled' => (bool) env('OPERATIONS_INTEREST_OPERATIONAL_CURRENT_SYNC_ENABLED', false),
];
