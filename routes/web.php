<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => view('instances'));

    Route::get('/p/{slug}/qr', fn (string $slug) => view('whatsapp', ['slug' => $slug]))
        ->where('slug', '[a-z0-9_-]+');

    Route::get('/p/{slug}/chat', fn (string $slug) => view('chat', ['slug' => $slug]))
        ->where('slug', '[a-z0-9_-]+');
});
