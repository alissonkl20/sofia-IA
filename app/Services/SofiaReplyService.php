<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;

class SofiaReplyService
{
    public function __construct(
        protected AIService $aiService,
    ) {}

    /**
     * @return array{
     *     message: string,
     *     intent: ?string,
     *     stage: ?string,
     *     confidence: float,
     *     needs_human: bool,
     *     conversation_complete: bool,
     *     data: array<string, mixed>
     * }
     */
    public function generateAndPersist(Conversation $conversation): array
    {
        $isFirstInteraction = $conversation->messages()
            ->where('role', Message::ROLE_ASSISTANT)
            ->doesntExist();

        if ($isFirstInteraction) {
            $aiResponse = [
                'message' => config('sofia.greeting'),
                'intent' => null,
                'stage' => 'greeting',
                'confidence' => 1.0,
                'needs_human' => false,
                'conversation_complete' => false,
                'data' => [],
            ];
        } else {
            $aiResponse = $this->aiService->generateResponse($conversation);
        }

        if ($aiResponse['needs_human']) {
            $aiResponse['message'] = config('sofia.transfer_message');
        }

        if ($aiResponse['conversation_complete']) {
            $aiResponse['message'] = config('sofia.completion_message');
        }

        $conversation = $this->aiService->applyResponseToConversation($conversation, $aiResponse);

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

        return $aiResponse;
    }
}
