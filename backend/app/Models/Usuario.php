<?php

namespace App\Models;

use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property bool|null $exige_estudios_basicos columna agregada en 2026_10_05_025339 (Sí, No o sin responder)
 */
class Usuario extends Authenticatable
{
    // Solo para Sanctum en modo SPA (cookie). No hay tabla de tokens: no usar createToken().
    use HasApiTokens;

    /** @use HasFactory<UsuarioFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;

    protected $hidden = ['password'];

    protected $rememberTokenName = '';

    protected $table = 'usuarios';

    protected $fillable = [
        'foto_path',
        'tipo_doc_identidad',
        'numero_doc_identidad',
        'apellido_paterno',
        'apellido_materno',
        'nombres',
        'fecha_nacimiento',
        'nacionalidad',
        'estado_civil',
        'domicilio',
        'ubigeo_id',
        'telefono',
        'email_institucional',
        'password',
        'tipo_personal',
        'regimen_laboral',
        'regimen_normativo_id',
        'unidad_organica_id',
        'nombre_puesto',
        'obligado_djbr',
        'exige_colegiatura',
        'exige_estudios_basicos',
        'tiene_periodo_prueba',
        'tiene_derechohabientes',
        'con_discapacidad',
        'activo',
        'ultimo_acceso',
        'contacto_emergencia_nombre',
        'contacto_emergencia_parentesco',
        'contacto_emergencia_telefono',
        'regimen_pensionario',
        'afp_nombre',
        'cuspp',
        'fecha_afiliacion_pension',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_afiliacion_pension' => 'date',
            'fecha_nacimiento' => 'date',
            'password' => 'hashed',
            'obligado_djbr' => 'boolean',
            'exige_colegiatura' => 'boolean',
            'exige_estudios_basicos' => 'boolean',
            'tiene_periodo_prueba' => 'boolean',
            'tiene_derechohabientes' => 'boolean',
            'con_discapacidad' => 'boolean',
            'activo' => 'boolean',
            'debe_cambiar_password' => 'boolean',
            'ultimo_acceso' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @var array<int, string>|null Permisos calculados una sola vez por petición. */
    private ?array $permisosCalculados = null;

    /** @var Collection<int, Rol>|null */
    private ?Collection $rolesVigentesCalculados = null;

