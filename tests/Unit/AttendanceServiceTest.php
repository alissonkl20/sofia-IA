<?php

namespace Tests\Unit;

use App\Models\BusinessHour;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sofia_responds_when_human_offline_and_conversation_in_ai_mode(): void
    {
        Setting::set('human_online', false);

        BusinessHour::query()->updateOrCreate(
            ['day_of_week' => now()->dayOfWeek],
            ['is_closed' => true, 'opens_at' => null, 'closes_at' => null],
        );

        $conversation = $this->makeConversation(Conversation::STATUS_AI);

        $service = app(AttendanceService::class);

        $this->assertTrue($service->shouldSofiaRespond($conversation));
    }

    public function test_sofia_does_not_respond_when_human_online(): void
    {
        Setting::set('human_online', true);

        $conversation = $this->makeConversation(Conversation::STATUS_AI);
        $service = app(AttendanceService::class);

        $this->assertFalse($service->shouldSofiaRespond($conversation));
    }

    public function test_sofia_does_not_respond_when_conversation_is_human(): void
    {
        Setting::set('human_online', false);

        BusinessHour::query()->updateOrCreate(
            ['day_of_week' => now()->dayOfWeek],
            ['is_closed' => true, 'opens_at' => null, 'closes_at' => null],
        );

        $conversation = $this->makeConversation(Conversation::STATUS_HUMAN);
        $service = app(AttendanceService::class);

        $this->assertFalse($service->shouldSofiaRespond($conversation));
    }

    protected function makeConversation(string $status): Conversation
    {
        $customer = Customer::query()->create([
            'phone' => '5511999999999',
            'whatsapp_id' => '5511999999999@c.us',
        ]);

        return Conversation::query()->create([
            'customer_id' => $customer->id,
            'status' => $status,
            'collected_data' => [],
        ]);
    }
}
