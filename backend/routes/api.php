<?php

use App\Http\Controllers\Api\V1\AfiliacionController;
use App\Http\Controllers\Api\V1\ArchivoDocumentoController;
use App\Http\Controllers\Api\V1\AuditoriaEventoController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BanderasSinResponderController;
use App\Http\Controllers\Api\V1\BitacoraAccesoLegajoController;
use App\Http\Controllers\Api\V1\CapacitacionController;
use App\Http\Controllers\Api\V1\CertificadoDiscapacidadController;
use App\Http\Controllers\Api\V1\ColegiaturaController;
use App\Http\Controllers\Api\V1\CompensacionBeneficioController;
use App\Http\Controllers\Api\V1\CompromisoDocumentoController;
use App\Http\Controllers\Api\V1\CompromisoEntregaOriginalController;
use App\Http\Controllers\Api\V1\ConfiguracionFuncionalidadController;
use App\Http\Controllers\Api\V1\ConsultaIdentidadController;
use App\Http\Controllers\Api\V1\ControversiaController;
use App\Http\Controllers\Api\V1\DeclaracionVerificacionController;
use App\Http\Controllers\Api\V1\DesplazamientoController;
use App\Http\Controllers\Api\V1\DesvinculacionController;
use App\Http\Controllers\Api\V1\DocumentoUsuarioController;
use App\Http\Controllers\Api\V1\EmisionPersonalController;
use App\Http\Controllers\Api\V1\EquipoAccesoController;
use App\Http\Controllers\Api\V1\EvaluacionDesempenoController;
use App\Http\Controllers\Api\V1\Evaluaciones\Seccion07Controller;
use App\Http\Controllers\Api\V1\ExperienciaLaboralController;
use App\Http\Controllers\Api\V1\FamiliarController;
use App\Http\Controllers\Api\V1\FeriadoController;
use App\Http\Controllers\Api\V1\FormacionAcademicaController;
use App\Http\Controllers\Api\V1\FormatosDirectivaController;
use App\Http\Controllers\Api\V1\GmailCorreoController;
use App\Http\Controllers\Api\V1\GoceVacacionalController;
use App\Http\Controllers\Api\V1\HistorialEstadoDocumentoController;
use App\Http\Controllers\Api\V1\InduccionController;
use App\Http\Controllers\Api\V1\InstitutionSettingsController;
use App\Http\Controllers\Api\V1\LegajoController;
use App\Http\Controllers\Api\V1\Legajos\AperturaLegajoController;
use App\Http\Controllers\Api\V1\Legajos\DocumentoLegajoController;
use App\Http\Controllers\Api\V1\Legajos\ExpedienteController;
use App\Http\Controllers\Api\V1\Legajos\ExpedienteImprimibleController;
use App\Http\Controllers\Api\V1\Legajos\FamiliarPersonalController;
use App\Http\Controllers\Api\V1\Legajos\FotoPersonalController;
use App\Http\Controllers\Api\V1\Legajos\Seccion01Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion02Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion03Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion04Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion05Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion06Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion07Controller as Seccion07LegajoController;
use App\Http\Controllers\Api\V1\Legajos\Seccion08Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion09Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion10Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion11Controller;
use App\Http\Controllers\Api\V1\Legajos\VinculoTipoController;
use App\Http\Controllers\Api\V1\LegajoSeccionController;
use App\Http\Controllers\Api\V1\LicenciaPermisoController;
use App\Http\Controllers\Api\V1\ModuloSistemaController;
use App\Http\Controllers\Api\V1\MovimientoCustodiaLegajoController;
use App\Http\Controllers\Api\V1\NotificacionUsuarioController;
use App\Http\Controllers\Api\V1\ObservacionDocumentoController;
use App\Http\Controllers\Api\V1\ParametroSistemaController;
use App\Http\Controllers\Api\V1\PerfilAdministrativoController;
use App\Http\Controllers\Api\V1\PerfilDocenteController;
use App\Http\Controllers\Api\V1\PeriodoPruebaController;
use App\Http\Controllers\Api\V1\PeriodoVacacionalController;
use App\Http\Controllers\Api\V1\ProcesoFiscalizacionController;
use App\Http\Controllers\Api\V1\ProgresionCarreraController;
use App\Http\Controllers\Api\V1\RatificacionDocenteController;
use App\Http\Controllers\Api\V1\ReconocimientoController;
use App\Http\Controllers\Api\V1\ReferenciaLegajoPasivoController;
use App\Http\Controllers\Api\V1\RegimenNormativoController;
use App\Http\Controllers\Api\V1\RegistroSstBienestarController;
use App\Http\Controllers\Api\V1\ReporteController;
use App\Http\Controllers\Api\V1\RequerimientoDocumentoController;
use App\Http\Controllers\Api\V1\RolController;
use App\Http\Controllers\Api\V1\SancionController;
use App\Http\Controllers\Api\V1\SeccionLegajoController;
use App\Http\Controllers\Api\V1\SolicitudAccesoLegajoController;
use App\Http\Controllers\Api\V1\SolicitudFiscalizacionController;
use App\Http\Controllers\Api\V1\SolicitudLegajoController;
use App\Http\Controllers\Api\V1\SubseccionLegajoController;
use App\Http\Controllers\Api\V1\TiempoServicioReconocidoController;
use App\Http\Controllers\Api\V1\TipoDocumentoController;
use App\Http\Controllers\Api\V1\UbigeoController;
use App\Http\Controllers\Api\V1\UnidadOrganicaController;
use App\Http\Controllers\Api\V1\UsuarioController;
use App\Http\Controllers\Api\V1\UsuarioRolController;
use App\Http\Controllers\Api\V1\VencimientosController;
use App\Http\Controllers\Api\V1\VinculoAdendaController;
use App\Http\Controllers\Api\V1\VinculoLaboralController;
use App\Http\Middleware\ApiHabilitada;
use App\Http\Middleware\ExigeCambioPassword;
use App\Http\Middleware\FuncionalidadActiva;
use App\Http\Middleware\LegajoSoloLectura;
use App\Http\Middleware\ModuloSistemaActivo;
use App\Support\ConsultaReportes;
use App\Support\EmisionesPersonales;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->middleware(ApiHabilitada::class)->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::put('password', [AuthController::class, 'cambiarPassword'])->middleware(['auth:sanctum', 'throttle:10,1']);
});

