<?php

use App\Http\Controllers\Api\PortfolioChatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['portfolio.token', 'throttle:portfolio'])->prefix('portfolio')->group(function () {
    Route::get('/status', [PortfolioChatController::class, 'status']);
    Route::post('/chat', [PortfolioChatController::class, 'store']);
    Route::get('/chat', [PortfolioChatController::class, 'show']);
});
