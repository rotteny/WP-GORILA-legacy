<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('whatsapp');
});

Route::get('/chat', function () {
    return view('chat');
});
