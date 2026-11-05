<?php

namespace Tests\Feature;

use App\Models\BusinessHour;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BakeryLandingPageConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.verify_token' => 'test-token',
            'whatsapp.api_url' => 'http://whatsapp.test',
        ]);

        Setting::set('ai_enabled', true);
        Setting::set('human_online', false);

        BusinessHour::query()->updateOrCreate(
            ['day_of_week' => now()->dayOfWeek],
            ['is_closed' => true, 'opens_at' => null, 'closes_at' => null],
        );
    }

    public function test_customer_requests_bakery_landing_page_and_sofia_responds(): void
    {
        Http::fake([
            '*/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'message' => 'Que legal! Uma landing page para padaria é uma ótima ideia. Qual o principal objetivo da página?',
                        'intent' => 'landing_page',
                        'stage' => 'requirements',
                        'confidence' => 0.92,
                        'needs_human' => false,
                        'conversation_complete' => false,
                        'data' => [
                            'service' => 'landing_page',
                            'objective' => 'divulgar padaria',
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ]),
            'http://whatsapp.test/send' => Http::response(['success' => true]),
        ]);

        $headers = ['X-Webhook-Token' => 'test-token'];
        $customer = [
            'from' => '5511987654321@c.us',
            'chatId' => '5511987654321@c.us',
            'name' => 'Maria da Padaria',
        ];

        $this->postJson('/webhook/whatsapp', [
            ...$customer,
            'body' => 'Olá, boa noite!',
            'messageId' => 'bakery-msg-001',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('sofia_queued', true);

        $conversationId = Conversation::query()->value('id');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'role' => Message::ROLE_USER,
            'content' => 'Olá, boa noite!',
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'role' => Message::ROLE_ASSISTANT,
            'content' => config('sofia.greeting'),
        ]);

        $this->postJson('/webhook/whatsapp', [
            ...$customer,
            'body' => 'Quero uma oferta de landing page para minha padaria',
            'messageId' => 'bakery-msg-002',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('sofia_queued', true);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'role' => Message::ROLE_USER,
            'content' => 'Quero uma oferta de landing page para minha padaria',
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'role' => Message::ROLE_ASSISTANT,
            'content' => 'Que legal! Uma landing page para padaria é uma ótima ideia. Qual o principal objetivo da página?',
        ]);

        $conversation = Conversation::query()->findOrFail($conversationId);

        $this->assertSame('landing_page', $conversation->intent);
        $this->assertSame('requirements', $conversation->stage);
        $this->assertSame('landing_page', $conversation->collected_data['service'] ?? null);

        Http::assertSentCount(3);
        Http::assertSent(function ($request) {
            if ($request->url() !== 'http://whatsapp.test/send') {
                return false;
            }

            return $request['to'] === '5511987654321@c.us'
                && str_contains($request['message'], 'landing page para padaria');
        });
    }
}
