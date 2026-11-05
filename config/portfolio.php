<?php

return [

    'api_token' => env('PORTFOLIO_API_TOKEN'),

    'throttle_per_minute' => (int) env('PORTFOLIO_THROTTLE_PER_MINUTE', 60),

    'queued_message' => env(
        'PORTFOLIO_QUEUED_MESSAGE',
        'Recebi sua mensagem e já registrei seu contato. Estou temporariamente indisponível; o Alisson retorna em breve.',
    ),

];
