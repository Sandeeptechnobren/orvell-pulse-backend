<?php

return [
    'base_url' => env('CHATTERLY_BASE_URL'),
    'admin_token' => env('CHATTERLY_ADMIN_TOKEN'),
    'inbound_url' => env('CHATTERLY_INBOUND_URL'),
    'timeout' => env('CHATTERLY_TIMEOUT', 15),
    'connect_timeout' => env('CHATTERLY_CONNECT_TIMEOUT', 5),
];