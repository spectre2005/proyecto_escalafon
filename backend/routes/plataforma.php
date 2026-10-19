<?php

use App\Http\Controllers\Plataforma\AdministradorInstitucionController;
use App\Http\Controllers\Plataforma\AuditoriaController;
use App\Http\Controllers\Plataforma\AuthController;
use App\Http\Controllers\Plataforma\CuentasPlataformaController;
use App\Http\Controllers\Plataforma\InstitucionController;
use App\Http\Controllers\Plataforma\OperacionInstitucionController;
use App\Http\Controllers\Plataforma\PeriodoServicioController;
use App\Http\Middleware\ApiHabilitada;
use App\Http\Middleware\AutenticarSesionPlataforma;
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

    Route::middleware(['auth:plataforma', AutenticarSesionPlataforma::class])->group(function (): void {
        Route::get('instituciones/{institucion}/contrato', [OperacionInstitucionController::class, 'contrato'])->whereUuid('institucion');
        Route::put('instituciones/{institucion}/contrato', [OperacionInstitucionController::class, 'guardarContrato'])->whereUuid('institucion');
        Route::get('instituciones/{institucion}/consumo', [OperacionInstitucionController::class, 'consumo'])->middleware('throttle:6,1')->whereUuid('institucion');
        Route::get('cuentas', [CuentasPlataformaController::class, 'index']);
        Route::post('cuentas', [CuentasPlataformaController::class, 'store'])->middleware('throttle:10,1');
        Route::patch('cuentas/{cuenta}/estado', [CuentasPlataformaController::class, 'estado'])->whereUuid('cuenta');
        Route::post('cuentas/{cuenta}/restablecer', [CuentasPlataformaController::class, 'restablecer'])->middleware('throttle:10,1')->whereUuid('cuenta');
        Route::get('auditoria', [AuditoriaController::class, 'index']);
        Route::get('auditoria/exportar', [AuditoriaController::class, 'exportar']);
        Route::post('instituciones/{institucion}/exportar', [InstitucionController::class, 'exportar'])->middleware('throttle:3,1')->whereUuid('institucion');
        Route::post('instituciones/{institucion}/archivar', [InstitucionController::class, 'archivar'])->whereUuid('institucion');
        Route::put('auth/password', [AuthController::class, 'cambiarPassword'])->middleware('throttle:10,1');
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
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
        Route::get('instituciones/{institucion}/administradores/persona-existente', [AdministradorInstitucionController::class, 'candidato'])->middleware('throttle:20,1')->whereUuid('institucion');
        Route::post('instituciones/{institucion}/administradores/asignar-existente', [AdministradorInstitucionController::class, 'asignarExistente'])->whereUuid('institucion');
        Route::get('instituciones/{institucion}/administradores', [AdministradorInstitucionController::class, 'index'])->whereUuid('institucion');
        Route::post('instituciones/{institucion}/administradores', [AdministradorInstitucionController::class, 'store'])->whereUuid('institucion');
        Route::post('instituciones/{institucion}/administradores/{administrador}/restablecer', [AdministradorInstitucionController::class, 'restablecer'])->whereUuid(['institucion', 'administrador']);
        Route::post('instituciones/{institucion}/administradores/{administrador}/desactivar', [AdministradorInstitucionController::class, 'desactivar'])->whereUuid(['institucion', 'administrador']);
        Route::post('instituciones/{institucion}/administradores/{administrador}/reactivar', [AdministradorInstitucionController::class, 'reactivar'])->whereUuid(['institucion', 'administrador']);
    });
});
