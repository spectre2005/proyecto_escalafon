<?php

use App\Http\Controllers\Plataforma\AdministradorInstitucionController;
use App\Http\Controllers\Plataforma\AuthController;
use App\Http\Controllers\Plataforma\InstitucionController;
use App\Http\Controllers\Plataforma\PeriodoServicioController;
use App\Http\Controllers\Plataforma\SuperadministradorController;
use App\Http\Middleware\ApiHabilitada;
use App\Http\Middleware\SesionPlataformaValida;
use Illuminate\Support\Facades\Route;

/*
 * Panel de la plataforma SaaS (prefijo api/plataforma, grupo api con sesión y CSRF de Sanctum). Guard «plataforma»:
 * una sesión institucional no entra aquí y esta no entra en api/v1. Como el resto de la API, fuera de local y pruebas
 * solo responde con API_HABILITADA=true (ApiHabilitada). Instituciones, sus administradores y su auditoría: ninguna ruta
 * a personas, legajos ni documentos.
 */
Route::middleware(ApiHabilitada::class)->group(function (): void {
    Route::get('auth/csrf', [AuthController::class, 'csrf']);
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

    // SesionPlataformaValida: cuenta activa, contraseña sin cambios desde el inicio de sesión y, con contraseña temporal,
    // solo sesión, cierre y cambio de contraseña.
    Route::middleware(['auth:plataforma', SesionPlataformaValida::class])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/password', [AuthController::class, 'cambiarPassword'])->middleware('throttle:10,1');
        // Cuentas de superadministrador: no se borran; se desactivan.
        Route::get('superadministradores', [SuperadministradorController::class, 'index']);
        Route::post('superadministradores', [SuperadministradorController::class, 'store']);
        Route::post('superadministradores/{cuenta}/restablecer', [SuperadministradorController::class, 'restablecer'])->whereUuid('cuenta');
        Route::post('superadministradores/{cuenta}/desactivar', [SuperadministradorController::class, 'desactivar'])->whereUuid('cuenta');
        Route::post('superadministradores/{cuenta}/reactivar', [SuperadministradorController::class, 'reactivar'])->whereUuid('cuenta');
        Route::get('dashboard', [InstitucionController::class, 'resumen']);
        Route::get('instituciones', [InstitucionController::class, 'index']);
        Route::post('instituciones', [InstitucionController::class, 'store']);
        Route::get('instituciones/{institucion}', [InstitucionController::class, 'show'])->whereUuid('institucion');
        Route::patch('instituciones/{institucion}', [InstitucionController::class, 'update'])->whereUuid('institucion');
        Route::post('instituciones/{institucion}/suspender', [InstitucionController::class, 'suspender'])->whereUuid('institucion');
        Route::post('instituciones/{institucion}/activar', [InstitucionController::class, 'activar'])->whereUuid('institucion');
        Route::post('instituciones/{institucion}/aprovisionar', [InstitucionController::class, 'aprovisionar'])->whereUuid('institucion');
        // Tiempo de uso pagado: periodos (no se editan; se anulan con motivo).
        Route::get('instituciones/{institucion}/periodos', [PeriodoServicioController::class, 'index'])->whereUuid('institucion');
        Route::post('instituciones/{institucion}/periodos', [PeriodoServicioController::class, 'store'])->whereUuid('institucion');
        Route::post('instituciones/{institucion}/periodos/{periodo}/anular', [PeriodoServicioController::class, 'anular'])->whereUuid(['institucion', 'periodo']);
        // Administradores de cada institución: solo cuentas de administración, nunca personas ni legajos.
        Route::get('instituciones/{institucion}/administradores', [AdministradorInstitucionController::class, 'index'])->whereUuid('institucion');
        Route::post('instituciones/{institucion}/administradores', [AdministradorInstitucionController::class, 'store'])->whereUuid('institucion');
        Route::post('instituciones/{institucion}/administradores/{administrador}/restablecer', [AdministradorInstitucionController::class, 'restablecer'])->whereUuid(['institucion', 'administrador']);
        Route::post('instituciones/{institucion}/administradores/{administrador}/desactivar', [AdministradorInstitucionController::class, 'desactivar'])->whereUuid(['institucion', 'administrador']);
        Route::post('instituciones/{institucion}/administradores/{administrador}/reactivar', [AdministradorInstitucionController::class, 'reactivar'])->whereUuid(['institucion', 'administrador']);
    });
});
