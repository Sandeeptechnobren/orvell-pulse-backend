<?php

return [
    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
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
    'customer_whapi' => [
        'token' => env('CUSTOMER_WHAPI_TOKEN'),
    ],
    'whapi' => [
        'base_url' => env(
            'WHAPI_BASE_URL',
            'https://gate.whapi.cloud'
        ),
        // 'token' => env('CUSTOMER_WHAPI_TOKEN'),
    ],

];
