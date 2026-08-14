<?php

namespace Tests\Unit;

use App\Services\AIService;
use Tests\TestCase;

class AIServiceTest extends TestCase
{
    public function test_parse_valid_json_response(): void
    {
        $service = app(AIService::class);

        $parsed = $service->parseStructuredResponse(json_encode([
            'message' => 'Olá!',
            'intent' => 'landing_page',
            'stage' => 'requirements',
            'confidence' => 0.9,
            'needs_human' => false,
            'conversation_complete' => false,
            'data' => ['service' => 'landing_page'],
        ]));

        $this->assertSame('Olá!', $parsed['message']);
        $this->assertSame('landing_page', $parsed['intent']);
    }

    public function test_invalid_json_uses_fallback(): void
    {
        $service = app(AIService::class);
        $parsed = $service->parseStructuredResponse('resposta sem json');

        $this->assertNotEmpty($parsed['message']);
        $this->assertFalse($parsed['needs_human']);
    }
}
