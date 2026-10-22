<?php

use App\Http\Controllers\Api\V1\GmailCorreoController;
use App\Http\Middleware\ApiHabilitada;
use App\Http\Middleware\ExigeCambioPassword;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Google vuelve mediante navegación del navegador: necesita la sesión web aun sin Origin del SPA.
Route::get('/api/v1/correo/gmail/callback', [GmailCorreoController::class, 'callback'])
    ->middleware([ApiHabilitada::class, 'can:sistema.administrar', ExigeCambioPassword::class])
    ->name('correo.gmail.callback');

Route::get('/api/v1/correo/gmail/retorno-local', [GmailCorreoController::class, 'retornoLocal'])
    ->name('correo.gmail.retorno-local');
