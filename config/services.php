<?php

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
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
    ],
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],
    'shopify_5core' => [
        'domain'   => env('SHOPIFY_5CORE_DOMAIN'),
        'api_key'  => env('SHOPIFY_5CORE_API_KEY'),
        'password' => env('SHOPIFY_5CORE_PASSWORD'),
    ],
    'walmart' => [
      'client_id' => env('WALMART_CLIENT_ID'),
      'client_secret' => env('WALMART_CLIENT_SECRET'),
      'url' => env('WALMART_API_URL', 'https://marketplace.walmartapis.com'),
      'sync_enabled' => env('WALMART_SYNC_ENABLED', true),
    ],

    'sendle' => [
        'key'    => env('SENDLE_KEY'),
        'secret' => env('SENDLE_SECRET'),
        'url'    => env('SENDLE_URL', 'https://sandbox.sendle.com/api'),
     ],
     'shippo' => [
        'base_url'  => env('SHIPPO_BASE_URL', 'https://api.goshippo.com'),
        'api_token' => env('SHIPPO_API_TOKEN'),
    ],
    'topdawg' => [
       'token' => env('TOPDAWG_API_TOKEN'),
    ],
    'mirakl' => [
      'base_url' => env('MIRAKL_BASE_URL'),
    ],
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    'reverb' => [
        'token' => env('REVERB_TOKEN'),
    ],
    'ebay' => [
        'token' => env('EBAY_ACCESS_TOKEN'),
    ],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT'),
    ],
    'macy' => [
        'client_id' => env('MACY_CLIENT_ID'),
        'client_secret' => env('MACY_CLIENT_SECRET'),
        'company_id' => env('MACY_COMPANY_ID'),
    ],
     'wayfair' => [
        'client_id' => env('WAYFAIR_CLIENT_ID'),
        'client_secret' => env('WAYFAIR_CLIENT_SECRET'),
        'audience' => env('WAYFAIR_AUDIENCE'),
    ],
    'bestbuy' => [
      'client_id' => env('BESTBUY_CLIENT_ID'),
      'client_secret' => env('BESTBUY_CLIENT_SECRET'),
    ], 
    'shipstation' => [
       'base_url' => env('SHIPSTATION_BASE_URL', 'https://api.shipstation.com/v2'),
       'api_key'  => env('SHIPSTATION_API_KEY'),
    ],
    'tiktok' => [
        'client_key'    => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect_uri'  => env('TIKTOK_REDIRECT_URI'),
        'app_key'       => env('TIKTOK_APP_KEY'),
        'app_secret'    => env('TIKTOK_APP_SECRET'),
    ],
    'aliexpress' => [
        'app_key'       => env('ALIEXPRESS_APP_KEY'),
        'app_secret'    => env('ALIEXPRESS_APP_SECRET'),
        'redirect_uri'  => env('ALIEXPRESS_REDIRECT_URI', 'https://ship.5coremanagement.com/aliexpress/callback'),
    ],

];
