<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateExpedienteRequest;
use App\Models\Afiliacion;
use App\Models\ArchivoDocumento;
use App\Models\AuditoriaEvento;
use App\Models\BitacoraAccesoLegajo;
use App\Models\Capacitacion;
use App\Models\CertificadoDiscapacidad;
use App\Models\Colegiatura;
use App\Models\CompensacionBeneficio;
use App\Models\CompromisoDocumento;
use App\Models\CompromisoEntregaOriginal;
use App\Models\Controversia;
use App\Models\DeclaracionVerificacion;
use App\Models\Desplazamiento;
use App\Models\Desvinculacion;
use App\Models\DocumentoUsuario;
use App\Models\EvaluacionDesempeno;
use App\Models\ExperienciaLaboral;
use App\Models\Familiar;
use App\Models\FormacionAcademica;
use App\Models\GoceVacacional;
use App\Models\HistorialEstadoDocumento;
use App\Models\Induccion;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\LicenciaPermiso;
use App\Models\MovimientoCustodiaLegajo;
use App\Models\NotificacionUsuario;
use App\Models\ObservacionDocumento;
use App\Models\PerfilAdministrativo;
use App\Models\PerfilDocente;
use App\Models\PeriodoPrueba;
use App\Models\PeriodoVacacional;
use App\Models\ProcesoFiscalizacion;
use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\Reconocimiento;
use App\Models\ReferenciaLegajoPasivo;
use App\Models\RegimenNormativo;
use App\Models\RegistroSstBienestar;
use App\Models\RequerimientoDocumento;
use App\Models\Sancion;
use App\Models\SeccionLegajo;
use App\Models\SolicitudAccesoLegajo;
use App\Models\SolicitudFiscalizacion;
use App\Models\TiempoServicioReconocido;
use App\Models\TipoDocumento;
use App\Models\Ubigeo;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use App\Support\PerfilDocumental;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpedienteController extends Controller
{
    private const SECTIONS = [
        'legajos' => ['model' => Legajo::class, 'label' => 'Legajos', 'group' => 'Expediente', 'field' => 'id', 'scope' => 'legajo'],
        'legajo-secciones' => ['model' => LegajoSeccion::class, 'label' => 'Secciones del legajo', 'group' => 'Expediente', 'field' => 'legajo_id', 'scope' => 'legajo'],
        'documentos-usuario' => ['model' => DocumentoUsuario::class, 'label' => 'Documentos', 'group' => 'Expediente', 'field' => 'legajo_id', 'scope' => 'legajo'],
        'archivos-documento' => ['model' => ArchivoDocumento::class, 'label' => 'Archivos', 'group' => 'Expediente', 'field' => 'id', 'scope' => 'archivo'],
        'tipos-documento' => ['model' => TipoDocumento::class, 'label' => 'Catálogo documental', 'group' => 'Expediente', 'field' => 'id', 'scope' => 'catalogo'],
        'formacion-academica' => ['model' => FormacionAcademica::class, 'label' => 'Formación académica', 'group' => 'Trayectoria', 'field' => 'usuario_id', 'scope' => 'persona'],
        'capacitaciones' => ['model' => Capacitacion::class, 'label' => 'Capacitaciones', 'group' => 'Trayectoria', 'field' => 'usuario_id', 'scope' => 'persona'],
        'experiencia-laboral' => ['model' => ExperienciaLaboral::class, 'label' => 'Experiencia laboral', 'group' => 'Trayectoria', 'field' => 'usuario_id', 'scope' => 'persona'],
        'colegiaturas' => ['model' => Colegiatura::class, 'label' => 'Colegiaturas', 'group' => 'Trayectoria', 'field' => 'usuario_id', 'scope' => 'persona'],
        'reconocimientos' => ['model' => Reconocimiento::class, 'label' => 'Reconocimientos', 'group' => 'Trayectoria', 'field' => 'usuario_id', 'scope' => 'persona'],
        'vinculos-laborales' => ['model' => VinculoLaboral::class, 'label' => 'Vínculos laborales', 'group' => 'Situación laboral', 'field' => 'usuario_id', 'scope' => 'persona'],
        'perfil-docente' => ['model' => PerfilDocente::class, 'label' => 'Perfil docente', 'group' => 'Situación laboral', 'field' => 'vinculo_laboral_id', 'scope' => 'vinculo'],
        'perfil-administrativo' => ['model' => PerfilAdministrativo::class, 'label' => 'Perfil administrativo', 'group' => 'Situación laboral', 'field' => 'vinculo_laboral_id', 'scope' => 'vinculo'],
        'vinculo-adendas' => ['model' => VinculoAdenda::class, 'label' => 'Adendas', 'group' => 'Situación laboral', 'field' => 'vinculo_laboral_id', 'scope' => 'vinculo'],
        'inducciones' => ['model' => Induccion::class, 'label' => 'Inducciones', 'group' => 'Situación laboral', 'field' => 'usuario_id', 'scope' => 'persona'],
        'periodos-prueba' => ['model' => PeriodoPrueba::class, 'label' => 'Períodos de prueba', 'group' => 'Situación laboral', 'field' => 'usuario_id', 'scope' => 'persona'],
        'progresiones-carrera' => ['model' => ProgresionCarrera::class, 'label' => 'Progresiones de carrera', 'group' => 'Situación laboral', 'field' => 'usuario_id', 'scope' => 'persona'],
        'ratificaciones-docentes' => ['model' => RatificacionDocente::class, 'label' => 'Ratificaciones docentes', 'group' => 'Situación laboral', 'field' => 'usuario_id', 'scope' => 'persona'],
        'desplazamientos' => ['model' => Desplazamiento::class, 'label' => 'Desplazamientos', 'group' => 'Situación laboral', 'field' => 'usuario_id', 'scope' => 'persona'],
        'desvinculaciones' => ['model' => Desvinculacion::class, 'label' => 'Desvinculaciones', 'group' => 'Situación laboral', 'field' => 'usuario_id', 'scope' => 'persona'],
        'tiempo-servicios-reconocidos' => ['model' => TiempoServicioReconocido::class, 'label' => 'Tiempo de servicio', 'group' => 'Situación laboral', 'field' => 'usuario_id', 'scope' => 'persona'],
        'compensaciones-beneficios' => ['model' => CompensacionBeneficio::class, 'label' => 'Compensaciones y beneficios', 'group' => 'Situación laboral', 'field' => 'usuario_id', 'scope' => 'persona'],
        'evaluaciones-desempeno' => ['model' => EvaluacionDesempeno::class, 'label' => 'Evaluaciones de desempeño', 'group' => 'Evaluaciones', 'field' => 'usuario_id', 'scope' => 'persona'],
        'licencias-permisos' => ['model' => LicenciaPermiso::class, 'label' => 'Licencias y permisos', 'group' => 'Ausencias', 'field' => 'usuario_id', 'scope' => 'persona'],
        'periodos-vacacionales' => ['model' => PeriodoVacacional::class, 'label' => 'Períodos vacacionales', 'group' => 'Ausencias', 'field' => 'usuario_id', 'scope' => 'persona'],
        'goces-vacacionales' => ['model' => GoceVacacional::class, 'label' => 'Goces vacacionales', 'group' => 'Ausencias', 'field' => 'usuario_id', 'scope' => 'persona'],
        'familiares' => ['model' => Familiar::class, 'label' => 'Familiares', 'group' => 'Datos complementarios', 'field' => 'usuario_id', 'scope' => 'persona'],
        'declaraciones-verificaciones' => ['model' => DeclaracionVerificacion::class, 'label' => 'Declaraciones y verificaciones', 'group' => 'Datos complementarios', 'field' => 'usuario_id', 'scope' => 'persona'],
        'afiliaciones' => ['model' => Afiliacion::class, 'label' => 'Afiliaciones', 'group' => 'Datos complementarios', 'field' => 'usuario_id', 'scope' => 'persona'],
        'certificados-discapacidad' => ['model' => CertificadoDiscapacidad::class, 'label' => 'Certificados de discapacidad', 'group' => 'Datos complementarios', 'field' => 'usuario_id', 'scope' => 'persona'],
        'registros-sst-bienestar' => ['model' => RegistroSstBienestar::class, 'label' => 'SST y bienestar', 'group' => 'Datos complementarios', 'field' => 'usuario_id', 'scope' => 'persona'],
        'sanciones' => ['model' => Sancion::class, 'label' => 'Sanciones', 'group' => 'Datos complementarios', 'field' => 'usuario_id', 'scope' => 'persona'],
        'controversias' => ['model' => Controversia::class, 'label' => 'Controversias', 'group' => 'Datos complementarios', 'field' => 'usuario_id', 'scope' => 'persona'],
        'requerimientos-documentos' => ['model' => RequerimientoDocumento::class, 'label' => 'Requerimientos', 'group' => 'Seguimiento', 'field' => 'usuario_id', 'scope' => 'persona'],
        'observaciones-documento' => ['model' => ObservacionDocumento::class, 'label' => 'Observaciones', 'group' => 'Seguimiento', 'field' => 'documento_id', 'scope' => 'documento'],
        'compromisos-entrega-originales' => ['model' => CompromisoEntregaOriginal::class, 'label' => 'Compromisos de entrega', 'group' => 'Seguimiento', 'field' => 'legajo_id', 'scope' => 'legajo'],
        'compromiso-documentos' => ['model' => CompromisoDocumento::class, 'label' => 'Documentos comprometidos', 'group' => 'Seguimiento', 'field' => 'compromiso_id', 'scope' => 'compromiso'],
        'procesos-fiscalizacion' => ['model' => ProcesoFiscalizacion::class, 'label' => 'Procesos de fiscalización', 'group' => 'Seguimiento', 'field' => 'legajo_id', 'scope' => 'legajo'],
        'solicitudes-fiscalizacion' => ['model' => SolicitudFiscalizacion::class, 'label' => 'Solicitudes de fiscalización', 'group' => 'Seguimiento', 'field' => 'proceso_fiscalizacion_id', 'scope' => 'proceso'],
        'historial-estados-documento' => ['model' => HistorialEstadoDocumento::class, 'label' => 'Historial documental', 'group' => 'Seguimiento', 'field' => 'documento_usuario_id', 'scope' => 'documento'],
        'notificaciones-usuario' => ['model' => NotificacionUsuario::class, 'label' => 'Notificaciones', 'group' => 'Seguimiento', 'field' => 'usuario_id', 'scope' => 'persona'],
        'referencias-legajo-pasivo' => ['model' => ReferenciaLegajoPasivo::class, 'label' => 'Referencias al archivo pasivo', 'group' => 'Custodia y acceso', 'field' => 'legajo_id', 'scope' => 'legajo'],
        'movimientos-custodia-legajo' => ['model' => MovimientoCustodiaLegajo::class, 'label' => 'Movimientos de custodia', 'group' => 'Custodia y acceso', 'field' => 'legajo_id', 'scope' => 'legajo'],
        'solicitudes-acceso-legajo' => ['model' => SolicitudAccesoLegajo::class, 'label' => 'Solicitudes de acceso', 'group' => 'Custodia y acceso', 'field' => 'legajo_id', 'scope' => 'legajo'],
        'bitacora-accesos-legajo' => ['model' => BitacoraAccesoLegajo::class, 'label' => 'Bitácora de accesos', 'group' => 'Custodia y acceso', 'field' => 'legajo_id', 'scope' => 'legajo'],
        'usuario-roles' => ['model' => UsuarioRol::class, 'label' => 'Roles asignados', 'group' => 'Custodia y acceso', 'field' => 'usuario_id', 'scope' => 'persona'],
        'auditoria-eventos' => ['model' => AuditoriaEvento::class, 'label' => 'Eventos de auditoría', 'group' => 'Custodia y acceso', 'field' => 'usuario_titular_id', 'scope' => 'persona'],
    ];

    /** Categorías de las secciones 01, 09 y 10: solo con datos_sensibles.ver. */
    private const SENSIBLES = ['familiares', 'declaraciones-verificaciones', 'certificados-discapacidad', 'afiliaciones', 'controversias', 'registros-sst-bienestar'];

    /** Secciones documentales que se ocultan sin datos_sensibles.ver. */
    private const SECCIONES_SENSIBLES = [1, 9, 10];

    /** Datos de la ficha general (sección 01) que se ocultan sin datos_sensibles.ver. */
    private const DATOS_PERSONALES_SENSIBLES = ['fecha_nacimiento', 'nacionalidad', 'estado_civil', 'domicilio', 'region', 'provincia', 'distrito', 'telefono', 'con_discapacidad', 'tiene_derechohabientes'];

    private const RELATIONS = [
        'Legajo' => ['usuario_id' => 'usuario'],
        'LegajoSeccion' => ['legajo_id' => 'legajo', 'seccion_legajo_id' => 'seccionLegajo'],
        'DocumentoUsuario' => ['usuario_id' => 'usuario', 'legajo_id' => 'legajo', 'tipo_documento_id' => 'tipoDocumento', 'seccion_legajo_id' => 'seccionLegajo', 'vinculo_laboral_id' => 'vinculoLaboral', 'archivo_id' => 'archivo', 'recepcionado_por' => 'recepcionadoPor', 'fedatario_id' => 'fedatario', 'fiscalizado_por' => 'fiscalizadoPor', 'subsana_a_documento_id' => 'subsanaADocumento', 'created_by' => 'createdBy'],
        'ArchivoDocumento' => ['subido_por' => 'subidoPor'],
        'TipoDocumento' => ['seccion_legajo_id' => 'seccionLegajo', 'subseccion_legajo_id' => 'subseccionLegajo'],
        'FormacionAcademica' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'Capacitacion' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'ExperienciaLaboral' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'Colegiatura' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'Reconocimiento' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'VinculoLaboral' => ['usuario_id' => 'usuario', 'regimen_normativo_id' => 'regimenNormativo', 'unidad_organica_id' => 'unidadOrganica'],
        'PerfilDocente' => ['vinculo_laboral_id' => 'vinculoLaboral'],
        'PerfilAdministrativo' => ['vinculo_laboral_id' => 'vinculoLaboral'],
        'VinculoAdenda' => ['vinculo_laboral_id' => 'vinculoLaboral', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'Induccion' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'vinculo_laboral_id' => 'vinculoLaboral', 'registrado_por' => 'registradoPor'],
        'PeriodoPrueba' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'vinculo_laboral_id' => 'vinculoLaboral', 'registrado_por' => 'registradoPor'],
        'ProgresionCarrera' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'vinculo_laboral_id' => 'vinculoLaboral', 'registrado_por' => 'registradoPor'],
        'RatificacionDocente' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'perfil_docente_id' => 'perfilDocente', 'registrado_por' => 'registradoPor'],
        'Desplazamiento' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'unidad_origen_id' => 'unidadOrigen', 'unidad_destino_id' => 'unidadDestino', 'registrado_por' => 'registradoPor'],
        'Desvinculacion' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'vinculo_laboral_id' => 'vinculoLaboral', 'registrado_por' => 'registradoPor'],
        'TiempoServicioReconocido' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'CompensacionBeneficio' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'EvaluacionDesempeno' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'evaluador_id' => 'evaluador', 'registrado_por' => 'registradoPor'],
        'LicenciaPermiso' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'PeriodoVacacional' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'vinculo_laboral_id' => 'vinculoLaboral', 'registrado_por' => 'registradoPor'],
        'GoceVacacional' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'periodo_vacacional_id' => 'periodoVacacional', 'registrado_por' => 'registradoPor'],
        'Familiar' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'DeclaracionVerificacion' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'Afiliacion' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'CertificadoDiscapacidad' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'familiar_id' => 'familiar', 'registrado_por' => 'registradoPor'],
        'RegistroSstBienestar' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'Sancion' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'Controversia' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'registrado_por' => 'registradoPor'],
        'RequerimientoDocumento' => ['usuario_id' => 'usuario', 'tipo_documento_id' => 'tipoDocumento', 'documento_id' => 'documento', 'solicitado_por' => 'solicitadoPor'],
        'ObservacionDocumento' => ['documento_id' => 'documento', 'registrada_por' => 'registradaPor', 'documento_subsanacion_id' => 'documentoSubsanacion', 'verificada_por' => 'verificadaPor'],
        'CompromisoEntregaOriginal' => ['usuario_id' => 'usuario', 'legajo_id' => 'legajo', 'archivo_id' => 'archivo', 'registrado_por' => 'registradoPor'],
        'CompromisoDocumento' => ['compromiso_id' => 'compromiso', 'tipo_documento_id' => 'tipoDocumento', 'documento_id' => 'documento'],
        'ProcesoFiscalizacion' => ['usuario_id' => 'usuario', 'legajo_id' => 'legajo', 'responsable_id' => 'responsable', 'informe_documento_id' => 'informeDocumento'],
        'SolicitudFiscalizacion' => ['proceso_fiscalizacion_id' => 'procesoFiscalizacion', 'documento_id' => 'documento', 'oficio_archivo_id' => 'oficioArchivo', 'documento_respuesta_id' => 'documentoRespuesta', 'registrado_por' => 'registradoPor'],
        'HistorialEstadoDocumento' => ['documento_usuario_id' => 'documentoUsuario', 'cambiado_por' => 'cambiadoPor', 'observacion_id' => 'observacion'],
        'NotificacionUsuario' => ['usuario_id' => 'usuario', 'documento_id' => 'documento', 'observacion_id' => 'observacion', 'requerimiento_id' => 'requerimiento'],
        'ReferenciaLegajoPasivo' => ['legajo_id' => 'legajo', 'vinculo_laboral_id' => 'vinculoLaboral', 'tipo_documento_id' => 'tipoDocumento', 'responsable_legajo_id' => 'responsableLegajo'],
        'MovimientoCustodiaLegajo' => ['legajo_id' => 'legajo', 'autorizado_por' => 'autorizadoPor', 'sustento_archivo_id' => 'sustentoArchivo'],
        'SolicitudAccesoLegajo' => ['legajo_id' => 'legajo', 'solicitante_usuario_id' => 'solicitanteUsuario', 'resuelta_por' => 'resueltaPor'],
        'BitacoraAccesoLegajo' => ['legajo_id' => 'legajo', 'documento_id' => 'documento', 'usuario_id' => 'usuario', 'solicitud_acceso_id' => 'solicitudAcceso'],
        'UsuarioRol' => ['usuario_id' => 'usuario', 'rol_id' => 'rol', 'unidad_organica_id' => 'unidadOrganica', 'asignado_por' => 'asignadoPor'],
        'AuditoriaEvento' => ['actor_usuario_id' => 'actorUsuario', 'usuario_titular_id' => 'usuarioTitular'],
        'Usuario' => ['regimen_normativo_id' => 'regimenNormativo', 'unidad_organica_id' => 'unidadOrganica', 'ubigeo_id' => 'ubigeo'],
    ];

    private const OMIT = ['password', 'remember_token', 'ultimo_acceso', 'storage_path', 'bucket', 'hash_sha256', 'hash_previo', 'hash_registro', 'datos_antes', 'datos_despues', 'ip_origen', 'user_agent', 'request_id'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:120',
            'tipo' => 'nullable|in:docente,administrativo',
            'legajo' => 'nullable|in:con,sin',
            'page' => 'sometimes|integer|min:1',
        ]);
        $query = Usuario::query()->select(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'numero_doc_identidad', 'tipo_personal', 'nombre_puesto', 'unidad_organica_id'])
            ->with('unidadOrganica:id,nombre')->withCount(['legajos', 'documentosUsuarioPorUsuario']);
        if (! empty($filters['q'])) {
            $search = '%'.mb_strtolower(trim($filters['q'])).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->whereRaw("LOWER(nombres || ' ' || apellido_paterno || ' ' || COALESCE(apellido_materno, '')) LIKE ?", [$search])
                    ->orWhere('numero_doc_identidad', 'like', $search)
                    ->orWhereHas('legajos', fn (Builder $legajos): Builder => $legajos->whereRaw('LOWER(numero_legajo) LIKE ?', [$search]));
            });
        }
        if (! empty($filters['tipo'])) {
            $query->where('tipo_personal', $filters['tipo']);
        }
        if (! empty($filters['legajo'])) {
            $filters['legajo'] === 'con' ? $query->has('legajos') : $query->doesntHave('legajos');
        }

        return response()->json($query->orderBy('apellido_paterno')->orderBy('nombres')->orderBy('id')->paginate(20)->withQueryString());
    }

    public function show(Request $request, string $usuario): JsonResponse
    {
        $person = Usuario::query()->with(['unidadOrganica', 'regimenNormativo', 'ubigeo'])->findOrFail($usuario);
        $veSensibles = $request->user()->can('datos_sensibles.ver');
        $presented = $this->present($person);
        if (! $veSensibles) {
            $presented['values'] = array_diff_key($presented['values'], array_flip(self::DATOS_PERSONALES_SENSIBLES));
        }

        return response()->json(['data' => [
            'version' => $this->version($person),
            'datos_sensibles_ocultos' => ! $veSensibles,
            'usuario' => $presented,
            'edit_values' => $person->only(['unidad_organica_id', 'regimen_normativo_id']),
            'nombre' => trim($person->nombres.' '.$person->apellido_paterno.' '.$person->apellido_materno),
            // La subsección 2.4 (período de prueba) solo se muestra si la persona lo tiene.
            'tiene_periodo_prueba' => PerfilDocumental::de($person, $person->vinculosLaborales()->get())->tienePeriodoPrueba(),
            'document_sections' => SeccionLegajo::query()->orderBy('orden')->get(['id', 'nombre']),
            'document_states' => DocumentoUsuario::query()->where('usuario_id', $usuario)->whereHas('legajo', fn (Builder $query): Builder => $query->where('usuario_id', $usuario))->distinct()->orderBy('estado')->pluck('estado'),
            'legajos' => $person->legajos()->orderByDesc('fecha_apertura')->get()->map(fn (Model $row): array => $this->present($row)),
            'sections' => collect(self::SECTIONS)->map(fn (array $section, string $key): array => [
                'key' => $key, 'label' => $section['label'], 'group' => $section['group'],
                'scope' => in_array($section['scope'], ['persona', 'vinculo'], true) ? 'persona' : ($section['scope'] === 'catalogo' ? 'catalogo' : 'legajo'),
            ])->values(),
        ]]);
    }

    public function update(UpdateExpedienteRequest $request, string $usuario): JsonResponse
    {
        $person = Usuario::query()->findOrFail($usuario);
        $data = $request->validated();
        if (isset($data['email_institucional'])) {
            $data['email_institucional'] = mb_strtolower($data['email_institucional']);
            if (Usuario::query()->whereKeyNot($usuario)->whereRaw('LOWER(email_institucional) = ?', [$data['email_institucional']])->exists()) {
                throw ValidationException::withMessages(['email_institucional' => 'Este correo ya está registrado.']);
            }
        }
        try {
            DB::transaction(function () use ($person, $data, $request): void {
                $person = Usuario::query()->lockForUpdate()->findOrFail($person->id);
                if ($request->hasHeader('If-Match') && ! hash_equals($this->version($person), $request->header('If-Match'))) {
                    abort(409, 'Los datos cambiaron mientras editabas. Cancela y vuelve a editar la ficha actualizada.');
                }
                if (array_key_exists('ubicacion', $data)) {
                    $location = $data['ubicacion'];
                    unset($data['ubicacion']);
                    $data['ubigeo_id'] = null;
                    if ($location !== null) {
                        $existing = Ubigeo::query();
                        foreach ($location as $field => $value) {
                            $location[$field] = mb_strtoupper(trim($value));
                            $existing->whereRaw('LOWER('.$field.') = ?', [mb_strtolower(trim($value))]);
                        }
                        $matched = $existing->first();
                        if (! $matched) {
                            throw ValidationException::withMessages(['ubicacion.distrito' => 'Selecciona una región, provincia y distrito del catálogo.']);
                        }
                        $data['ubigeo_id'] = $matched->id;
                    }
                }
                $person->update($data);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['usuario' => 'El documento de identidad o correo ya está registrado.']);
        }

        return $this->show($request, $usuario);
    }

    private function version(Usuario $person): string
    {
        return hash('sha256', json_encode($person->only(array_diff($person->getFillable(), ['password', 'ultimo_acceso'])), JSON_THROW_ON_ERROR));
    }

    public function editOptions(): JsonResponse
    {
        return response()->json(['data' => [
            'unidades' => UnidadOrganica::query()->orderBy('nombre')->get(['id', 'nombre', 'activo']),
            'regimenes' => RegimenNormativo::query()->orderBy('nombre')->get(['id', 'nombre', 'activo']),
        ]]);
    }

    public function locations(Request $request): JsonResponse
    {
        $filters = $request->validate(['region' => 'nullable|string|max:100|required_with:provincia', 'provincia' => 'nullable|string|max:100']);
        $query = Ubigeo::query();
        if (! empty($filters['region'])) {
            $query->where('region', $filters['region']);
        }
        if (! empty($filters['provincia'])) {
            $query->where('provincia', $filters['provincia']);
        }
        $column = ! empty($filters['region']) ? (! empty($filters['provincia']) ? 'distrito' : 'provincia') : 'region';

        return response()->json(['data' => $query->distinct()->orderBy($column)->pluck($column)]);
    }

    public function section(Request $request, string $usuario, string $section): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        abort_unless(isset(self::SECTIONS[$section]), 404);
        $veSensibles = $request->user()->can('datos_sensibles.ver');
        abort_if(in_array($section, self::SENSIBLES, true) && ! $veSensibles, 403, 'Esta sección contiene datos sensibles.');
        $filters = $request->validate([
            'legajo_id' => 'nullable|uuid', 'page' => 'sometimes|integer|min:1',
            'seccion_id' => 'nullable|integer|exists:secciones_legajo,id',
            'estado' => 'nullable|string|max:20',
        ]);
        $legajos = Legajo::query()->where('usuario_id', $usuario);
        if (! empty($filters['legajo_id'])) {
            $legajos->whereKey($filters['legajo_id']);
            abort_unless((clone $legajos)->exists(), 404);
        }
        $documents = DocumentoUsuario::query()->where('usuario_id', $usuario)->whereIn('legajo_id', (clone $legajos)->select('id'));
        if (! $veSensibles) {
            $documents->whereNotIn('seccion_legajo_id', self::SECCIONES_SENSIBLES)
                ->whereHas('tipoDocumento', fn ($tipo) => $tipo->where('es_dato_sensible', false)->where('es_dato_salud', false));
        }
        $config = self::SECTIONS[$section];
        $query = $config['model']::query();
        $ids = match ($config['scope']) {
            'legajo' => (clone $legajos)->select('id'),
            'documento' => (clone $documents)->select('id'),
            'vinculo' => VinculoLaboral::query()->where('usuario_id', $usuario)->select('id'),
            'compromiso' => CompromisoEntregaOriginal::query()->where('usuario_id', $usuario)->whereIn('legajo_id', (clone $legajos)->select('id'))->select('id'),
            'proceso' => ProcesoFiscalizacion::query()->where('usuario_id', $usuario)->whereIn('legajo_id', (clone $legajos)->select('id'))->select('id'),
            'archivo' => (clone $documents)->whereNotNull('archivo_id')->select('archivo_id')
                ->union(CompromisoEntregaOriginal::query()->where('usuario_id', $usuario)->whereIn('legajo_id', (clone $legajos)->select('id'))->whereNotNull('archivo_id')->select('archivo_id'))
                ->union(MovimientoCustodiaLegajo::query()->whereIn('legajo_id', (clone $legajos)->select('id'))->whereNotNull('sustento_archivo_id')->select('sustento_archivo_id'))
                ->union(SolicitudFiscalizacion::query()->whereIn('proceso_fiscalizacion_id', ProcesoFiscalizacion::query()->where('usuario_id', $usuario)->whereIn('legajo_id', (clone $legajos)->select('id'))->select('id'))->whereNotNull('oficio_archivo_id')->select('oficio_archivo_id')),
            default => null,
        };
        if ($ids !== null) {
            $query->whereIn($config['field'], $ids);
        } elseif ($config['scope'] === 'persona') {
            $query->where($config['field'], $usuario);
        }
        if ($config['scope'] === 'legajo' && in_array('usuario_id', $query->getModel()->getFillable(), true) && $section !== 'bitacora-accesos-legajo') {
            $query->where('usuario_id', $usuario);
        }
        if (! $veSensibles && in_array($section, ['formacion-academica', 'colegiaturas', 'capacitaciones', 'tiempo-servicios-reconocidos'], true)) {
            $query->where(fn ($datos) => $datos->whereNull('documento_id')->orWhereHas('documento',
                fn ($doc) => $doc->whereNotIn('seccion_legajo_id', self::SECCIONES_SENSIBLES)
                    ->whereHas('tipoDocumento', fn ($tipo) => $tipo->where('es_dato_sensible', false)->where('es_dato_salud', false))));
        }
        if (! $veSensibles && $section === 'compensaciones-beneficios') {
            $query->whereHas('documento', fn ($doc) => $doc->whereNotIn('seccion_legajo_id', self::SECCIONES_SENSIBLES)
                ->whereHas('tipoDocumento', fn ($tipo) => $tipo->where('es_dato_sensible', false)->where('es_dato_salud', false)));
        }
        if (in_array($section, ['compensaciones-beneficios', 'tiempo-servicios-reconocidos'], true)) {
            $query->where(fn ($datos) => $datos->whereNull('documento_id')->orWhereHas('documento',
                fn ($doc) => $doc->where('seccion_legajo_id', '!=', 6)->orWhere(fn ($actual) => $actual->where('estado', '!=', 'anulado')
                    ->whereDoesntHave('documentosUsuarioPorSubsanaADocumento'))));
        }
        // Vínculos y adendas anulados no salen en los listados (se ven aparte, en la sección 02).
        if (in_array($section, ['vinculos-laborales', 'vinculo-adendas'], true)) {
            $query->where('anulado', false);
        }
        if ($section === 'documentos-usuario') {
            if (! $veSensibles) {
                $query->whereNotIn('seccion_legajo_id', self::SECCIONES_SENSIBLES)
                    ->whereHas('tipoDocumento', fn ($tipo) => $tipo->where('es_dato_sensible', false)->where('es_dato_salud', false));
            }
            if (! empty($filters['seccion_id'])) {
                $query->where('seccion_legajo_id', $filters['seccion_id']);
            }
            if (! empty($filters['estado'])) {
                $query->where('estado', $filters['estado']);
            }
        }
        $relations = self::RELATIONS[class_basename($query->getModel())] ?? [];
        foreach ($relations as $relation) {
            $related = $query->getModel()->{$relation}()->getRelated();
            $columns = array_values(array_intersect(['id', 'nombre', 'apellidos_nombres', 'nombres', 'apellido_paterno', 'apellido_materno', 'numero_legajo', 'nombre_original', 'codigo', 'descripcion', 'numero_documento_vinculo', 'nombre_puesto', 'fecha_inicio', 'tipo', 'tipo_documento_id', 'num_resolucion'], array_merge(['id'], $related->getFillable())));
            $query->with($relation.':'.implode(',', $columns));
            if ($related instanceof DocumentoUsuario) {
                $query->with($relation.'.tipoDocumento:id,nombre');
            }
        }
        $page = $query->orderBy('id')->paginate(20)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn (Model $row): array => $this->present($row)));

        return response()->json($page);
    }

    private function present(Model $record): array
    {
        $values = [];
        $relations = self::RELATIONS[class_basename($record)] ?? [];
        foreach ($record->getFillable() as $field) {
            if ($field === 'id' || in_array($field, self::OMIT, true)) {
                continue;
            }
            if ($record instanceof Usuario && $field === 'ubigeo_id') {
                foreach (['region', 'provincia', 'distrito'] as $part) {
                    $values[$part] = $record->ubigeo?->{$part};
                }

                continue;
            }
            if (isset($relations[$field])) {
                $relation = $relations[$field];
                $related = $record->relationLoaded($relation) ? $record->getRelation($relation) : null;
                $values[$field] = $related ? $this->relationLabel($related) : null;
            } else {
                $value = $record->getAttribute($field);
                $values[$field] = $value instanceof \DateTimeInterface ? $value->format(str_contains($record->getCasts()[$field] ?? '', 'datetime') ? 'Y-m-d H:i:s' : 'Y-m-d') : $value;
            }
        }

        if ($record instanceof DeclaracionVerificacion) {
            $values['pdf_nombre'] = $record->pdf_nombre;
        }
        if ($record instanceof CertificadoDiscapacidad) {
            $values['pdf_nombre'] = $record->pdf_nombre;

            return ['id' => $record->getKey(), 'values' => $values, 'familiar_id' => $record->familiar_id];
        }

        return ['id' => $record->getKey(), 'values' => $values];
    }

    private function relationLabel(Model $record): string
    {
        if ($record instanceof Usuario) {
            return trim($record->nombres.' '.$record->apellido_paterno.' '.$record->apellido_materno);
        }
        if ($record instanceof DocumentoUsuario) {
            return trim(($record->tipoDocumento?->nombre ?? 'Documento').' '.($record->num_resolucion ?? ''));
        }
        if ($record instanceof Ubigeo) {
            return implode(' / ', array_filter([$record->region, $record->provincia, $record->distrito]));
        }
        if ($record instanceof VinculoLaboral) {
            return trim(($record->nombre_puesto ?? 'Vínculo laboral').' '.($record->numero_documento_vinculo ?? ''));
        }
        foreach (['nombre', 'apellidos_nombres', 'numero_legajo', 'nombre_original', 'numero_documento_vinculo', 'codigo', 'descripcion', 'tipo'] as $field) {
            if ($record->getAttribute($field)) {
                return (string) $record->getAttribute($field);
            }
        }

        return 'Registro relacionado';
    }
}
