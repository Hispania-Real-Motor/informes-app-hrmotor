<?php

// Independent baseline captured from e7073d3; do not derive expectations from production catalog.
return [
    'reports-prune-transversal-data' => ['reports:prune-transversal-data', [], '30 0 * * *', 30],
    'stock-sync-daily' => ['stock:sync-daily', ['--sales-days' => '14', '--logistics-days' => '30'], '30 3 * * *', 120],
    'salesforce-sync-delegation-managers' => ['salesforce:sync-delegation-managers', ['--from' => '2026-07-01'], '15 4 * * *', 60],
    'salesforce-sync-monthly-commercial' => ['salesforce:sync-monthly-commercial', ['--days' => '2'], '*/15 * * * *', 30],
    'salesforce-sync-interest-reporting' => ['salesforce:sync-interest-reporting', [], '5 * * * *', 30],
    'salesforce-sync-tasaciones' => ['salesforce:sync-tasaciones', ['--days' => '120'], '0 1 * * *', 180],
    'campaigns-sync-meta' => ['campaigns:sync-meta', ['--days' => '120'], '30 1 * * *', 180],
    'campaigns-sync-google' => ['campaigns:sync-google', ['--days' => '120'], '45 1 * * *', 180],
    'campaigns-build-attribution' => ['campaigns:build-attribution', ['--days' => '120', '--require-ready' => true], '30 8 * * *', 240],
    'reports-refresh-campaigns' => ['reports:refresh-campaigns', ['--days' => '120', '--store' => true], '0 9 * * *', 120],
    'salesforce-sync-opportunities' => ['salesforce:sync-opportunities', ['--days' => '2', '--modified' => true], '10 7 * * *', 180],
    'salesforce-sync-opportunity-interest-direct' => ['salesforce:sync-opportunity-interest-direct', ['--reason' => 'Snapshot diario ROT-3 posterior al sync de Opportunities'], '35 7 * * *', 180],
    'salesforce-sync-calls' => ['salesforce:sync-calls', ['--days' => '7'], '45 4 * * *', 60],
    'seo-sync-search-console' => ['seo:sync-search-console', ['--days' => '120'], '15 5 * * *', 120],
    'seo-sync-salesforce-organic' => ['seo:sync-salesforce-organic', ['--days' => '120'], '30 5 * * *', 120],
    'seo-sync-ga4-organic' => ['seo:sync-ga4-organic', ['--days' => '120'], '45 5 * * *', 120],
    'seo-sync-technical-health' => ['seo:sync-technical-health', [], '0 6 * * *', 120],
    'seo-build-analytical-snapshots' => ['seo:build-analytical-snapshots', [], '15 6 * * *', 120],
    'seo-evaluate-analytical-snapshots' => ['seo:evaluate-analytical-snapshots', [], '30 6 * * *', 120],
    'seo-send-executive-daily-email' => ['seo:send-executive-daily-email', [], '0 8 * * *', 30],
];
