<?php

use Illuminate\Support\Facades\Route;

// Home: lista os projetos (instâncias).
Route::get('/', function () {
    return view('instances');
});

// M4: QR de uma instância específica.
Route::get('/p/{slug}/qr', function (string $slug) {
    return view('whatsapp', ['slug' => $slug]);
})->where('slug', '[a-z0-9_-]+');

// M4: Chat de uma instância específica.
Route::get('/p/{slug}/chat', function (string $slug) {
    return view('chat', ['slug' => $slug]);
})->where('slug', '[a-z0-9_-]+');

// Compat temporária — vai sumir no M5.
Route::get('/chat', function () {
    return redirect('/p/piloto/chat');
});
