<?php

use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('whatsapp')->group(function () {
    Route::post('/webhook', [WhatsAppController::class, 'webhook']);
    Route::get('/status', [WhatsAppController::class, 'getStatus']);
    Route::post('/send-message', [WhatsAppController::class, 'sendMessage']);
    Route::post('/send-media', [WhatsAppController::class, 'sendMedia']);
    Route::post('/reset', [WhatsAppController::class, 'reset']);
    Route::get('/chats', [WhatsAppController::class, 'listChats']);
    Route::get('/chats/{jid}/messages', [WhatsAppController::class, 'chatMessages'])
        ->where('jid', '.+');
    Route::get('/media/{messageId}', [WhatsAppController::class, 'media']);
});
