<?php

return [
    'daily_automation_enabled' => (bool) env('CAMPAIGN_OPERATIONAL_DAILY_AUTOMATION_ENABLED', false),
    'daily_automation_auto_activate_enabled' => (bool) env('CAMPAIGN_OPERATIONAL_DAILY_AUTO_ACTIVATE_ENABLED', false),
    'daily_automation_max_incidents' => (int) env('CAMPAIGN_OPERATIONAL_DAILY_MAX_INCIDENTS', 0),
    'october_2026_enabled' => (bool) env('CAMPAIGN_OPERATIONAL_OCTOBER_2026_ENABLED', false),
    'november_2026_enabled' => (bool) env('CAMPAIGN_OPERATIONAL_NOVEMBER_2026_ENABLED', false),
];
