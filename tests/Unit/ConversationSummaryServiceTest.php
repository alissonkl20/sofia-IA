<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Models\Customer;
use App\Services\ConversationSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_commercial_summary(): void
    {
        $customer = Customer::query()->create([
            'name' => 'João',
            'phone' => '5511999999999',
        ]);

        $conversation = Conversation::query()->create([
            'customer_id' => $customer->id,
            'status' => Conversation::STATUS_AI,
            'intent' => 'landing_page',
            'complexity' => 'Média',
            'collected_data' => [
                'service' => 'landing_page',
                'objective' => 'Captar clientes',
                'features' => ['CTA WhatsApp'],
                'deadline' => '30 dias',
                'integrations' => ['WhatsApp'],
            ],
        ]);

        $summary = app(ConversationSummaryService::class)->generate($conversation);

        $this->assertStringContainsString('João', $summary);
        $this->assertStringContainsString('Landing page', $summary);
        $this->assertStringContainsString('A definir pelo Alisson', $summary);
        $this->assertStringContainsString('5511999999999', $summary);
    }

    public function test_uses_placeholder_when_phone_and_email_are_missing(): void
    {
        $customer = Customer::query()->create([
            'name' => 'Maria',
            'phone' => null,
            'email' => null,
            'portfolio_session_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $conversation = Conversation::query()->create([
            'customer_id' => $customer->id,
            'channel' => Conversation::CHANNEL_PORTFOLIO,
            'status' => Conversation::STATUS_AI,
            'intent' => 'landing_page',
            'collected_data' => [],
        ]);

        $summary = app(ConversationSummaryService::class)->generate($conversation);

        $this->assertStringContainsString('Telefone: Não informado', $summary);
        $this->assertStringContainsString('E-mail: Não informado', $summary);
    }
}
