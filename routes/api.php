<?php

use App\Http\Controllers\InstanceController;
use App\Http\Controllers\WebhookConfigController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('whatsapp')->group(function () {
    // Público — chamado pelo Node.js interno
    Route::post('/webhook', [WhatsAppController::class, 'webhook']);

    Route::middleware('auth:web')->group(function () {
        Route::get('/instances', [InstanceController::class, 'index']);
        Route::post('/instances', [InstanceController::class, 'store']);
        Route::delete('/instances/{instance}', [InstanceController::class, 'destroy']);

        Route::prefix('instances/{instance}')->group(function () {
            Route::get('/status', [WhatsAppController::class, 'getStatus']);
            Route::post('/reset', [WhatsAppController::class, 'reset']);
            Route::post('/send-message', [WhatsAppController::class, 'sendMessage']);
            Route::post('/send-media', [WhatsAppController::class, 'sendMedia']);
            Route::get('/chats', [WhatsAppController::class, 'listChats']);
            Route::get('/chats/{jid}/messages', [WhatsAppController::class, 'chatMessages'])
                ->where('jid', '.+');
            Route::get('/media/{messageId}', [WhatsAppController::class, 'media']);
            Route::get('/webhooks', [WebhookConfigController::class, 'index']);
            Route::put('/webhooks', [WebhookConfigController::class, 'upsert']);
            Route::delete('/webhooks/{event}', [WebhookConfigController::class, 'destroy']);
        });
    });
});
