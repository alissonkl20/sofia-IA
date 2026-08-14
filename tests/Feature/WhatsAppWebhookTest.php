<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.verify_token' => 'test-token']);
    }

    public function test_webhook_rejects_invalid_token(): void
    {
        $response = $this->postJson('/webhook/whatsapp', [
            'from' => '5511999999999@c.us',
            'chatId' => '5511999999999@c.us',
            'body' => 'Olá',
        ], [
            'X-Webhook-Token' => 'wrong',
        ]);

        $response->assertUnauthorized();
    }

    public function test_webhook_accepts_message_and_queues_job_when_ai_should_respond(): void
    {
        Bus::fake();
        Setting::set('human_online', false);

        $response = $this->postJson('/webhook/whatsapp', [
            'from' => '5511999999999@c.us',
            'chatId' => '5511999999999@c.us',
            'body' => 'Quero um site',
            'messageId' => 'msg-001',
        ], [
            'X-Webhook-Token' => 'test-token',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('sofia_queued', true);

        $this->assertDatabaseHas('customers', ['phone' => '5511999999999']);
        $this->assertDatabaseHas('messages', [
            'external_message_id' => 'msg-001',
            'role' => Message::ROLE_USER,
            'content' => 'Quero um site',
        ]);

        Bus::assertDispatched(ProcessWhatsAppMessage::class);
    }

    public function test_duplicate_message_is_ignored(): void
    {
        Bus::fake();

        $payload = [
            'from' => '5511888888888@c.us',
            'chatId' => '5511888888888@c.us',
            'body' => 'Olá',
            'messageId' => 'dup-001',
        ];

        $this->postJson('/webhook/whatsapp', $payload, ['X-Webhook-Token' => 'test-token'])
            ->assertOk();

        $this->postJson('/webhook/whatsapp', $payload, ['X-Webhook-Token' => 'test-token'])
            ->assertOk()
            ->assertJsonPath('status', 'ignored')
            ->assertJsonPath('reason', 'duplicate');

        $this->assertDatabaseCount('messages', 1);
        Bus::assertDispatchedTimes(ProcessWhatsAppMessage::class, 1);
    }

    public function test_human_attendance_prevents_sofia_queue(): void
    {
        Bus::fake();
        Setting::set('human_online', true);

        $this->postJson('/webhook/whatsapp', [
            'from' => '5511777777777@c.us',
            'chatId' => '5511777777777@c.us',
            'body' => 'Olá',
            'messageId' => 'human-001',
        ], ['X-Webhook-Token' => 'test-token'])
            ->assertOk()
            ->assertJsonPath('sofia_queued', false);

        Bus::assertNothingDispatched();
    }
}
