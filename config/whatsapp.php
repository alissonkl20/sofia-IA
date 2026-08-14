<?php

return [

    'api_url' => rtrim((string) env('WHATSAPP_API_URL', ''), '/'),

    'api_token' => env('WHATSAPP_API_TOKEN'),

    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),

    'send_endpoint' => '/send',

];
