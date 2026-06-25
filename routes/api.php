<?php

use App\Http\Controllers\ApiKeyController;
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

        Route::post('/send-message', [WhatsAppController::class, 'sendMessageFallback']);
        Route::post('/send-media',   [WhatsAppController::class, 'sendMediaFallback']);

        // CRUD de chaves de API (gerenciado pelo painel autenticado por sessão)
        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store']);
        Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);

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

// ───────────────────────────────────────────────────────────────────────────
// API pública versionada — autenticada por chave de API (Authorization: Bearer wpg_...).
// Use estes endpoints em integrações externas (acca, n8n, etc).
// Permissões: send + read (não permite deletar instância nem configurar webhooks).
// ───────────────────────────────────────────────────────────────────────────
Route::prefix('v1/whatsapp')->middleware('api-key')->group(function () {
    Route::prefix('instances/{instance}')->group(function () {
        Route::get('/status', [WhatsAppController::class, 'getStatus']);
        Route::post('/send-message', [WhatsAppController::class, 'sendMessage']);
        Route::post('/send-media', [WhatsAppController::class, 'sendMedia']);
        Route::get('/chats', [WhatsAppController::class, 'listChats']);
        Route::get('/chats/{jid}/messages', [WhatsAppController::class, 'chatMessages'])
            ->where('jid', '.+');
        Route::get('/media/{messageId}', [WhatsAppController::class, 'media']);
    });
});
