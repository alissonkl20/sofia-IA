<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AttendanceService;
use App\Services\SofiaReplyService;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public int $conversationId,
        public int $messageId,
    ) {}

    public function handle(
        AttendanceService $attendanceService,
        SofiaReplyService $sofiaReplyService,
        WhatsAppService $whatsAppService,
    ): void {
        $lock = Cache::lock("conversation:{$this->conversationId}:processing", 180);

        if (! $lock->get()) {
            Log::info('Conversation already processing', ['conversation_id' => $this->conversationId]);

            return;
        }

        try {
            $conversation = Conversation::query()
                ->with(['customer', 'messages'])
                ->find($this->conversationId);

            if (! $conversation || $conversation->channel !== Conversation::CHANNEL_WHATSAPP) {
                return;
            }

            if (! $attendanceService->shouldSofiaRespond($conversation)) {
                return;
            }

            $triggerMessage = Message::query()->find($this->messageId);

            if (! $triggerMessage || $triggerMessage->conversation_id !== $conversation->id) {
                return;
            }

            $aiResponse = $sofiaReplyService->generateAndPersist($conversation);
            $conversation->refresh()->loadMissing('customer');

            $recipient = $conversation->customer->whatsapp_id
                ?: $conversation->customer->phone;

            $whatsAppService->sendMessage($recipient, $aiResponse['message']);

            if ($aiResponse['needs_human']) {
                Log::info('Conversation transferred to human', [
                    'conversation_id' => $conversation->id,
                ]);
            }
        } finally {
            $lock->release();
        }
    }
}
