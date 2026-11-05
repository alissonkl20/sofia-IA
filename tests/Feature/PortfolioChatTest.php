<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortfolioChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'portfolio.api_token' => 'test-portfolio-token',
            'whatsapp.api_url' => 'http://whatsapp.test',
            'ai.url' => 'http://ollama.test',
        ]);

        Setting::set('ai_enabled', true);
        Setting::set('human_online', true);
    }

    public function test_invalid_token_returns_unauthorized(): void
    {
        $this->postJson('/api/portfolio/chat', [
            'session_id' => (string) Str::uuid(),
            'message' => 'Olá',
        ], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized();

        $this->getJson('/api/portfolio/status')
            ->assertUnauthorized();
    }

    public function test_empty_token_returns_unauthorized(): void
    {
        $this->postJson('/api/portfolio/chat', [
            'session_id' => (string) Str::uuid(),
            'message' => 'Olá',
        ])->assertUnauthorized();

        $this->getJson('/api/portfolio/status', [
            'Authorization' => 'Bearer ',
        ])->assertUnauthorized();
    }

    public function test_status_reports_llm_online(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []]),
        ]);

        $this->getJson('/api/portfolio/status', $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'online' => true,
                'llm' => true,
            ]);
    }

    public function test_status_reports_llm_offline(): void
    {
        Http::fake([
            '*/api/tags' => Http::response('unavailable', 503),
        ]);

        $this->getJson('/api/portfolio/status', $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'online' => true,
                'llm' => false,
            ]);
    }

    public function test_post_creates_customer_by_session_id_without_phone(): void
    {
        Http::fake([
            'http://ollama.test/api/tags' => Http::response(['models' => []]),
        ]);

        $sessionId = (string) Str::uuid();

        $this->postJson('/api/portfolio/chat', [
            'session_id' => $sessionId,
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'message' => 'Olá',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', config('sofia.greeting'))
            ->assertJsonPath('stage', 'greeting')
            ->assertJsonPath('complete', false)
            ->assertJsonPath('summary', null);

        $this->assertDatabaseHas('customers', [
            'portfolio_session_id' => $sessionId,
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'phone' => null,
        ]);

        $this->assertDatabaseHas('conversations', [
            'channel' => Conversation::CHANNEL_PORTFOLIO,
            'status' => Conversation::STATUS_AI,
        ]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/chat'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/send'));
        $this->assertSame(0, Customer::query()->whereNotNull('phone')->count());
    }

    public function test_first_message_returns_greeting_without_calling_ollama_chat(): void
    {
        Http::fake([
            'http://ollama.test/api/tags' => Http::response(['models' => []]),
            'http://ollama.test/api/chat' => Http::response(['message' => ['content' => '{}']]),
            'http://whatsapp.test/send' => Http::response(['success' => true]),
        ]);

        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldNotReceive('sendMessage');
        });

        Bus::fake();

        $this->postJson('/api/portfolio/chat', [
            'session_id' => (string) Str::uuid(),
            'message' => 'Quero um site',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', config('sofia.greeting'));

        $this->assertDatabaseHas('messages', [
            'role' => Message::ROLE_ASSISTANT,
            'content' => config('sofia.greeting'),
        ]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/chat'));
        Bus::assertNotDispatched(ProcessWhatsAppMessage::class);
    }

    public function test_returns_queued_when_llm_is_down_and_persists_lead_message(): void
    {
        Http::fake([
            'http://ollama.test/api/tags' => Http::response('down', 503),
            'http://whatsapp.test/send' => Http::response(['success' => true]),
        ]);

        $sessionId = (string) Str::uuid();

        $this->postJson('/api/portfolio/chat', [
            'session_id' => $sessionId,
            'email' => 'lead@example.com',
            'message' => 'Quero uma landing page',
        ], $this->authHeaders())
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('reply', config('portfolio.queued_message'));

        $this->assertDatabaseHas('customers', [
            'portfolio_session_id' => $sessionId,
            'email' => 'lead@example.com',
            'phone' => null,
        ]);

        $this->assertDatabaseHas('messages', [
            'role' => Message::ROLE_USER,
            'content' => 'Quero uma landing page',
        ]);

        $this->assertDatabaseMissing('messages', [
            'role' => Message::ROLE_ASSISTANT,
        ]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/send'));
    }

    public function test_returns_offline_when_ai_is_globally_disabled(): void
    {
        Setting::set('ai_enabled', false);

        $this->getJson('/api/portfolio/status', $this->authHeaders())
            ->assertStatus(503)
            ->assertExactJson(['status' => 'offline']);

        $this->postJson('/api/portfolio/chat', [
            'session_id' => (string) Str::uuid(),
            'message' => 'Olá',
        ], $this->authHeaders())
            ->assertStatus(503)
            ->assertExactJson(['status' => 'offline']);

        $this->assertDatabaseHas('messages', [
            'role' => Message::ROLE_USER,
            'content' => 'Olá',
        ]);
    }

    public function test_whatsapp_job_skips_portfolio_channel(): void
    {
        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldNotReceive('sendMessage');
        });

        $customer = Customer::query()->create([
            'portfolio_session_id' => (string) Str::uuid(),
            'email' => 'lead@example.com',
        ]);

        $conversation = Conversation::query()->create([
            'customer_id' => $customer->id,
            'channel' => Conversation::CHANNEL_PORTFOLIO,
            'status' => Conversation::STATUS_AI,
            'collected_data' => [],
        ]);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_USER,
            'content' => 'Olá',
        ]);

        ProcessWhatsAppMessage::dispatchSync($conversation->id, $message->id);

        $this->assertDatabaseMissing('messages', [
            'role' => Message::ROLE_ASSISTANT,
        ]);
    }

    public function test_validation_requires_session_id_and_message(): void
    {
        $this->postJson('/api/portfolio/chat', [
            'name' => 'Ana',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['session_id', 'message']);

        $this->getJson('/api/portfolio/chat', $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['session_id']);
    }

    public function test_poll_returns_latest_portfolio_messages(): void
    {
        Http::fake([
            'http://ollama.test/api/tags' => Http::response(['models' => []]),
        ]);

        $sessionId = (string) Str::uuid();

        $this->postJson('/api/portfolio/chat', [
            'session_id' => $sessionId,
            'message' => 'Olá do site',
        ], $this->authHeaders())
            ->assertOk();

        $this->getJson('/api/portfolio/chat?session_id='.$sessionId, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('stage', 'greeting')
            ->assertJsonPath('complete', false)
            ->assertJsonPath('messages.0.role', Message::ROLE_USER)
            ->assertJsonPath('messages.0.content', 'Olá do site')
            ->assertJsonPath('messages.1.role', Message::ROLE_ASSISTANT)
            ->assertJsonPath('messages.1.content', config('sofia.greeting'));
    }

    public function test_whatsapp_is_not_called_for_portfolio_follow_up(): void
    {
        Http::fake([
            'http://ollama.test/api/tags' => Http::response(['models' => []]),
            'http://ollama.test/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'message' => 'Qual o objetivo da landing page?',
                        'intent' => 'landing_page',
                        'stage' => 'requirements',
                        'confidence' => 0.9,
                        'needs_human' => false,
                        'conversation_complete' => false,
                        'data' => ['service' => 'landing_page'],
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ]),
            'http://whatsapp.test/send' => Http::response(['success' => true]),
        ]);

        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldNotReceive('sendMessage');
        });

        $sessionId = (string) Str::uuid();
        $headers = $this->authHeaders();

        $this->postJson('/api/portfolio/chat', [
            'session_id' => $sessionId,
            'message' => 'Olá',
        ], $headers)->assertOk();

        $this->postJson('/api/portfolio/chat', [
            'session_id' => $sessionId,
            'message' => 'Quero uma landing page',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', 'Qual o objetivo da landing page?')
            ->assertJsonPath('stage', 'requirements');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/send'));
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer test-portfolio-token',
        ];
    }
}
