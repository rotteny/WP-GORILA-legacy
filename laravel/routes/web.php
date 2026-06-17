<?php

use Illuminate\Support\Facades\Route;

// M3: a home agora lista os projetos (instâncias).
Route::get('/', function () {
    return view('instances');
});

// Mantidas por compatibilidade até o M4 refatorar as telas legadas.
Route::get('/chat', function () {
    return view('chat');
});
