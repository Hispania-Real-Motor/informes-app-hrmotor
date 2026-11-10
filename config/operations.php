<?php

return [
    // Null preserves the existing catalog schedule. An explicitly empty value
    // disables every scheduled task; otherwise only listed keys may run.
    'scheduled_task_keys' => env('OPERATIONS_SCHEDULED_TASK_KEYS'),
];
