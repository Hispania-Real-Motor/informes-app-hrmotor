<?php

return [
    'daily_automation_enabled' => (bool) env('CAMPAIGN_OPERATIONAL_DAILY_AUTOMATION_ENABLED', false),
    'daily_automation_auto_activate_enabled' => (bool) env('CAMPAIGN_OPERATIONAL_DAILY_AUTO_ACTIVATE_ENABLED', false),
    'daily_automation_allow_partial_publication_enabled' => (bool) env('CAMPAIGN_OPERATIONAL_DAILY_ALLOW_PARTIAL_PUBLICATION_ENABLED', false),
    'october_2026_enabled' => (bool) env('CAMPAIGN_OPERATIONAL_OCTOBER_2026_ENABLED', false),
    'november_2026_enabled' => (bool) env('CAMPAIGN_OPERATIONAL_NOVEMBER_2026_ENABLED', false),
    'enabled_months' => array_values(array_filter(array_map('trim', explode(',', (string) env('CAMPAIGN_OPERATIONAL_ENABLED_MONTHS', ''))))),
];
