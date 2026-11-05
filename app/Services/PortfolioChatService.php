<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PortfolioChatService
{
    public function __construct(
        protected AttendanceService $attendanceService,
        protected AIService $aiService,
        protected SofiaReplyService $sofiaReplyService,
    ) {}

    public function status(): JsonResponse
    {
        if (! $this->attendanceService->isAiGloballyEnabled()) {
            return response()->json(['status' => 'offline'], 503);
        }

        return response()->json([
            'online' => true,
            'llm' => $this->aiService->isReachable(),
        ]);
    }

    /**
     * @param  array{session_id: string, name?: ?string, email?: ?string, message: string}  $payload
     */
    public function chat(array $payload): JsonResponse
    {
        $conversation = DB::transaction(function () use ($payload) {
            $customer = $this->findOrCreateCustomer($payload);

            $conversation = $customer->activeConversation(Conversation::CHANNEL_PORTFOLIO);

            if (! $conversation) {
                $conversation = Conversation::query()->create([
                    'customer_id' => $customer->id,
                    'channel' => Conversation::CHANNEL_PORTFOLIO,
                    'status' => $this->attendanceService->initialConversationStatus(
                        Conversation::CHANNEL_PORTFOLIO,
                    ),
                    'collected_data' => [],
                ]);
            }

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'role' => Message::ROLE_USER,
                'content' => $payload['message'],
            ]);

            return $conversation->fresh(['customer']);
        });

        if (! $this->attendanceService->isAiGloballyEnabled()) {
            return response()->json(['status' => 'offline'], 503);
        }

        if (! $this->attendanceService->shouldSofiaRespond($conversation)) {
            return response()->json([
                'status' => 'ok',
                'reply' => config('sofia.transfer_message'),
                'stage' => $conversation->stage,
                'complete' => $this->isComplete($conversation),
                'summary' => $conversation->summary,
            ]);
        }

        if (! $this->aiService->isReachable()) {
            return response()->json([
                'status' => 'queued',
                'reply' => config('portfolio.queued_message'),
            ], 202);
        }

        $lock = Cache::lock("conversation:{$conversation->id}:processing", 180);

        if (! $lock->get()) {
            return response()->json([
                'status' => 'queued',
                'reply' => config('portfolio.queued_message'),
            ], 202);
        }

        try {
            $aiResponse = $this->sofiaReplyService->generateAndPersist($conversation);
            $conversation->refresh();

            return response()->json([
                'status' => 'ok',
                'reply' => $aiResponse['message'],
                'stage' => $aiResponse['stage'] ?? $conversation->stage,
                'complete' => (bool) $aiResponse['conversation_complete'],
                'summary' => $conversation->summary,
            ]);
        } finally {
            $lock->release();
        }
    }

    public function history(string $sessionId): JsonResponse
    {
        $customer = Customer::query()
            ->where('portfolio_session_id', $sessionId)
            ->first();

        $conversation = $customer?->activeConversation(Conversation::CHANNEL_PORTFOLIO)
            ?? $customer?->conversations()
                ->where('channel', Conversation::CHANNEL_PORTFOLIO)
                ->latest()
                ->first();

        if (! $conversation) {
            return response()->json([
                'status' => 'ok',
                'stage' => null,
                'complete' => false,
                'summary' => null,
                'messages' => [],
            ]);
        }

        $messages = $conversation->messages()
            ->whereIn('role', [Message::ROLE_USER, Message::ROLE_ASSISTANT])
            ->latest('id')
            ->limit(30)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (Message $message) => [
                'role' => $message->role,
                'content' => $message->content,
            ]);

        return response()->json([
            'status' => 'ok',
            'stage' => $conversation->stage,
            'complete' => $this->isComplete($conversation),
            'summary' => $conversation->summary,
            'messages' => $messages,
        ]);
    }

    /**
     * @param  array{session_id: string, name?: ?string, email?: ?string, message: string}  $payload
     */
    protected function findOrCreateCustomer(array $payload): Customer
    {
        $customer = Customer::query()->firstOrCreate(
            ['portfolio_session_id' => $payload['session_id']],
            [
                'name' => $payload['name'] ?? null,
                'email' => $payload['email'] ?? null,
            ],
        );

        $updates = [];

        if (! empty($payload['name']) && $customer->name !== $payload['name']) {
            $updates['name'] = $payload['name'];
        }

        if (! empty($payload['email']) && $customer->email !== $payload['email']) {
            $updates['email'] = $payload['email'];
        }

        if ($updates !== []) {
            $customer->update($updates);
        }

        return $customer;
    }

    protected function isComplete(Conversation $conversation): bool
    {
        return $conversation->summary !== null || $conversation->stage === 'closing';
    }
}
