<?php

use App\Http\Controllers\Api\V1\UsuarioController;
use App\Http\Middleware\LocalDevelopmentOnly;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(LocalDevelopmentOnly::class)->group(function (): void {
    Route::get('usuarios', [UsuarioController::class, 'index'])->name('api.v1.usuarios.index');
    Route::post('usuarios', [UsuarioController::class, 'store'])->name('api.v1.usuarios.store');
});
