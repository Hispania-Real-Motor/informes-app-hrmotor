<?php

return [
    'auth_mode' => env('SALESFORCE_AUTH_MODE', 'client_credentials'),

    'api_version' => env('SALESFORCE_API_VERSION', 'v60.0'),
    'timeout' => (int) env('SALESFORCE_TIMEOUT', 120),
    'interest_sync_overlap_seconds' => (int) env('SALESFORCE_INTEREST_SYNC_OVERLAP_SECONDS', 300),

    'token_url' => env('SALESFORCE_TOKEN_URL', 'https://login.salesforce.com/services/oauth2/token'),
    'authorize_url' => env('SALESFORCE_AUTHORIZE_URL', 'https://login.salesforce.com/services/oauth2/authorize'),

    'client_id' => env('SALESFORCE_CLIENT_ID'),
    'client_secret' => env('SALESFORCE_CLIENT_SECRET'),
    'redirect_uri' => env('SALESFORCE_REDIRECT_URI'),
    'scope' => env('SALESFORCE_SCOPE'),

    'refresh_token' => env('SALESFORCE_REFRESH_TOKEN'),

    'cache_key' => 'salesforce_access_token',
    'instance_url_cache_key' => 'salesforce_instance_url',
];