// FuncionalidadActiva: con una funcionalidad desactivada, sus rutas de creación y modificación responden 409.
Route::prefix('v1')->middleware([ApiHabilitada::class, 'auth:sanctum', ExigeCambioPassword::class, FuncionalidadActiva::class, LegajoSoloLectura::class])->group(function (): void {
    Route::prefix('correo/gmail')->middleware('can:sistema.administrar')->controller(GmailCorreoController::class)->group(function (): void {
        Route::get('/', 'estado');
        Route::post('autorizar', 'autorizar')->middleware('throttle:10,1');
        Route::delete('/', 'desconectar');
    });
    Route::prefix('solicitudes-legajo')->middleware('can:requerimientos.gestionar')->controller(SolicitudLegajoController::class)->group(function (): void {
        Route::get('/', 'index');
        Route::get('opciones', 'opciones');
        Route::get('sugerencias', 'sugerencias');
        Route::post('/', 'store');
        Route::post('sincronizar', 'sincronizar')->middleware('throttle:10,1');
        Route::post('{record}/registrar-entrega', 'registrarEntrega')->whereUuid('record');
        Route::post('{record}/enviar', 'enviar')->whereUuid('record');
        Route::post('{record}/gestionar', 'gestionar')->whereUuid('record');
        Route::get('{record}/documentos', 'documentos')->whereUuid('record');
        Route::get('{record}/archivos/{indice}/{adjunto}', 'archivo')->whereUuid('record')->whereNumber('indice')->whereNumber('adjunto');
        Route::get('{record}/archivos/{indice}', 'archivo')->whereUuid('record')->whereNumber('indice');
    });
    Route::get('reportes/opciones', [ReporteController::class, 'opciones']);
    // Formatos de la directiva (anexos 06 a 09): solo quien administra el sistema y ve legajos.
    Route::prefix('reportes/formatos')->middleware(['can:sistema.administrar', 'can:legajo.ver'])->controller(FormatosDirectivaController::class)->group(function (): void {
        Route::get('/', 'catalogo');
        Route::get('legajos', 'legajos');
        Route::get('{formato}', 'show')->whereIn('formato', array_keys(FormatosDirectivaController::FORMATOS));
    });
    // Informe escalafonario y constancias de una persona: mismos permisos que los formatos de la directiva.
    Route::get('reportes/personales/{usuario}/{documento}', [EmisionPersonalController::class, 'show'])->middleware(['can:sistema.administrar', 'can:legajo.ver'])
        ->whereUuid('usuario')->whereIn('documento', array_keys(EmisionesPersonales::DOCUMENTOS));
    Route::get('reportes/{tipo}', [ReporteController::class, 'show'])->whereIn('tipo', array_keys(ConsultaReportes::catalogo()));
    Route::get('vencimientos', VencimientosController::class)->middleware('can:legajo.ver');
    Route::get('institucion', [InstitutionSettingsController::class, 'show']);
    Route::middleware('can:equipo.gestionar')->prefix('equipo-accesos')->group(function (): void {
        Route::get('/', [EquipoAccesoController::class, 'index']);
        Route::get('personas', [EquipoAccesoController::class, 'personas']);
        Route::post('/', [EquipoAccesoController::class, 'store']);
        Route::patch('{asignacion}', [EquipoAccesoController::class, 'update'])->whereUuid('asignacion');
        Route::post('{asignacion}/revocar', [EquipoAccesoController::class, 'revocar'])->whereUuid('asignacion');
        Route::post('{asignacion}/restablecer', [EquipoAccesoController::class, 'restablecer'])->whereUuid('asignacion');
    });
    // Qué ocultar en la navegación (cualquier sesión). Cambiar la configuración exige configuracion.modulos.
    Route::get('funcionalidades', [ConfiguracionFuncionalidadController::class, 'publico']);
    Route::put('institucion', [InstitutionSettingsController::class, 'update'])->middleware('can:sistema.administrar');
    // Configuración de funcionalidades: solo configuracion.modulos (asignación explícita, fuera de '*').
    Route::middleware('can:configuracion.modulos')->group(function (): void {
        Route::get('configuracion/modulos-sistema', [ModuloSistemaController::class, 'index']);
        Route::patch('configuracion/modulos-sistema/{codigo}', [ModuloSistemaController::class, 'update']);
        Route::get('configuracion/funcionalidades', [ConfiguracionFuncionalidadController::class, 'index']);
        Route::patch('configuracion/funcionalidades/{codigo}', [ConfiguracionFuncionalidadController::class, 'update'])->where('codigo', '[a-z0-9_.]+');
    });
    Route::middleware('can:datos_sensibles.ver')->group(function (): void {
        Route::get('expedientes/{usuario}/seccion-10', [Seccion10Controller::class, 'show'])->middleware('can:legajo.ver')->whereUuid('usuario');
        Route::get('expedientes/{usuario}/seccion-10/documentos/{documento}/historial', [Seccion10Controller::class, 'historial'])
            ->middleware('can:legajo.ver')->whereUuid(['usuario', 'documento']);
        Route::post('expedientes/{usuario}/seccion-10/documentos', [Seccion10Controller::class, 'store'])->middleware('can:documento.subir')->whereUuid('usuario');
        Route::patch('expedientes/{usuario}/seccion-10/documentos/{documento}', [Seccion10Controller::class, 'update'])
            ->middleware('can:legajo.editar')->whereUuid(['usuario', 'documento']);
        Route::post('expedientes/{usuario}/seccion-10/documentos/{documento}/estado', [Seccion10Controller::class, 'cambiarEstado'])
            ->middleware('can:documento.revisar')->whereUuid(['usuario', 'documento']);
        Route::post('expedientes/{usuario}/seccion-10/documentos/{documento}/anular', [Seccion10Controller::class, 'anular'])
            ->middleware('can:sistema.administrar')->whereUuid(['usuario', 'documento']);
    });
    Route::middleware('can:legajo.ver')->group(function (): void {
        Route::get('expedientes', [ExpedienteController::class, 'index']);
        Route::get('ubicaciones', [ExpedienteController::class, 'locations']);
        Route::get('expediente-opciones', [ExpedienteController::class, 'editOptions']);
        Route::get('expedientes/{usuario}', [ExpedienteController::class, 'show'])->whereUuid('usuario');
        Route::get('expedientes/{usuario}/foto', [FotoPersonalController::class, 'show'])->whereUuid('usuario');
        // Vista previa del expediente imprimible (fase 1, secciones 01 a 04); antes de la ruta genérica {section}.
        Route::get('expedientes/{usuario}/expediente-imprimible', [ExpedienteImprimibleController::class, 'show'])->whereUuid('usuario');
        Route::get('expedientes/{usuario}/expediente-imprimible/zip', [ExpedienteImprimibleController::class, 'descargar'])->whereUuid('usuario')->middleware('throttle:10,1');
        // Antes de la ruta genérica {section}. La sección 01 exige además datos_sensibles.ver.
        Route::get('expedientes/{usuario}/seccion-01', [Seccion01Controller::class, 'show'])->whereUuid('usuario')->middleware('can:datos_sensibles.ver');
        Route::get('expedientes/{usuario}/seccion-02', [Seccion02Controller::class, 'show'])->whereUuid('usuario');
        Route::get('expedientes/{usuario}/seccion-06', [Seccion06Controller::class, 'show'])->whereUuid('usuario');
        Route::get('expedientes/{usuario}/seccion-04', [Seccion04Controller::class, 'show'])->whereUuid('usuario');
        Route::get('expedientes/{usuario}/seccion-03', [Seccion03Controller::class, 'show'])->whereUuid('usuario');
        Route::get('expedientes/{usuario}/seccion-02/anulados', [Seccion02Controller::class, 'anulados'])->whereUuid('usuario')->middleware('can:sistema.administrar');
        Route::get('expedientes/{usuario}/seccion-05', [Seccion05Controller::class, 'show'])->whereUuid('usuario');
        Route::get('expedientes/{usuario}/seccion-05/anulados', [Seccion05Controller::class, 'anulados'])->whereUuid('usuario')->middleware('can:sistema.administrar');
        // Sección 07: además de legajo.ver, seccion07.ver. La calificación sale solo con seccion07.ver_calificacion.
        Route::get('expedientes/{usuario}/seccion-07', [Seccion07LegajoController::class, 'show'])->whereUuid('usuario')->middleware('can:seccion07.ver');
        Route::get('expedientes/{usuario}/seccion-08', [Seccion08Controller::class, 'show'])->whereUuid('usuario');
        Route::get('expedientes/{usuario}/seccion-07/resumen', [Seccion07LegajoController::class, 'resumen'])->whereUuid('usuario')->middleware('can:seccion07.ver');
        // Sección 09: sensible (afiliación sindical, Directiva definición "e"), exige además datos_sensibles.ver.
        Route::get('expedientes/{usuario}/seccion-09', [Seccion09Controller::class, 'show'])->whereUuid('usuario')->middleware('can:datos_sensibles.ver');
        // Sección 11 (desvinculación): no es sensible; la liquidación (tipo sensible) se filtra en el controlador.
        Route::get('expedientes/{usuario}/seccion-11', [Seccion11Controller::class, 'show'])->whereUuid('usuario');
        Route::get('expedientes/{usuario}/seccion-11/anulados', [Seccion11Controller::class, 'anulados'])->whereUuid('usuario')->middleware('can:sistema.administrar');
        Route::get('expedientes/{usuario}/{section}', [ExpedienteController::class, 'section'])->whereUuid('usuario');
    });

    Route::middleware('can:legajo.editar')->group(function (): void {
        Route::post('expedientes/{usuario}/foto', [FotoPersonalController::class, 'store'])->whereUuid('usuario');
    });

    // Sección 01 (datos personales y familiares): además del permiso de la acción, datos_sensibles.ver.
    Route::get('expedientes/{usuario}/declaraciones-verificaciones/{declaracion}/pdf', [Seccion01Controller::class, 'descargarDeclaracion'])
        ->middleware(['can:legajo.ver', 'can:datos_sensibles.ver'])->whereUuid(['usuario', 'declaracion']);
    Route::get('expedientes/{usuario}/certificados-discapacidad/{certificado}/pdf', [Seccion01Controller::class, 'descargarCertificado'])
        ->middleware(['can:legajo.ver', 'can:datos_sensibles.ver'])->whereUuid(['usuario', 'certificado']);

    Route::middleware(['can:legajo.editar', 'can:datos_sensibles.ver'])->group(function (): void {
        Route::patch('expedientes/{usuario}', [ExpedienteController::class, 'update'])->whereUuid('usuario');
        Route::post('expedientes/{usuario}/familiares', [FamiliarPersonalController::class, 'storeForPerson'])->whereUuid('usuario');
        Route::patch('expedientes/{usuario}/familiares/{familiar}', [FamiliarPersonalController::class, 'updateForPerson'])->whereUuid(['usuario', 'familiar']);
        Route::post('expedientes/{usuario}/declaraciones-verificaciones', [Seccion01Controller::class, 'storeDeclaracion'])->whereUuid('usuario');
        Route::patch('expedientes/{usuario}/declaraciones-verificaciones/{declaracion}', [Seccion01Controller::class, 'updateDeclaracion'])->whereUuid(['usuario', 'declaracion']);
        Route::post('expedientes/{usuario}/certificados-discapacidad', [Seccion01Controller::class, 'storeCertificado'])->whereUuid('usuario');
        Route::patch('expedientes/{usuario}/certificados-discapacidad/{certificado}', [Seccion01Controller::class, 'updateCertificado'])->whereUuid(['usuario', 'certificado']);
    });

    // Sección 02 (incorporación): no tiene datos sensibles.
    Route::middleware('can:legajo.editar')->group(function (): void {
        Route::post('expedientes/{usuario}/vinculos-laborales', [Seccion02Controller::class, 'storeVinculo'])->whereUuid('usuario');
        Route::patch('expedientes/{usuario}/vinculos-laborales/{vinculo}', [Seccion02Controller::class, 'updateVinculo'])->whereUuid(['usuario', 'vinculo']);
        Route::put('expedientes/{usuario}/vinculos-laborales/{vinculo}/datos-puesto', [Seccion02Controller::class, 'guardarDatosPuesto'])->whereUuid(['usuario', 'vinculo']);
        // Vínculo cerrado Sin definir: solo se completa el tipo, una vez (auditado).
        Route::post('expedientes/{usuario}/vinculos-laborales/{vinculo}/completar-tipo', [VinculoTipoController::class, 'completar'])->whereUuid(['usuario', 'vinculo']);
        Route::post('expedientes/{usuario}/vinculo-adendas', [Seccion02Controller::class, 'storeAdenda'])->whereUuid('usuario');
        Route::post('expedientes/{usuario}/inducciones', [Seccion02Controller::class, 'storeInduccion'])->whereUuid('usuario');
        Route::post('expedientes/{usuario}/periodos-prueba', [Seccion02Controller::class, 'storePeriodo'])->whereUuid('usuario');
        Route::patch('expedientes/{usuario}/periodos-prueba/{periodo}', [Seccion02Controller::class, 'updatePeriodo'])->whereUuid(['usuario', 'periodo']);
    });

    // Sección 05 (movimientos del personal). El descanso médico (dato de salud) exige además datos_sensibles.ver: lo valida el controlador.
    Route::middleware('can:legajo.editar')->group(function (): void {
        Route::post('expedientes/{usuario}/seccion-05/licencias-permisos', [Seccion05Controller::class, 'storeLicencia'])->whereUuid('usuario');
        Route::post('expedientes/{usuario}/seccion-05/periodos-vacacionales', [Seccion05Controller::class, 'storePeriodo'])->whereUuid('usuario');
        Route::post('expedientes/{usuario}/seccion-05/goces-vacacionales', [Seccion05Controller::class, 'storeGoce'])->whereUuid('usuario');
    });

    // Sección 07 (evaluación, progresión y desplazamiento): registrar y corregir con seccion07.registrar; anular, además, solo administración.
    Route::middleware('can:seccion07.registrar')->group(function (): void {
        $tipos07 = ['evaluaciones-desempeno', 'progresiones-carrera', 'ratificaciones-docentes', 'desplazamientos'];
        Route::post('expedientes/{usuario}/seccion-07/{tipo}', [Seccion07LegajoController::class, 'store'])->whereUuid('usuario')->whereIn('tipo', $tipos07);
        Route::patch('expedientes/{usuario}/seccion-07/{tipo}/{registro}', [Seccion07LegajoController::class, 'update'])->whereUuid(['usuario', 'registro'])->whereIn('tipo', $tipos07);
        Route::post('expedientes/{usuario}/seccion-07/{tipo}/{registro}/anular', [Seccion07LegajoController::class, 'anularRegistro'])
            ->whereUuid(['usuario', 'registro'])->whereIn('tipo', $tipos07)->middleware('can:sistema.administrar');
    });

    // Sección 09 (relaciones laborales): registrar y corregir con legajo.editar; anular con sistema.administrar. Todo con datos_sensibles.ver.
    Route::middleware(['can:legajo.editar', 'can:datos_sensibles.ver'])->group(function (): void {
        Route::post('expedientes/{usuario}/seccion-09/controversias', [Seccion09Controller::class, 'storeControversia'])->whereUuid('usuario');
        Route::patch('expedientes/{usuario}/seccion-09/controversias/{registro}', [Seccion09Controller::class, 'updateControversia'])->whereUuid(['usuario', 'registro']);
        Route::post('expedientes/{usuario}/seccion-09/afiliaciones', [Seccion09Controller::class, 'storeAfiliacion'])->whereUuid('usuario');
        Route::patch('expedientes/{usuario}/seccion-09/afiliaciones/{registro}', [Seccion09Controller::class, 'updateAfiliacion'])->whereUuid(['usuario', 'registro']);
    });
    Route::post('expedientes/{usuario}/seccion-09/{tipo}/{registro}/anular', [Seccion09Controller::class, 'anularRegistro'])
        ->middleware(['can:sistema.administrar', 'can:datos_sensibles.ver'])->whereUuid(['usuario', 'registro'])->whereIn('tipo', ['controversias', 'afiliaciones']);

    // Sección 11 (desvinculación): registrar y corregir con legajo.editar; anular con sistema.administrar.
    Route::middleware('can:legajo.editar')->group(function (): void {
        Route::post('expedientes/{usuario}/seccion-11/desvinculaciones', [Seccion11Controller::class, 'store'])->whereUuid('usuario');
        Route::patch('expedientes/{usuario}/seccion-11/desvinculaciones/{registro}', [Seccion11Controller::class, 'update'])->whereUuid(['usuario', 'registro']);
    });
    Route::post('expedientes/{usuario}/seccion-11/desvinculaciones/{registro}/anular', [Seccion11Controller::class, 'anularRegistro'])
        ->middleware('can:sistema.administrar')->whereUuid(['usuario', 'registro']);

    Route::post('expedientes/{usuario}/legajo', [AperturaLegajoController::class, 'store'])->middleware('can:legajo.abrir')->whereUuid('usuario');
    Route::post('expedientes/{usuario}/legajos/{legajo}/cerrar', [AperturaLegajoController::class, 'cerrar'])
        ->middleware('can:legajo.editar')->whereUuid(['usuario', 'legajo']);
    Route::post('expedientes/{usuario}/legajos/{legajo}/reabrir', [AperturaLegajoController::class, 'reabrir'])
        ->middleware('can:legajo.abrir')->whereUuid(['usuario', 'legajo']);

    // Documentos del legajo (PDF + metadatos), común a todas las secciones. Las secciones 01, 09 y 10
    // exigen además datos_sensibles.ver (lo valida el controlador según la sección y el tipo).
    Route::middleware('can:documento.subir')->group(function (): void {
        Route::post('expedientes/{usuario}/seccion-06/documentos', [Seccion06Controller::class, 'store'])->whereUuid('usuario');
        Route::post('expedientes/{usuario}/seccion-04/documentos', [Seccion04Controller::class, 'store'])->whereUuid('usuario');
        Route::post('expedientes/{usuario}/seccion-03/documentos', [Seccion03Controller::class, 'store'])->whereUuid('usuario');
        Route::get('expedientes/{usuario}/documentos/folio-sugerido', [DocumentoLegajoController::class, 'folio'])->whereUuid('usuario');
        Route::post('expedientes/{usuario}/documentos', [DocumentoLegajoController::class, 'store'])->whereUuid('usuario');
        // Enlazar más tarde un documento ya cargado que quedó sin registro (sin volver a subirlo).
        Route::post('expedientes/{usuario}/documentos/{documento}/enlazar', [DocumentoLegajoController::class, 'enlazar'])->whereUuid(['usuario', 'documento']);
    });
    Route::post('expedientes/{usuario}/seccion-08/documentos', [Seccion08Controller::class, 'store'])->whereUuid('usuario');
    Route::post('expedientes/{usuario}/seccion-06/documentos/{documento}/estado', [Seccion06Controller::class, 'cambiarEstado'])
        ->middleware('can:documento.revisar')->whereUuid(['usuario', 'documento']);
    Route::post('expedientes/{usuario}/seccion-08/documentos/{documento}/estado', [Seccion08Controller::class, 'cambiarEstado'])
        ->whereUuid(['usuario', 'documento']);
    Route::post('expedientes/{usuario}/seccion-04/documentos/{documento}/estado', [Seccion04Controller::class, 'cambiarEstado'])
        ->middleware('can:documento.revisar')->whereUuid(['usuario', 'documento']);
    Route::post('expedientes/{usuario}/seccion-03/documentos/{documento}/estado', [Seccion03Controller::class, 'cambiarEstado'])
        ->middleware('can:documento.revisar')->whereUuid(['usuario', 'documento']);
    Route::post('expedientes/{usuario}/documentos/{documento}/recepcionar', [DocumentoLegajoController::class, 'recepcionar'])
        ->middleware('can:documento.recepcionar')->whereUuid(['usuario', 'documento']);
    Route::get('expedientes/{usuario}/documentos/{documento}/archivo', [DocumentoLegajoController::class, 'archivo'])
        ->middleware('can:legajo.ver')->whereUuid(['usuario', 'documento']);
    // Tipos de documento que no corresponden a la persona (docente o administrativo, régimen): no se ofrecen para cargar.
    Route::get('expedientes/{usuario}/documentos/no-aplicables', [DocumentoLegajoController::class, 'noAplicables'])
        ->middleware('can:legajo.ver')->whereUuid('usuario');

    Route::middleware('can:sistema.administrar')->group(function (): void {
        // Personas con banderas del perfil sin responder (Sí / No / Sin responder).
        Route::get('personal/banderas-sin-responder', BanderasSinResponderController::class);
        // URI propia: la misma forma que la de la sección 01 la reemplazaría.
        Route::post('expedientes/{usuario}/seccion-02/{tipo}/{registro}/anular', [Seccion02Controller::class, 'anularRegistro'])
            ->whereUuid(['usuario', 'registro'])->whereIn('tipo', ['vinculos-laborales', 'vinculo-adendas', 'inducciones', 'periodos-prueba']);
        Route::post('expedientes/{usuario}/seccion-05/{tipo}/{registro}/anular', [Seccion05Controller::class, 'anularRegistro'])
            ->whereUuid(['usuario', 'registro'])->whereIn('tipo', ['licencias-permisos', 'periodos-vacacionales', 'goces-vacacionales']);
    });

    // Anular (no se elimina): solo administración.
    Route::middleware(['can:sistema.administrar', 'can:datos_sensibles.ver'])->group(function (): void {
        Route::post('expedientes/{usuario}/{tipo}/{registro}/anular', [Seccion01Controller::class, 'anularRegistro'])
            ->whereUuid(['usuario', 'registro'])->whereIn('tipo', ['familiares', 'declaraciones-verificaciones', 'certificados-discapacidad']);
    });

    Route::middleware(['can:seccion07.ver', ModuloSistemaActivo::class.':evaluaciones'])->group(function (): void {
        Route::get('seccion07-opciones', [Seccion07Controller::class, 'options']);
        Route::get('seccion07/{tipo}', [Seccion07Controller::class, 'index'])->whereIn('tipo', ['evaluaciones', 'progresiones', 'ratificaciones', 'desplazamientos']);
    });

    Route::middleware('can:personal.ver')->group(function (): void {
        Route::get('usuarios', [UsuarioController::class, 'index'])->name('api.v1.usuarios.index');
        Route::get('usuarios/{record}', [UsuarioController::class, 'show'])->whereUuid('record')->name('api.v1.usuarios.show');
    });

    Route::middleware('can:usuarios.gestionar')->group(function (): void {
        Route::post('usuarios', [UsuarioController::class, 'store'])->name('api.v1.usuarios.store');
        // Registro manual: persona con su primer vínculo (además exige legajo.abrir y legajo.editar, en el controlador).
        Route::post('usuarios/con-vinculo', [UsuarioController::class, 'storeConVinculo'])->name('api.v1.usuarios.store-con-vinculo');
        // Consulta de identidad por DNI para completar el registro manual (no crea personas; además exige legajo.abrir y
        // legajo.editar, en el controlador). El DNI va en el cuerpo, nunca en la URL.
        Route::get('identidad/consulta-dni', [ConsultaIdentidadController::class, 'estado'])->name('api.v1.identidad.estado');
        Route::post('identidad/consulta-dni', [ConsultaIdentidadController::class, 'consultar'])
            ->middleware(ModuloSistemaActivo::class.':'.ConsultaIdentidadController::MODULO)->name('api.v1.identidad.consultar');
        Route::match(['put', 'patch'], 'usuarios/{record}', [UsuarioController::class, 'update'])->whereUuid('record')->name('api.v1.usuarios.update');
        Route::delete('usuarios/{record}', [UsuarioController::class, 'destroy'])->whereUuid('record')->name('api.v1.usuarios.destroy');
    });
});

