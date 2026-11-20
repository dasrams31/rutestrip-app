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

    // Single sign-on with the web chat (docs/SSO.md).
    // Empty RUTESTRIP_SSO_SECRET falls back to a key derived from APP_KEY (dev/tests only:
    // the chat needs the same secret). Empty SSO_COOKIE_DOMAIN = host-only cookies.
    'sso'      => [
        'secret'          => env('RUTESTRIP_SSO_SECRET'),
        'internal_secret' => env('RUTESTRIP_INTERNAL_SECRET'),
        'cookie_domain'   => env('SSO_COOKIE_DOMAIN') ?: null,
        'cookie_secure'   => (bool) env('SSO_COOKIE_SECURE', false),
    ],

    // Gemini (Google AI) for the "Asisten RuteStrip" widget (AssistantController).
    // Model order like webchat/app/api/chat/route.js: GEMINI_MODELS (comma list) wins,
    // otherwise GEMINI_MODEL followed by GEMINI_FALLBACK_MODELS. Tried in order;
    // the next model is used on 429/5xx/timeout.
    'gemini'   => [
        'key'                    => env('GEMINI_API_KEY'),
        'models'                 => array_values(array_unique(array_filter(array_map('trim', explode(',',
            env('GEMINI_MODELS') ?: implode(',', [
                (string) env('GEMINI_MODEL', ''),
                (string) (env('GEMINI_FALLBACK_MODELS') ?: 'gemini-3.5-flash,gemini-3.1-flash-lite'),
            ])
        ))))),
        'first_token_timeout_ms' => (int) (env('GEMINI_FIRST_TOKEN_TIMEOUT_MS') ?: 12000),
        'total_timeout_ms'       => (int) (env('GEMINI_TOTAL_TIMEOUT_MS') ?: 30000),
    ],

];
