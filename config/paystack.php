<?php

return [
    'secret_key'   => env('PAYSTACK_SECRET_KEY'),
    'public_key'   => env('PAYSTACK_PUBLIC_KEY'),
    'currency'     => env('PAYSTACK_CURRENCY', 'GHS'),
    'base_url'     => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    'callback_url' => env('PAYSTACK_CALLBACK_URL'),
];
