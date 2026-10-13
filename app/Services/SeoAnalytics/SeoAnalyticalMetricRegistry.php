<?php

namespace App\Services\SeoAnalytics;

final class SeoAnalyticalMetricRegistry
{
    public const MODULE = 'seo';

    public const SALESFORCE_SOURCE_IDENTIFIER = 'salesforce-organic-interests';

    /**
     * @return array<int, array{key: string, label: string, source: string, source_label: string, scope: string, format: string, field: string}>
     */
    public function metrics(): array
    {
        return [
            ['key' => 'search_console_clicks', 'label' => 'Clicks orgánicos', 'source' => 'search_console', 'source_label' => 'Search Console', 'scope' => 'ESP', 'format' => 'integer', 'field' => 'clicks'],
            ['key' => 'search_console_impressions', 'label' => 'Impresiones orgánicas', 'source' => 'search_console', 'source_label' => 'Search Console', 'scope' => 'ESP', 'format' => 'integer', 'field' => 'impressions'],
            ['key' => 'search_console_ctr', 'label' => 'CTR', 'source' => 'search_console', 'source_label' => 'Search Console', 'scope' => 'ESP', 'format' => 'percent', 'field' => 'ctr'],
            ['key' => 'search_console_position', 'label' => 'Posición media', 'source' => 'search_console', 'source_label' => 'Search Console', 'scope' => 'ESP', 'format' => 'decimal', 'field' => 'position'],
            ['key' => 'salesforce_organic_interests', 'label' => 'Interés orgánico (Salesforce)', 'source' => 'salesforce', 'source_label' => 'Salesforce', 'scope' => 'all', 'format' => 'integer', 'field' => 'interest_count'],
            ['key' => 'ga4_organic_key_events', 'label' => 'Conversiones web orgánicas (GA4)', 'source' => 'ga4', 'source_label' => 'GA4', 'scope' => 'ESP', 'format' => 'decimal', 'field' => 'key_events'],
        ];
    }
}
