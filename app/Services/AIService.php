<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Support\SofiaPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AIService
{
    public function __construct(
        protected ConversationSummaryService $summaryService,
    ) {}

    public function generateResponse(Conversation $conversation): array
    {
        $startedAt = microtime(true);
        $messages = $this->buildMessages($conversation);

        try {
            $response = Http::timeout(config('ai.timeout', 120))
                ->post(rtrim(config('ai.url'), '/').'/api/chat', [
                    'model' => config('ai.model'),
                    'messages' => $messages,
                    'format' => 'json',
                    'stream' => false,
                    'options' => [
                        'temperature' => 0.4,
                    ],
                ]);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            if (! $response->successful()) {
                Log::error('Ollama request failed', [
                    'conversation_id' => $conversation->id,
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 500),
                    'duration_ms' => $durationMs,
                ]);

                return $this->fallbackResponse();
            }

            $raw = trim((string) $response->json('message.content', ''));
            $parsed = $this->parseStructuredResponse($raw);

            Log::info('Ollama response received', [
                'conversation_id' => $conversation->id,
                'model' => config('ai.model'),
                'duration_ms' => $durationMs,
                'eval_count' => $response->json('eval_count'),
            ]);

            return $parsed;
        } catch (\Throwable $exception) {
            Log::error('Ollama exception', [
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->fallbackResponse();
        }
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    protected function buildMessages(Conversation $conversation): array
    {
        $messages = [
            ['role' => 'system', 'content' => SofiaPrompt::system()],
        ];

        $context = $this->buildContextBlock($conversation);

        if ($context !== '') {
            $messages[] = ['role' => 'system', 'content' => $context];
        }

        $recentMessages = $conversation->messages()
            ->whereIn('role', [Message::ROLE_USER, Message::ROLE_ASSISTANT])
            ->latest()
            ->limit(config('ai.context_messages', 12))
            ->get()
            ->sortBy('id');

        foreach ($recentMessages as $message) {
            $messages[] = [
                'role' => $message->role === Message::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => $message->content,
            ];
        }

        return $messages;
    }

    protected function buildContextBlock(Conversation $conversation): string
    {
        $parts = [];

        if ($conversation->summary) {
            $parts[] = "Resumo da conversa:\n{$conversation->summary}";
        }

        if (! empty($conversation->collected_data)) {
            $parts[] = 'Dados já coletados: '.json_encode(
                $conversation->collected_data,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            );
        }

        if ($conversation->intent) {
            $parts[] = "Intent atual: {$conversation->intent}";
        }

        if ($conversation->stage) {
            $parts[] = "Estágio atual: {$conversation->stage}";
        }

        return implode("\n\n", $parts);
    }

    public function parseStructuredResponse(string $raw): array
    {
        $json = $this->extractJson($raw);

        if ($json === null) {
            Log::warning('Invalid AI JSON response', ['raw' => Str::limit($raw, 500)]);

            return $this->fallbackResponse($raw);
        }

        $message = trim((string) ($json['message'] ?? ''));

        if ($message === '') {
            return $this->fallbackResponse();
        }

        return [
            'message' => $message,
            'intent' => $this->normalizeIntent($json['intent'] ?? null),
            'stage' => $this->normalizeStage($json['stage'] ?? null),
            'confidence' => (float) ($json['confidence'] ?? 0),
            'needs_human' => (bool) ($json['needs_human'] ?? false),
            'conversation_complete' => (bool) ($json['conversation_complete'] ?? false),
            'data' => is_array($json['data'] ?? null) ? $json['data'] : [],
        ];
    }

    protected function extractJson(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $raw, $matches)) {
            $decoded = json_decode($matches[0], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    protected function normalizeIntent(?string $intent): ?string
    {
        if (! $intent) {
            return null;
        }

        $intent = Str::snake($intent);

        return in_array($intent, config('ai.service_types', []), true) ? $intent : 'outro';
    }

    protected function normalizeStage(?string $stage): ?string
    {
        if (! $stage) {
            return null;
        }

        $stage = Str::snake($stage);

        return in_array($stage, config('ai.stages', []), true) ? $stage : 'discovery';
    }

    protected function fallbackResponse(?string $rawMessage = null): array
    {
        $message = config('ai.fallback_message');

        if ($rawMessage && ! str_starts_with(trim($rawMessage), '{')) {
            $message = trim($rawMessage);
        }

        return [
            'message' => $message,
            'intent' => null,
            'stage' => 'discovery',
            'confidence' => 0,
            'needs_human' => false,
            'conversation_complete' => false,
            'data' => [],
        ];
    }

    public function applyResponseToConversation(Conversation $conversation, array $response): Conversation
    {
        $collected = array_merge($conversation->collected_data ?? [], $response['data'] ?? []);

        $updates = [
            'collected_data' => $collected,
        ];

        if ($response['intent']) {
            $updates['intent'] = $response['intent'];
        }

        if ($response['stage']) {
            $updates['stage'] = $response['stage'];
        }

        if (! empty($response['data']['complexity'])) {
            $updates['complexity'] = (string) $response['data']['complexity'];
        }

        if ($response['needs_human']) {
            $updates['status'] = Conversation::STATUS_HUMAN;
            $updates['transferred_at'] = now();
        }

        if ($response['conversation_complete']) {
            $updates['stage'] = 'closing';
            $updates['summary'] = $this->summaryService->generate($conversation->fresh() ?? $conversation);
        }

        $conversation->update($updates);

        return $conversation->fresh();
    }
}