    /** @return Collection<int, Rol> Roles activos con asignación vigente hoy. */
    public function rolesVigentes(): Collection
    {
        if ($this->rolesVigentesCalculados !== null) {
            return $this->rolesVigentesCalculados;
        }
        $hoy = today()->toDateString();

        return $this->rolesVigentesCalculados = Rol::query()
            ->where('activo', true)
            ->whereIn('id', $this->usuarioRolesPorUsuario()
                ->whereNull('revocado_en')
                ->whereDate('vigente_desde', '<=', $hoy)
                ->where(fn (Builder $query): Builder => $query->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $hoy))
                ->select('rol_id'))
            ->get();
    }

    /** @return array<int, string> Permisos del catálogo que dan sus roles vigentes. */
    public function permisos(): array
    {
        if ($this->permisosCalculados !== null) {
            return $this->permisosCalculados;
        }
        if (! $this->activo) {
            return $this->permisosCalculados = [];
        }
        $catalogo = array_keys(config('permisos.catalogo'));
        $derivados = config('permisos.derivados');
        $asignables = array_diff($catalogo, array_keys($derivados), config('permisos.pendientes'));
        // Los explícitos se asignan solo nombrándolos en la lista de un rol, nunca por '*'. Un rol puede combinar
        // el comodín con explícitos: ['*', 'configuracion.modulos'].
        $comodin = array_diff($asignables, config('permisos.explicitos', []));
        $permisos = [];
        foreach ($this->rolesVigentes() as $rol) {
            if (in_array($rol->codigo, config('permisos.deshabilitados', []), true)) {
                continue;
            }
            $delRol = (array) config('permisos.roles.'.$rol->codigo, []);
            $permisos = [...$permisos, ...(in_array('*', $delRol, true) ? $comodin : []), ...array_intersect($delRol, $asignables)];
            foreach ($derivados as $permiso => $columna) {
                if ($rol->{$columna}) {
                    $permisos[] = $permiso;
                }
            }
        }

        return $this->permisosCalculados = array_values(array_unique($permisos));
    }

    public function tienePermiso(string $permiso): bool
    {
        return in_array($permiso, $this->permisos(), true);
    }

    public function veDatosSensibles(): bool
    {
        return $this->tienePermiso('datos_sensibles.ver');
    }

    public function isAdministrator(): bool
    {
        return $this->tienePermiso('sistema.administrar');
    }

    /** @return BelongsTo<RegimenNormativo, $this> */
    public function regimenNormativo(): BelongsTo
    {
        return $this->belongsTo(RegimenNormativo::class, 'regimen_normativo_id', 'id');
    }

    /** @return BelongsTo<UnidadOrganica, $this> */
    public function unidadOrganica(): BelongsTo
    {
        return $this->belongsTo(UnidadOrganica::class, 'unidad_organica_id', 'id');
    }

    /** @return BelongsTo<Ubigeo, $this> */
    public function ubigeo(): BelongsTo
    {
        return $this->belongsTo(Ubigeo::class, 'ubigeo_id', 'id');
    }

    /** @return HasMany<UsuarioRol, $this> */
    public function usuarioRolesPorUsuario(): HasMany
    {
        return $this->hasMany(UsuarioRol::class, 'usuario_id', 'id');
    }

    /** @return HasMany<UsuarioRol, $this> */
    public function usuarioRolesPorAsignadoPor(): HasMany
    {
        return $this->hasMany(UsuarioRol::class, 'asignado_por', 'id');
    }

    /** @return HasMany<VinculoLaboral, $this> */
    public function vinculosLaborales(): HasMany
    {
        return $this->hasMany(VinculoLaboral::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Legajo, $this> */
    public function legajos(): HasMany
    {
        return $this->hasMany(Legajo::class, 'usuario_id', 'id');
    }

    /** @return HasMany<ArchivoDocumento, $this> */
    public function archivosDocumento(): HasMany
    {
        return $this->hasMany(ArchivoDocumento::class, 'subido_por', 'id');
    }

    /** @return HasMany<AuditoriaEvento, $this> */
    public function auditoriaEventosPorActorUsuario(): HasMany
    {
        return $this->hasMany(AuditoriaEvento::class, 'actor_usuario_id', 'id');
    }

    /** @return HasMany<AuditoriaEvento, $this> */
    public function auditoriaEventosPorUsuarioTitular(): HasMany
    {
        return $this->hasMany(AuditoriaEvento::class, 'usuario_titular_id', 'id');
    }

    /** @return HasMany<ParametroSistema, $this> */
    public function parametrosSistema(): HasMany
    {
        return $this->hasMany(ParametroSistema::class, 'actualizado_por', 'id');
    }

    /** @return HasMany<DocumentoUsuario, $this> */
    public function documentosUsuarioPorUsuario(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'usuario_id', 'id');
    }

    /** @return HasMany<DocumentoUsuario, $this> */
    public function documentosUsuarioPorRecepcionadoPor(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'recepcionado_por', 'id');
    }

    /** @return HasMany<DocumentoUsuario, $this> */
    public function documentosUsuarioPorFedatario(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'fedatario_id', 'id');
    }

    /** @return HasMany<DocumentoUsuario, $this> */
    public function documentosUsuarioPorFiscalizadoPor(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'fiscalizado_por', 'id');
    }

    /** @return HasMany<DocumentoUsuario, $this> */
    public function documentosUsuarioPorCreatedBy(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'created_by', 'id');
    }

    /** @return HasMany<CompromisoEntregaOriginal, $this> */
    public function compromisosEntregaOriginalesPorUsuario(): HasMany
    {
        return $this->hasMany(CompromisoEntregaOriginal::class, 'usuario_id', 'id');
    }

    /** @return HasMany<CompromisoEntregaOriginal, $this> */
    public function compromisosEntregaOriginalesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(CompromisoEntregaOriginal::class, 'registrado_por', 'id');
    }

    /** @return HasMany<ReferenciaLegajoPasivo, $this> */
    public function referenciasLegajoPasivo(): HasMany
    {
        return $this->hasMany(ReferenciaLegajoPasivo::class, 'responsable_legajo_id', 'id');
    }

    /** @return HasMany<SolicitudAccesoLegajo, $this> */
    public function solicitudesAccesoLegajoPorSolicitanteUsuario(): HasMany
    {
        return $this->hasMany(SolicitudAccesoLegajo::class, 'solicitante_usuario_id', 'id');
    }

    /** @return HasMany<SolicitudAccesoLegajo, $this> */
    public function solicitudesAccesoLegajoPorResueltaPor(): HasMany
    {
        return $this->hasMany(SolicitudAccesoLegajo::class, 'resuelta_por', 'id');
    }

    /** @return HasMany<MovimientoCustodiaLegajo, $this> */
    public function movimientosCustodiaLegajo(): HasMany
    {
        return $this->hasMany(MovimientoCustodiaLegajo::class, 'autorizado_por', 'id');
    }

    /** @return HasMany<VinculoAdenda, $this> */
    public function vinculoAdendas(): HasMany
    {
        return $this->hasMany(VinculoAdenda::class, 'registrado_por', 'id');
    }

    /** @return HasMany<Familiar, $this> */
    public function familiaresPorUsuario(): HasMany
    {
        return $this->hasMany(Familiar::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Familiar, $this> */
    public function familiaresPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Familiar::class, 'registrado_por', 'id');
    }

    /** @return HasMany<DeclaracionVerificacion, $this> */
    public function declaracionesVerificacionesPorUsuario(): HasMany
    {
        return $this->hasMany(DeclaracionVerificacion::class, 'usuario_id', 'id');
    }

    /** @return HasMany<DeclaracionVerificacion, $this> */
    public function declaracionesVerificacionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(DeclaracionVerificacion::class, 'registrado_por', 'id');
    }

    /** @return HasMany<Induccion, $this> */
    public function induccionesPorUsuario(): HasMany
    {
        return $this->hasMany(Induccion::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Induccion, $this> */
    public function induccionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Induccion::class, 'registrado_por', 'id');
    }

    /** @return HasMany<PeriodoPrueba, $this> */
    public function periodosPruebaPorUsuario(): HasMany
    {
        return $this->hasMany(PeriodoPrueba::class, 'usuario_id', 'id');
    }

    /** @return HasMany<PeriodoPrueba, $this> */
    public function periodosPruebaPorRegistradoPor(): HasMany
    {
        return $this->hasMany(PeriodoPrueba::class, 'registrado_por', 'id');
    }

    /** @return HasMany<FormacionAcademica, $this> */
    public function formacionAcademicaPorUsuario(): HasMany
    {
        return $this->hasMany(FormacionAcademica::class, 'usuario_id', 'id');
    }

    /** @return HasMany<FormacionAcademica, $this> */
    public function formacionAcademicaPorRegistradoPor(): HasMany
    {
        return $this->hasMany(FormacionAcademica::class, 'registrado_por', 'id');
    }

    /** @return HasMany<Colegiatura, $this> */
    public function colegiaturasPorUsuario(): HasMany
    {
        return $this->hasMany(Colegiatura::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Colegiatura, $this> */
    public function colegiaturasPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Colegiatura::class, 'registrado_por', 'id');
    }

    /** @return HasMany<Capacitacion, $this> */
    public function capacitacionesPorUsuario(): HasMany
    {
        return $this->hasMany(Capacitacion::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Idioma, $this> */
    public function idiomasPorUsuario(): HasMany
    {
        return $this->hasMany(Idioma::class, 'usuario_id', 'id');
    }

    /** @return HasMany<ProduccionIntelectual, $this> */
    public function produccionesIntelectualesPorUsuario(): HasMany
    {
        return $this->hasMany(ProduccionIntelectual::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Capacitacion, $this> */
    public function capacitacionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Capacitacion::class, 'registrado_por', 'id');
    }

    /** @return HasMany<ExperienciaLaboral, $this> */
    public function experienciaLaboralPorUsuario(): HasMany
    {
        return $this->hasMany(ExperienciaLaboral::class, 'usuario_id', 'id');
    }

    /** @return HasMany<ExperienciaLaboral, $this> */
    public function experienciaLaboralPorRegistradoPor(): HasMany
    {
        return $this->hasMany(ExperienciaLaboral::class, 'registrado_por', 'id');
    }

    /** @return HasMany<LicenciaPermiso, $this> */
    public function licenciasPermisosPorUsuario(): HasMany
    {
        return $this->hasMany(LicenciaPermiso::class, 'usuario_id', 'id');
    }

    /** @return HasMany<LicenciaPermiso, $this> */
    public function licenciasPermisosPorRegistradoPor(): HasMany
    {
        return $this->hasMany(LicenciaPermiso::class, 'registrado_por', 'id');
    }

    /** @return HasMany<PeriodoVacacional, $this> */
    public function periodosVacacionalesPorUsuario(): HasMany
    {
        return $this->hasMany(PeriodoVacacional::class, 'usuario_id', 'id');
    }

    /** @return HasMany<PeriodoVacacional, $this> */
    public function periodosVacacionalesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(PeriodoVacacional::class, 'registrado_por', 'id');
    }

    /** @return HasMany<TiempoServicioReconocido, $this> */
    public function tiempoServiciosReconocidosPorUsuario(): HasMany
    {
        return $this->hasMany(TiempoServicioReconocido::class, 'usuario_id', 'id');
    }

    /** @return HasMany<TiempoServicioReconocido, $this> */
    public function tiempoServiciosReconocidosPorRegistradoPor(): HasMany
    {
        return $this->hasMany(TiempoServicioReconocido::class, 'registrado_por', 'id');
    }

    /** @return HasMany<CompensacionBeneficio, $this> */
    public function compensacionesBeneficiosPorUsuario(): HasMany
    {
        return $this->hasMany(CompensacionBeneficio::class, 'usuario_id', 'id');
    }

    /** @return HasMany<CompensacionBeneficio, $this> */
    public function compensacionesBeneficiosPorRegistradoPor(): HasMany
    {
        return $this->hasMany(CompensacionBeneficio::class, 'registrado_por', 'id');
    }

    /** @return HasMany<EvaluacionDesempeno, $this> */
    public function evaluacionesDesempenoPorUsuario(): HasMany
    {
        return $this->hasMany(EvaluacionDesempeno::class, 'usuario_id', 'id');
    }

    /** @return HasMany<EvaluacionDesempeno, $this> */
    public function evaluacionesDesempenoPorEvaluador(): HasMany
    {
        return $this->hasMany(EvaluacionDesempeno::class, 'evaluador_id', 'id');
    }

    /** @return HasMany<EvaluacionDesempeno, $this> */
    public function evaluacionesDesempenoPorRegistradoPor(): HasMany
    {
        return $this->hasMany(EvaluacionDesempeno::class, 'registrado_por', 'id');
    }

    /** @return HasMany<Desplazamiento, $this> */
    public function desplazamientosPorUsuario(): HasMany
    {
        return $this->hasMany(Desplazamiento::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Desplazamiento, $this> */
    public function desplazamientosPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Desplazamiento::class, 'registrado_por', 'id');
    }

    /** @return HasMany<ProgresionCarrera, $this> */
    public function progresionesCarreraPorUsuario(): HasMany
    {
        return $this->hasMany(ProgresionCarrera::class, 'usuario_id', 'id');
    }

    /** @return HasMany<ProgresionCarrera, $this> */
    public function progresionesCarreraPorRegistradoPor(): HasMany
    {
        return $this->hasMany(ProgresionCarrera::class, 'registrado_por', 'id');
    }

    /** @return HasMany<RatificacionDocente, $this> */
    public function ratificacionesDocentesPorUsuario(): HasMany
    {
        return $this->hasMany(RatificacionDocente::class, 'usuario_id', 'id');
    }

    /** @return HasMany<RatificacionDocente, $this> */
    public function ratificacionesDocentesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(RatificacionDocente::class, 'registrado_por', 'id');
    }

    /** @return HasMany<Reconocimiento, $this> */
    public function reconocimientosPorUsuario(): HasMany
    {
        return $this->hasMany(Reconocimiento::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Reconocimiento, $this> */
    public function reconocimientosPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Reconocimiento::class, 'registrado_por', 'id');
    }

    /** @return HasMany<Sancion, $this> */
    public function sancionesPorUsuario(): HasMany
    {
        return $this->hasMany(Sancion::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Sancion, $this> */
    public function sancionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Sancion::class, 'registrado_por', 'id');
    }

    /** @return HasMany<Controversia, $this> */
    public function controversiasPorUsuario(): HasMany
    {
        return $this->hasMany(Controversia::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Controversia, $this> */
    public function controversiasPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Controversia::class, 'registrado_por', 'id');
    }

    /** @return HasMany<Afiliacion, $this> */
    public function afiliacionesPorUsuario(): HasMany
    {
        return $this->hasMany(Afiliacion::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Afiliacion, $this> */
    public function afiliacionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Afiliacion::class, 'registrado_por', 'id');
    }

    /** @return HasMany<RegistroSstBienestar, $this> */
    public function registrosSstBienestarPorUsuario(): HasMany
    {
        return $this->hasMany(RegistroSstBienestar::class, 'usuario_id', 'id');
    }

    /** @return HasMany<RegistroSstBienestar, $this> */
    public function registrosSstBienestarPorRegistradoPor(): HasMany
    {
        return $this->hasMany(RegistroSstBienestar::class, 'registrado_por', 'id');
    }

    /** @return HasMany<Desvinculacion, $this> */
    public function desvinculacionesPorUsuario(): HasMany
    {
        return $this->hasMany(Desvinculacion::class, 'usuario_id', 'id');
    }

    /** @return HasMany<Desvinculacion, $this> */
    public function desvinculacionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Desvinculacion::class, 'registrado_por', 'id');
    }

    /** @return HasMany<RequerimientoDocumento, $this> */
    public function requerimientosDocumentosPorUsuario(): HasMany
    {
        return $this->hasMany(RequerimientoDocumento::class, 'usuario_id', 'id');
    }

    /** @return HasMany<RequerimientoDocumento, $this> */
    public function requerimientosDocumentosPorSolicitadoPor(): HasMany
    {
        return $this->hasMany(RequerimientoDocumento::class, 'solicitado_por', 'id');
    }

    /** @return HasMany<ObservacionDocumento, $this> */
    public function observacionesDocumentoPorRegistradaPor(): HasMany
    {
        return $this->hasMany(ObservacionDocumento::class, 'registrada_por', 'id');
    }

    /** @return HasMany<ObservacionDocumento, $this> */
    public function observacionesDocumentoPorVerificadaPor(): HasMany
    {
        return $this->hasMany(ObservacionDocumento::class, 'verificada_por', 'id');
    }

    /** @return HasMany<ProcesoFiscalizacion, $this> */
    public function procesosFiscalizacionPorUsuario(): HasMany
    {
        return $this->hasMany(ProcesoFiscalizacion::class, 'usuario_id', 'id');
    }

    /** @return HasMany<ProcesoFiscalizacion, $this> */
    public function procesosFiscalizacionPorResponsable(): HasMany
    {
        return $this->hasMany(ProcesoFiscalizacion::class, 'responsable_id', 'id');
    }

    /** @return HasMany<BitacoraAccesoLegajo, $this> */
    public function bitacoraAccesosLegajo(): HasMany
    {
        return $this->hasMany(BitacoraAccesoLegajo::class, 'usuario_id', 'id');
    }

    /** @return HasMany<CertificadoDiscapacidad, $this> */
    public function certificadosDiscapacidadPorUsuario(): HasMany
    {
        return $this->hasMany(CertificadoDiscapacidad::class, 'usuario_id', 'id');
    }

    /** @return HasMany<CertificadoDiscapacidad, $this> */
    public function certificadosDiscapacidadPorRegistradoPor(): HasMany
    {
        return $this->hasMany(CertificadoDiscapacidad::class, 'registrado_por', 'id');
    }

    /** @return HasMany<GoceVacacional, $this> */
    public function gocesVacacionalesPorUsuario(): HasMany
    {
        return $this->hasMany(GoceVacacional::class, 'usuario_id', 'id');
    }

    /** @return HasMany<GoceVacacional, $this> */
    public function gocesVacacionalesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(GoceVacacional::class, 'registrado_por', 'id');
    }

    /** @return HasMany<SolicitudFiscalizacion, $this> */
    public function solicitudesFiscalizacion(): HasMany
    {
        return $this->hasMany(SolicitudFiscalizacion::class, 'registrado_por', 'id');
    }

    /** @return HasMany<HistorialEstadoDocumento, $this> */
    public function historialEstadosDocumento(): HasMany
    {
        return $this->hasMany(HistorialEstadoDocumento::class, 'cambiado_por', 'id');
    }

    /** @return HasMany<NotificacionUsuario, $this> */
    public function notificacionesUsuario(): HasMany
    {
        return $this->hasMany(NotificacionUsuario::class, 'usuario_id', 'id');
    }

    public function getEmailForPasswordReset(): string
    {
        return $this->email_institucional;
    }

    public function routeNotificationForMail(): string
    {
        return $this->email_institucional;
    }
}
