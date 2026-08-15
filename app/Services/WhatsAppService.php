<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppService
{
    public function sendMessage(string $to, string $message): bool
    {
        $apiUrl = config('whatsapp.api_url');

        if ($apiUrl === '') {
            Log::warning('WhatsApp API URL not configured', ['to' => $to]);

            return false;
        }

        $request = Http::timeout(30)
            ->acceptJson()
            ->asJson();

        $token = config('whatsapp.api_token');

        if ($token) {
            $request = $request->withToken($token);
        }

        $endpoint = $apiUrl.config('whatsapp.send_endpoint', '/send');

        try {
            $response = $request->post($endpoint, [
                'to' => $to,
                'message' => $message,
            ]);
        } catch (\Throwable $exception) {
            Log::error('WhatsApp send exception', [
                'to' => $to,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::error('WhatsApp send failed', [
                'to' => $to,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 500),
            ]);

            return false;
        }

        Log::info('WhatsApp message sent', ['to' => $to]);

        return true;
    }

    /**
     * @return array{phone: string, whatsapp_id: string, name: ?string, content: string, external_message_id: ?string}
     */
    public function parseIncomingPayload(array $payload): array
    {
        if (isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
            return $this->parseMetaPayload($payload);
        }

        return $this->parseBridgePayload($payload);
    }

    protected function parseBridgePayload(array $payload): array
    {
        $whatsappId = (string) ($payload['chatId'] ?? $payload['from'] ?? '');
        $phone = $this->normalizePhone($whatsappId);

        return [
            'phone' => $phone,
            'whatsapp_id' => $whatsappId,
            'name' => $payload['name'] ?? $payload['pushName'] ?? null,
            'content' => trim((string) ($payload['body'] ?? $payload['message'] ?? '')),
            'external_message_id' => $payload['messageId'] ?? $payload['id'] ?? null,
        ];
    }

    protected function parseMetaPayload(array $payload): array
    {
        $message = $payload['entry'][0]['changes'][0]['value']['messages'][0];
        $contact = $payload['entry'][0]['changes'][0]['value']['contacts'][0] ?? [];
        $from = (string) ($message['from'] ?? '');

        return [
            'phone' => $this->normalizePhone($from),
            'whatsapp_id' => $from,
            'name' => $contact['profile']['name'] ?? null,
            'content' => trim((string) ($message['text']['body'] ?? '')),
            'external_message_id' => $message['id'] ?? null,
        ];
    }

    protected function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits;
    }

    public function verifyWebhookToken(?string $token): bool
    {
        $expected = config('whatsapp.verify_token');

        if (! $expected) {
            return true;
        }

        if (($token === null || $token === '') && app()->environment('local')) {
            return true;
        }

        return hash_equals((string) $expected, (string) $token);
    }
}
