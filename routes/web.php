<?php

use Illuminate\Support\Facades\Route;

// Home: lista os projetos (instâncias).
Route::get('/', function () {
    return view('instances');
});

// QR de uma instância específica.
Route::get('/p/{slug}/qr', function (string $slug) {
    return view('whatsapp', ['slug' => $slug]);
})->where('slug', '[a-z0-9_-]+');

// Chat de uma instância específica.
Route::get('/p/{slug}/chat', function (string $slug) {
    return view('chat', ['slug' => $slug]);
})->where('slug', '[a-z0-9_-]+');
