<?php

namespace App\Services;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationService
{
    public function __construct(
        protected AttendanceService $attendanceService,
    ) {}

    /**
     * @param  array{phone: string, whatsapp_id: string, name: ?string, content: string, external_message_id: ?string}  $incoming
     */
    public function handleIncomingMessage(array $incoming): array
    {
        if ($incoming['content'] === '') {
            return ['status' => 'ignored', 'reason' => 'empty_message'];
        }

        if ($incoming['external_message_id']) {
            $exists = Message::query()
                ->where('external_message_id', $incoming['external_message_id'])
                ->exists();

            if ($exists) {
                Log::info('Duplicate webhook ignored', [
                    'external_message_id' => $incoming['external_message_id'],
                ]);

                return ['status' => 'ignored', 'reason' => 'duplicate'];
            }
        }

        return DB::transaction(function () use ($incoming) {
            $customer = Customer::query()->firstOrCreate(
                ['phone' => $incoming['phone']],
                [
                    'name' => $incoming['name'],
                    'whatsapp_id' => $incoming['whatsapp_id'],
                ],
            );

            if ($incoming['name'] && ! $customer->name) {
                $customer->update(['name' => $incoming['name']]);
            }

            if ($incoming['whatsapp_id'] && $customer->whatsapp_id !== $incoming['whatsapp_id']) {
                $customer->update(['whatsapp_id' => $incoming['whatsapp_id']]);
            }

            $conversation = $customer->activeConversation(Conversation::CHANNEL_WHATSAPP);

            if (! $conversation) {
                $conversation = Conversation::query()->create([
                    'customer_id' => $customer->id,
                    'channel' => Conversation::CHANNEL_WHATSAPP,
                    'status' => $this->attendanceService->initialConversationStatus(Conversation::CHANNEL_WHATSAPP),
                    'collected_data' => [],
                ]);
            }

            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'external_message_id' => $incoming['external_message_id'],
                'role' => Message::ROLE_USER,
                'content' => $incoming['content'],
            ]);

            Log::info('Message received', [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'message_id' => $message->id,
            ]);

            if ($this->attendanceService->shouldSofiaRespond($conversation)) {
                ProcessWhatsAppMessage::dispatch($conversation->id, $message->id);
            }

            return [
                'status' => 'accepted',
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'sofia_queued' => $this->attendanceService->shouldSofiaRespond($conversation),
            ];
        });
    }
}
