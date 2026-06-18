<?php

use App\Http\Controllers\Api\V1\InstanceController as V1InstanceController;
use App\Http\Controllers\Api\V1\MessageController as V1MessageController;
use App\Http\Controllers\InstanceController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('whatsapp')->group(function () {
    Route::post('/webhook', [WhatsAppController::class, 'webhook']);

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
    });
});

Route::prefix('v1')->middleware('api.key')->group(function () {
    Route::get('/instances', [V1InstanceController::class, 'index']);
    Route::get('/instances/{instance}', [V1InstanceController::class, 'show']);

    Route::prefix('instances/{instance}')->group(function () {
        Route::get('/messages', [V1MessageController::class, 'index']);
        Route::post('/messages', [V1MessageController::class, 'store']);
        Route::get('/messages/{message}', [V1MessageController::class, 'show']);
    });
});
