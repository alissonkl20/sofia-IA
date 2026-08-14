<?php

return [

    'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),

    'model' => env('OLLAMA_MODEL', 'qwen3:4b'),

    'timeout' => (int) env('OLLAMA_TIMEOUT', 120),

    'context_messages' => (int) env('SOFIA_CONTEXT_MESSAGES', 12),

    'enabled' => filter_var(env('SOFIA_AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'fallback_message' => 'Desculpe, tive uma dificuldade técnica momentânea. Pode repetir sua mensagem? Se preferir, posso encaminhar para o Alisson.',

    'service_types' => [
        'landing_page',
        'site_corporativo',
        'site_produto',
        'sistema_web',
        'api',
        'automacao',
        'integracao',
        'correcao_bug',
        'refatoracao',
        'otimizacao',
        'manutencao',
        'frontend',
        'backend',
        'fullstack',
        'outro',
    ],

    'stages' => [
        'greeting',
        'discovery',
        'requirements',
        'details',
        'closing',
    ],

];