// CRUD genérico por tabla: solo para sistema.administrar. Fuera de local y pruebas es de solo lectura
// (ApiHabilitada:crud): su escritura salta las reglas de cada sección, y no se abre (API_CRUD_GENERICO_ESCRITURA) hasta
// que cada recurso esté protegido. El frontend solo lo lee; sus escrituras van por /expedientes.
// documentos-usuario y archivos-documento no tienen update ni destroy (Directiva, arts. 2.3 y 5.3:
// un documento no se elimina ni se altera). familiares, declaraciones-verificaciones y
// certificados-discapacidad (sección 01) exigen además datos_sensibles.ver; su destroy responde 405.
// vinculos-laborales, vinculo-adendas, inducciones, periodos-prueba, perfil-docente y perfil-administrativo también son
// solo de lectura: se registran, corrigen y anulan solo desde la sección 02 (Seccion02Controller). Responden 405.
// licencias-permisos, periodos-vacacionales y goces-vacacionales, igual: se registran y anulan solo desde la sección 05
// (Seccion05Controller). evaluaciones-desempeno, progresiones-carrera, ratificaciones-docentes y desplazamientos, igual: se
// registran, corrigen y anulan solo desde la sección 07 (Legajos\Seccion07Controller).
// afiliaciones y controversias (sección 09, sensibles): solo lectura y con datos_sensibles.ver; se registran, corrigen y
// anulan desde Legajos\Seccion09Controller.
// desvinculaciones (sección 11): solo lectura; se registran, corrigen y anulan desde Legajos\Seccion11Controller.
Route::prefix('v1')->middleware([ApiHabilitada::class.':crud', 'auth:sanctum', ExigeCambioPassword::class, 'can:sistema.administrar', FuncionalidadActiva::class])->group(function (): void {
    Route::apiResource('afiliaciones', AfiliacionController::class)->parameters(['afiliaciones' => 'record'])->whereUuid('record')->names('api.v1.afiliaciones')->only(['index', 'show'])->middleware('can:datos_sensibles.ver');
    Route::apiResource('archivos-documento', ArchivoDocumentoController::class)->parameters(['archivos-documento' => 'record'])->whereUuid('record')->names('api.v1.archivos-documento')->only(['index', 'show']);
    Route::apiResource('auditoria-eventos', AuditoriaEventoController::class)->parameters(['auditoria-eventos' => 'record'])->whereUuid('record')->names('api.v1.auditoria-eventos');
    Route::apiResource('bitacora-accesos-legajo', BitacoraAccesoLegajoController::class)->parameters(['bitacora-accesos-legajo' => 'record'])->whereUuid('record')->names('api.v1.bitacora-accesos-legajo');
    Route::apiResource('capacitaciones', CapacitacionController::class)->parameters(['capacitaciones' => 'record'])->whereUuid('record')->names('api.v1.capacitaciones');
    Route::apiResource('certificados-discapacidad', CertificadoDiscapacidadController::class)->parameters(['certificados-discapacidad' => 'record'])->whereUuid('record')->names('api.v1.certificados-discapacidad')->middleware('can:datos_sensibles.ver');
    Route::apiResource('colegiaturas', ColegiaturaController::class)->parameters(['colegiaturas' => 'record'])->whereUuid('record')->names('api.v1.colegiaturas');
    Route::apiResource('compensaciones-beneficios', CompensacionBeneficioController::class)->parameters(['compensaciones-beneficios' => 'record'])->whereUuid('record')->names('api.v1.compensaciones-beneficios');
    Route::apiResource('compromiso-documentos', CompromisoDocumentoController::class)->parameters(['compromiso-documentos' => 'record'])->whereUuid('record')->names('api.v1.compromiso-documentos');
    Route::apiResource('compromisos-entrega-originales', CompromisoEntregaOriginalController::class)->parameters(['compromisos-entrega-originales' => 'record'])->whereUuid('record')->names('api.v1.compromisos-entrega-originales');
    Route::apiResource('controversias', ControversiaController::class)->parameters(['controversias' => 'record'])->whereUuid('record')->names('api.v1.controversias')->only(['index', 'show'])->middleware('can:datos_sensibles.ver');
    Route::apiResource('declaraciones-verificaciones', DeclaracionVerificacionController::class)->parameters(['declaraciones-verificaciones' => 'record'])->whereUuid('record')->names('api.v1.declaraciones-verificaciones')->middleware('can:datos_sensibles.ver');
    Route::apiResource('desplazamientos', DesplazamientoController::class)->parameters(['desplazamientos' => 'record'])->whereUuid('record')->names('api.v1.desplazamientos')->only(['index', 'show']);
    Route::apiResource('desvinculaciones', DesvinculacionController::class)->parameters(['desvinculaciones' => 'record'])->whereUuid('record')->names('api.v1.desvinculaciones')->only(['index', 'show']);
    Route::apiResource('documentos-usuario', DocumentoUsuarioController::class)->parameters(['documentos-usuario' => 'record'])->whereUuid('record')->names('api.v1.documentos-usuario')->only(['index', 'show']);
    Route::apiResource('evaluaciones-desempeno', EvaluacionDesempenoController::class)->parameters(['evaluaciones-desempeno' => 'record'])->whereUuid('record')->names('api.v1.evaluaciones-desempeno')->only(['index', 'show']);
    Route::apiResource('experiencia-laboral', ExperienciaLaboralController::class)->parameters(['experiencia-laboral' => 'record'])->whereUuid('record')->names('api.v1.experiencia-laboral');
    Route::apiResource('familiares', FamiliarController::class)->parameters(['familiares' => 'record'])->whereUuid('record')->names('api.v1.familiares')->middleware('can:datos_sensibles.ver');
    Route::apiResource('feriados', FeriadoController::class)->parameters(['feriados' => 'record'])->whereUuid('record')->names('api.v1.feriados');
    Route::apiResource('formacion-academica', FormacionAcademicaController::class)->parameters(['formacion-academica' => 'record'])->whereUuid('record')->names('api.v1.formacion-academica');
    Route::apiResource('goces-vacacionales', GoceVacacionalController::class)->parameters(['goces-vacacionales' => 'record'])->whereUuid('record')->names('api.v1.goces-vacacionales')->only(['index', 'show']);
    Route::apiResource('historial-estados-documento', HistorialEstadoDocumentoController::class)->parameters(['historial-estados-documento' => 'record'])->whereUuid('record')->names('api.v1.historial-estados-documento');
    Route::apiResource('inducciones', InduccionController::class)->parameters(['inducciones' => 'record'])->whereUuid('record')->names('api.v1.inducciones')->only(['index', 'show']);
    Route::apiResource('legajos', LegajoController::class)->parameters(['legajos' => 'record'])->whereUuid('record')->names('api.v1.legajos');
    Route::apiResource('legajo-secciones', LegajoSeccionController::class)->parameters(['legajo-secciones' => 'record'])->whereUuid('record')->names('api.v1.legajo-secciones');
    Route::apiResource('licencias-permisos', LicenciaPermisoController::class)->parameters(['licencias-permisos' => 'record'])->whereUuid('record')->names('api.v1.licencias-permisos')->only(['index', 'show']);
    Route::apiResource('movimientos-custodia-legajo', MovimientoCustodiaLegajoController::class)->parameters(['movimientos-custodia-legajo' => 'record'])->whereUuid('record')->names('api.v1.movimientos-custodia-legajo');
    Route::apiResource('notificaciones-usuario', NotificacionUsuarioController::class)->parameters(['notificaciones-usuario' => 'record'])->whereUuid('record')->names('api.v1.notificaciones-usuario');
    Route::apiResource('observaciones-documento', ObservacionDocumentoController::class)->parameters(['observaciones-documento' => 'record'])->whereUuid('record')->names('api.v1.observaciones-documento');
    Route::apiResource('parametros-sistema', ParametroSistemaController::class)->parameters(['parametros-sistema' => 'record'])->whereUuid('record')->names('api.v1.parametros-sistema');
    Route::apiResource('perfil-administrativo', PerfilAdministrativoController::class)->parameters(['perfil-administrativo' => 'record'])->whereUuid('record')->names('api.v1.perfil-administrativo')->only(['index', 'show']);
    Route::apiResource('perfil-docente', PerfilDocenteController::class)->parameters(['perfil-docente' => 'record'])->whereUuid('record')->names('api.v1.perfil-docente')->only(['index', 'show']);
    Route::apiResource('periodos-prueba', PeriodoPruebaController::class)->parameters(['periodos-prueba' => 'record'])->whereUuid('record')->names('api.v1.periodos-prueba')->only(['index', 'show']);
    Route::apiResource('periodos-vacacionales', PeriodoVacacionalController::class)->parameters(['periodos-vacacionales' => 'record'])->whereUuid('record')->names('api.v1.periodos-vacacionales')->only(['index', 'show']);
    Route::apiResource('procesos-fiscalizacion', ProcesoFiscalizacionController::class)->parameters(['procesos-fiscalizacion' => 'record'])->whereUuid('record')->names('api.v1.procesos-fiscalizacion');
    Route::apiResource('progresiones-carrera', ProgresionCarreraController::class)->parameters(['progresiones-carrera' => 'record'])->whereUuid('record')->names('api.v1.progresiones-carrera')->only(['index', 'show']);
    Route::apiResource('ratificaciones-docentes', RatificacionDocenteController::class)->parameters(['ratificaciones-docentes' => 'record'])->whereUuid('record')->names('api.v1.ratificaciones-docentes')->only(['index', 'show']);
    Route::apiResource('reconocimientos', ReconocimientoController::class)->parameters(['reconocimientos' => 'record'])->whereUuid('record')->names('api.v1.reconocimientos')->only(['index', 'show']);
    Route::apiResource('referencias-legajo-pasivo', ReferenciaLegajoPasivoController::class)->parameters(['referencias-legajo-pasivo' => 'record'])->whereUuid('record')->names('api.v1.referencias-legajo-pasivo');
    Route::apiResource('regimenes-normativos', RegimenNormativoController::class)->parameters(['regimenes-normativos' => 'record'])->whereUuid('record')->names('api.v1.regimenes-normativos');
    Route::apiResource('registros-sst-bienestar', RegistroSstBienestarController::class)->parameters(['registros-sst-bienestar' => 'record'])->whereUuid('record')->names('api.v1.registros-sst-bienestar')->middleware('can:datos_sensibles.ver')->only(['index', 'show']);
    Route::apiResource('requerimientos-documentos', RequerimientoDocumentoController::class)->parameters(['requerimientos-documentos' => 'record'])->whereUuid('record')->names('api.v1.requerimientos-documentos');
    Route::apiResource('roles', RolController::class)->parameters(['roles' => 'record'])->whereUuid('record')->names('api.v1.roles');
    Route::apiResource('sanciones', SancionController::class)->parameters(['sanciones' => 'record'])->whereUuid('record')->names('api.v1.sanciones')->only(['index', 'show'])->middleware('can:seccion08.sanciones.ver');
    Route::apiResource('secciones-legajo', SeccionLegajoController::class)->parameters(['secciones-legajo' => 'record'])->whereNumber('record')->names('api.v1.secciones-legajo');
    Route::apiResource('solicitudes-acceso-legajo', SolicitudAccesoLegajoController::class)->parameters(['solicitudes-acceso-legajo' => 'record'])->whereUuid('record')->names('api.v1.solicitudes-acceso-legajo');
    Route::apiResource('solicitudes-fiscalizacion', SolicitudFiscalizacionController::class)->parameters(['solicitudes-fiscalizacion' => 'record'])->whereUuid('record')->names('api.v1.solicitudes-fiscalizacion');
    Route::apiResource('subsecciones-legajo', SubseccionLegajoController::class)->parameters(['subsecciones-legajo' => 'record'])->whereUuid('record')->names('api.v1.subsecciones-legajo');
    Route::apiResource('tiempo-servicios-reconocidos', TiempoServicioReconocidoController::class)->parameters(['tiempo-servicios-reconocidos' => 'record'])->whereUuid('record')->names('api.v1.tiempo-servicios-reconocidos');
    Route::apiResource('tipos-documento', TipoDocumentoController::class)->parameters(['tipos-documento' => 'record'])->whereUuid('record')->names('api.v1.tipos-documento');
    Route::apiResource('ubigeo', UbigeoController::class)->parameters(['ubigeo' => 'record'])->whereUuid('record')->names('api.v1.ubigeo');
    Route::apiResource('unidades-organicas', UnidadOrganicaController::class)->parameters(['unidades-organicas' => 'record'])->whereUuid('record')->names('api.v1.unidades-organicas');
    Route::apiResource('usuario-roles', UsuarioRolController::class)->parameters(['usuario-roles' => 'record'])->whereUuid('record')->names('api.v1.usuario-roles');
    Route::apiResource('vinculo-adendas', VinculoAdendaController::class)->parameters(['vinculo-adendas' => 'record'])->whereUuid('record')->names('api.v1.vinculo-adendas')->only(['index', 'show']);
    Route::apiResource('vinculos-laborales', VinculoLaboralController::class)->parameters(['vinculos-laborales' => 'record'])->whereUuid('record')->names('api.v1.vinculos-laborales')->only(['index', 'show']);
});
