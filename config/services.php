<?php

$commissionsApiCredentials = json_decode((string) env('COMMISSIONS_API_CREDENTIALS'), true);

if (! is_array($commissionsApiCredentials)) {
    $commissionsApiCredentials = [];
}

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'meta_ads' => [
        'api_version' => env('META_API_VERSION', 'v25.0'),
        'access_token' => env('META_ACCESS_TOKEN'),
        'ad_account_ids' => array_values(array_filter(array_map('trim', explode(',', env('META_AD_ACCOUNT_IDS', ''))))),
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
    ],

    'google_ads' => [
        'api_version' => env('GOOGLE_ADS_API_VERSION', 'v25'),
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_ADS_REFRESH_TOKEN'),
        'customer_ids' => array_values(array_filter(array_map('trim', explode(',', env('GOOGLE_ADS_CUSTOMER_IDS', ''))))),
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
    ],

    'google_search_console' => [
        'client_id' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_SEARCH_CONSOLE_REFRESH_TOKEN'),
        'property' => env('GOOGLE_SEARCH_CONSOLE_PROPERTY'),
        'token_url' => 'https://oauth2.googleapis.com/token',
        'sites_url' => 'https://www.googleapis.com/webmasters/v3/sites',
        'search_analytics_url' => 'https://www.googleapis.com/webmasters/v3/sites',
    ],

    'google_analytics' => [
        'client_id' => env('GOOGLE_ANALYTICS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ANALYTICS_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_ANALYTICS_REFRESH_TOKEN'),
        'property_id' => env('GOOGLE_ANALYTICS_PROPERTY_ID'),
        'token_url' => 'https://oauth2.googleapis.com/token',
        'data_api_url' => 'https://analyticsdata.googleapis.com/v1beta',
        'admin_api_url' => 'https://analyticsadmin.googleapis.com/v1beta',
    ],

    'sistrix' => [
        'api_key' => env('SISTRIX_API_KEY'),
        'credits_url' => 'https://api.sistrix.com/credits',
    ],

    'report_admin' => [
        'email' => env('REPORT_ADMIN_EMAIL'),
        'password' => env('REPORT_ADMIN_PASSWORD'),
        'name' => env('REPORT_ADMIN_NAME', 'Administrador informes'),
    ],

    'internal_reviews' => [
        'endpoint' => env('INTERNAL_REVIEWS_ENDPOINT'),
        'user' => env('INTERNAL_REVIEWS_USER'),
        'password' => env('INTERNAL_REVIEWS_PASSWORD'),
        'timeout' => (int) env('INTERNAL_REVIEWS_TIMEOUT', 20),
        'cache_minutes' => (int) env('INTERNAL_REVIEWS_CACHE_MINUTES', 15),
    ],

    'commissions_api' => [
        'user' => env('COMMISSIONS_API_USER'),
        'password' => env('COMMISSIONS_API_PASSWORD'),
        'credentials' => $commissionsApiCredentials,
        'rate_limit_per_minute' => (int) env('COMMISSIONS_API_RATE_LIMIT_PER_MINUTE', 120),
        'auth_failures_per_minute' => (int) env('COMMISSIONS_API_AUTH_FAILURES_PER_MINUTE', 10),
    ],

];
