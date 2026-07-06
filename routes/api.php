<?php

use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\InstanceController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProjectController;
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

        // Projetos: agrupam telefones (instâncias) com failover.
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::patch('/projects/{project}', [ProjectController::class, 'update']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
        // Cria um telefone novo já dentro do projeto (cria sessão no whatsapp-service).
        Route::post('/projects/{project}/instances', [ProjectController::class, 'createInstance']);
        Route::delete('/projects/{project}/instances/{instance}', [ProjectController::class, 'deleteInstance']);
        Route::post('/projects/{project}/instances/{instance}/promote', [ProjectController::class, 'promote']);

        Route::prefix('instances/{instance}')->group(function () {
            Route::get('/status', [WhatsAppController::class, 'getStatus']);
            Route::post('/reset', [WhatsAppController::class, 'reset']);
            Route::post('/pair-code', [WhatsAppController::class, 'pairCode']);
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
// `throttle:api-key` roda DEPOIS de `api-key`, então já tem a chave resolvida pra
// limitar por chave (60/min). Limiter definido em AppServiceProvider::boot().
Route::prefix('v1/whatsapp')->middleware(['api-key', 'throttle:api-key'])->group(function () {
    // Envio ASSÍNCRONO (o único jeito de enviar pela API pública): enfileira e retorna
    // 202 { id, status: 'queued' }. O worker processa e atualiza o status; consumidores
    // acompanham o ciclo (message.sent/delivered/read) pelos webhooks de saída.
    //
    // "Pela chave" (sem instância/projeto na URL): o escopo da própria chave decide o
    // destino — projeto (telefone ativo + failover) ou instância.
    Route::post('/messages/text', [MessageController::class, 'sendTextByKey']);
    Route::post('/messages/media', [MessageController::class, 'sendMediaByKey']);

    Route::prefix('instances/{instance}')->group(function () {
        Route::get('/status', [WhatsAppController::class, 'getStatus']);
        Route::post('/messages/text', [MessageController::class, 'sendTextInstance']);
        Route::post('/messages/media', [MessageController::class, 'sendMediaInstance']);
        Route::get('/chats', [WhatsAppController::class, 'listChats']);
        Route::get('/chats/{jid}/messages', [WhatsAppController::class, 'chatMessages'])
            ->where('jid', '.+');
        Route::get('/media/{messageId}', [WhatsAppController::class, 'media']);
    });

    // Envio "pelo projeto": resolve o telefone ativo e envia por ele. O failover
    // (tik1 -> tik2) é transparente — a chave e a URL do consumidor não mudam.
    Route::prefix('projects/{project}')->group(function () {
        Route::post('/messages/text', [MessageController::class, 'sendTextProject']);
        Route::post('/messages/media', [MessageController::class, 'sendMediaProject']);
    });
});
