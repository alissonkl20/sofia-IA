<?php

use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => config('app.name'),
    'status' => 'ok',
    'webhook' => url('/webhook/whatsapp'),
]));

Route::middleware('throttle:120,1')->group(function () {
    Route::get('/webhook/whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/webhook/whatsapp', [WhatsAppWebhookController::class, 'receive']);
});
