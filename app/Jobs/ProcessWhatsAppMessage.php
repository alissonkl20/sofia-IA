<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AIService;
use App\Services\AttendanceService;
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
        AIService $aiService,
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

            if (! $conversation || ! $attendanceService->shouldSofiaRespond($conversation)) {
                return;
            }

            $triggerMessage = Message::query()->find($this->messageId);

            if (! $triggerMessage || $triggerMessage->conversation_id !== $conversation->id) {
                return;
            }

            $isFirstInteraction = $conversation->messages()
                ->where('role', Message::ROLE_ASSISTANT)
                ->doesntExist();

            if ($isFirstInteraction) {
                $aiResponse = [
                    'message' => config('sofia.greeting'),
                    'intent' => null,
                    'stage' => 'greeting',
                    'confidence' => 1,
                    'needs_human' => false,
                    'conversation_complete' => false,
                    'data' => [],
                ];
            } else {
                $aiResponse = $aiService->generateResponse($conversation);
            }

            if ($aiResponse['needs_human']) {
                $aiResponse['message'] = config('sofia.transfer_message');
            }

            if ($aiResponse['conversation_complete']) {
                $aiResponse['message'] = config('sofia.completion_message');
            }

            $conversation = $aiService->applyResponseToConversation($conversation, $aiResponse);

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'role' => Message::ROLE_ASSISTANT,
                'content' => $aiResponse['message'],
                'metadata' => [
                    'intent' => $aiResponse['intent'],
                    'stage' => $aiResponse['stage'],
                    'confidence' => $aiResponse['confidence'],
                ],
            ]);

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
