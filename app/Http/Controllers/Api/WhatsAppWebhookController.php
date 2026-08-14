<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConversationService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppService $whatsAppService): Response|JsonResponse
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        if ($mode === 'subscribe' && $whatsAppService->verifyWebhookToken($token)) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request, WhatsAppService $whatsAppService, ConversationService $conversationService): JsonResponse
    {
        $token = $request->header('X-Webhook-Token') ?? $request->bearerToken();

        if (! $whatsAppService->verifyWebhookToken($token)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $incoming = $whatsAppService->parseIncomingPayload($request->all());
        $result = $conversationService->handleIncomingMessage($incoming);

        return response()->json($result);
    }
}
