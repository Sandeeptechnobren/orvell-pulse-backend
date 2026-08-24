<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI / LLM Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Supported providers: "openai", "gemini", "mock", "disabled"
    |
    */
    'provider' => env('AI_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Provider Credentials & Base URLs
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key'  => env('OPENAI_API_KEY', env('AI_API_KEY', '')),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model'    => env('OPENAI_MODEL', env('AI_MODEL', 'gpt-4o-mini')),
    ],

    'gemini' => [
        'api_key'  => env('GEMINI_API_KEY', env('AI_API_KEY', '')),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'model'    => env('GEMINI_MODEL', 'gemini-1.5-flash'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution Parameters
    |--------------------------------------------------------------------------
    */
    'timeout'     => (int) env('AI_TIMEOUT', 5), // Max 5 seconds for WhatsApp real-time responsiveness
    'max_tokens'  => (int) env('AI_MAX_TOKENS', 400),
    'temperature' => (float) env('AI_TEMPERATURE', 0.5),
];
