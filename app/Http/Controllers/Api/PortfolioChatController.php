<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PortfolioChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioChatController extends Controller
{
    public function status(PortfolioChatService $portfolioChatService): JsonResponse
    {
        return $portfolioChatService->status();
    }

    public function store(Request $request, PortfolioChatService $portfolioChatService): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'uuid'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:8000'],
        ]);

        return $portfolioChatService->chat($validated);
    }

    public function show(Request $request, PortfolioChatService $portfolioChatService): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'uuid'],
        ]);

        return $portfolioChatService->history($validated['session_id']);
    }
}
