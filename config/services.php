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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend'   => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses'      => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack'    => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'python'   => [
        'path'          => env('PYTHON_PATH', 'python'),
        'processor_url' => env('PYTHON_PROCESSOR_URL'),
        'auto_index'    => env('ROUTE_SEARCH_AUTO_INDEX', true),
    ],

    'seo'      => [
        // Ownership verification codes from Google Search Console / Bing Webmaster Tools
        'google_verification' => env('GOOGLE_SITE_VERIFICATION'),
        'bing_verification'   => env('BING_SITE_VERIFICATION'),
    ],

    'webchat'  => [
        'url' => env('WEBCHAT_URL', 'https://chat.rutestrip.ai.id'),
    ],

    // Self-hosted ALTCHA proof-of-work captcha on /register.
    // Empty ALTCHA_HMAC_KEY falls back to a key derived from APP_KEY.
    'altcha'   => [
        'hmac_key'   => env('ALTCHA_HMAC_KEY'),
        'max_number' => (int) env('ALTCHA_MAX_NUMBER', 100000),
        'expires'    => (int) env('ALTCHA_EXPIRES', 600),
    ],

];
