<?php

namespace App\Models;

use App\Support\PoliticaPermisos;
use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;

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
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'password' => 'hashed',
            'obligado_djbr' => 'boolean',
            'exige_colegiatura' => 'boolean',
            'exige_estudios_basicos' => 'boolean',
            'tiene_periodo_prueba' => 'boolean',
            'tiene_derechohabientes' => 'boolean',
            'con_discapacidad' => 'boolean',
            'activo' => 'boolean',
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

        if ($this->relationLoaded('usuarioRolesPorUsuario')) {
            return $this->rolesVigentesCalculados = $this->usuarioRolesPorUsuario
                ->filter(fn (UsuarioRol $asignacion): bool => $asignacion->vigente_desde->toDateString() <= $hoy
                    && ($asignacion->vigente_hasta === null || $asignacion->vigente_hasta->toDateString() >= $hoy))
                ->map(fn (UsuarioRol $asignacion): ?Rol => $asignacion->rol)
                ->filter(fn (?Rol $rol): bool => $rol !== null && $rol->activo)->unique('id')->values();
        }

        return $this->rolesVigentesCalculados = Rol::query()
            ->where('activo', true)
            ->whereIn('id', $this->usuarioRolesPorUsuario()
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
        $fuente = config('permisos.fuente');
        if (! in_array($fuente, ['legacy', 'dual', 'database'], true)) {
            return $this->permisosCalculados = [];
        }
        if ($fuente === 'database') {
            return $this->permisosCalculados = PoliticaPermisos::resolver($this->rolesVigentes());
        }
        $catalogo = array_keys(config('permisos.catalogo'));
        $derivados = config('permisos.derivados');
        $asignables = array_diff($catalogo, array_keys($derivados), config('permisos.pendientes'));
        $permisos = [];
        foreach ($this->rolesVigentes() as $rol) {
            $delRol = config('permisos.roles.'.$rol->codigo, []);
            $permisos = [...$permisos, ...($delRol === '*' ? $asignables : array_intersect($delRol, $asignables))];
            foreach ($derivados as $permiso => $columna) {
                if ($rol->{$columna}) {
                    $permisos[] = $permiso;
                }
            }
        }

        $legacy = array_values(array_unique($permisos));
        if ($fuente === 'dual') {
            $baseDatos = PoliticaPermisos::resolver($this->rolesVigentes());
            if (array_diff($legacy, $baseDatos) !== [] || array_diff($baseDatos, $legacy) !== []) {
                Log::notice('permisos.divergencia', ['usuario_id' => $this->id,
                    'solo_legacy' => array_values(array_diff($legacy, $baseDatos)), 'solo_bd' => array_values(array_diff($baseDatos, $legacy))]);
            }

            return $this->permisosCalculados = $baseDatos;
        }

        return $this->permisosCalculados = $legacy;
    }

    public function tienePermiso(string $permiso): bool
    {
        return in_array($permiso, $this->permisos(), true);
    }

    public function veDatosSensibles(): bool
    {
        return $this->tienePermiso('datos_sensibles.ver') || ($this->tienePermiso('legajo.propio') && request()->isMethod('GET') && request()->is('api/v1/expedientes/*') && request()->route('usuario') === $this->id);
    }

    public function isAdministrator(): bool
    {
        return $this->tienePermiso('sistema.administrar');
    }

    public function regimenNormativo(): BelongsTo
    {
        return $this->belongsTo(RegimenNormativo::class, 'regimen_normativo_id', 'id');
    }

    public function unidadOrganica(): BelongsTo
    {
        return $this->belongsTo(UnidadOrganica::class, 'unidad_organica_id', 'id');
    }

    public function ubigeo(): BelongsTo
    {
        return $this->belongsTo(Ubigeo::class, 'ubigeo_id', 'id');
    }

    public function usuarioRolesPorUsuario(): HasMany
    {
        return $this->hasMany(UsuarioRol::class, 'usuario_id', 'id');
    }

    public function usuarioRolesPorAsignadoPor(): HasMany
    {
        return $this->hasMany(UsuarioRol::class, 'asignado_por', 'id');
    }

    public function vinculosLaborales(): HasMany
    {
        return $this->hasMany(VinculoLaboral::class, 'usuario_id', 'id');
    }

    public function legajos(): HasMany
    {
        return $this->hasMany(Legajo::class, 'usuario_id', 'id');
    }

    public function archivosDocumento(): HasMany
    {
        return $this->hasMany(ArchivoDocumento::class, 'subido_por', 'id');
    }

    public function auditoriaEventosPorActorUsuario(): HasMany
    {
        return $this->hasMany(AuditoriaEvento::class, 'actor_usuario_id', 'id');
    }

    public function auditoriaEventosPorUsuarioTitular(): HasMany
    {
        return $this->hasMany(AuditoriaEvento::class, 'usuario_titular_id', 'id');
    }

    public function parametrosSistema(): HasMany
    {
        return $this->hasMany(ParametroSistema::class, 'actualizado_por', 'id');
    }

    public function documentosUsuarioPorUsuario(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'usuario_id', 'id');
    }

    public function documentosUsuarioPorRecepcionadoPor(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'recepcionado_por', 'id');
    }

    public function documentosUsuarioPorFedatario(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'fedatario_id', 'id');
    }

    public function documentosUsuarioPorFiscalizadoPor(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'fiscalizado_por', 'id');
    }

    public function documentosUsuarioPorCreatedBy(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'created_by', 'id');
    }

    public function compromisosEntregaOriginalesPorUsuario(): HasMany
    {
        return $this->hasMany(CompromisoEntregaOriginal::class, 'usuario_id', 'id');
    }

    public function compromisosEntregaOriginalesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(CompromisoEntregaOriginal::class, 'registrado_por', 'id');
    }

    public function referenciasLegajoPasivo(): HasMany
    {
        return $this->hasMany(ReferenciaLegajoPasivo::class, 'responsable_legajo_id', 'id');
    }

    public function solicitudesAccesoLegajoPorSolicitanteUsuario(): HasMany
    {
        return $this->hasMany(SolicitudAccesoLegajo::class, 'solicitante_usuario_id', 'id');
    }

    public function solicitudesAccesoLegajoPorResueltaPor(): HasMany
    {
        return $this->hasMany(SolicitudAccesoLegajo::class, 'resuelta_por', 'id');
    }

    public function movimientosCustodiaLegajo(): HasMany
    {
        return $this->hasMany(MovimientoCustodiaLegajo::class, 'autorizado_por', 'id');
    }

    public function vinculoAdendas(): HasMany
    {
        return $this->hasMany(VinculoAdenda::class, 'registrado_por', 'id');
    }

    public function familiaresPorUsuario(): HasMany
    {
        return $this->hasMany(Familiar::class, 'usuario_id', 'id');
    }

    public function familiaresPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Familiar::class, 'registrado_por', 'id');
    }

    public function declaracionesVerificacionesPorUsuario(): HasMany
    {
        return $this->hasMany(DeclaracionVerificacion::class, 'usuario_id', 'id');
    }

    public function declaracionesVerificacionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(DeclaracionVerificacion::class, 'registrado_por', 'id');
    }

    public function induccionesPorUsuario(): HasMany
    {
        return $this->hasMany(Induccion::class, 'usuario_id', 'id');
    }

    public function induccionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Induccion::class, 'registrado_por', 'id');
    }

    public function periodosPruebaPorUsuario(): HasMany
    {
        return $this->hasMany(PeriodoPrueba::class, 'usuario_id', 'id');
    }

    public function periodosPruebaPorRegistradoPor(): HasMany
    {
        return $this->hasMany(PeriodoPrueba::class, 'registrado_por', 'id');
    }

    public function formacionAcademicaPorUsuario(): HasMany
    {
        return $this->hasMany(FormacionAcademica::class, 'usuario_id', 'id');
    }

    public function formacionAcademicaPorRegistradoPor(): HasMany
    {
        return $this->hasMany(FormacionAcademica::class, 'registrado_por', 'id');
    }

    public function colegiaturasPorUsuario(): HasMany
    {
        return $this->hasMany(Colegiatura::class, 'usuario_id', 'id');
    }

    public function colegiaturasPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Colegiatura::class, 'registrado_por', 'id');
    }

    public function capacitacionesPorUsuario(): HasMany
    {
        return $this->hasMany(Capacitacion::class, 'usuario_id', 'id');
    }

    public function capacitacionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Capacitacion::class, 'registrado_por', 'id');
    }

    public function experienciaLaboralPorUsuario(): HasMany
    {
        return $this->hasMany(ExperienciaLaboral::class, 'usuario_id', 'id');
    }

    public function experienciaLaboralPorRegistradoPor(): HasMany
    {
        return $this->hasMany(ExperienciaLaboral::class, 'registrado_por', 'id');
    }

    public function licenciasPermisosPorUsuario(): HasMany
    {
        return $this->hasMany(LicenciaPermiso::class, 'usuario_id', 'id');
    }

    public function licenciasPermisosPorRegistradoPor(): HasMany
    {
        return $this->hasMany(LicenciaPermiso::class, 'registrado_por', 'id');
    }

    public function periodosVacacionalesPorUsuario(): HasMany
    {
        return $this->hasMany(PeriodoVacacional::class, 'usuario_id', 'id');
    }

    public function periodosVacacionalesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(PeriodoVacacional::class, 'registrado_por', 'id');
    }

    public function tiempoServiciosReconocidosPorUsuario(): HasMany
    {
        return $this->hasMany(TiempoServicioReconocido::class, 'usuario_id', 'id');
    }

    public function tiempoServiciosReconocidosPorRegistradoPor(): HasMany
    {
        return $this->hasMany(TiempoServicioReconocido::class, 'registrado_por', 'id');
    }

    public function compensacionesBeneficiosPorUsuario(): HasMany
    {
        return $this->hasMany(CompensacionBeneficio::class, 'usuario_id', 'id');
    }

    public function compensacionesBeneficiosPorRegistradoPor(): HasMany
    {
        return $this->hasMany(CompensacionBeneficio::class, 'registrado_por', 'id');
    }

    public function evaluacionesDesempenoPorUsuario(): HasMany
    {
        return $this->hasMany(EvaluacionDesempeno::class, 'usuario_id', 'id');
    }

    public function evaluacionesDesempenoPorEvaluador(): HasMany
    {
        return $this->hasMany(EvaluacionDesempeno::class, 'evaluador_id', 'id');
    }

    public function evaluacionesDesempenoPorRegistradoPor(): HasMany
    {
        return $this->hasMany(EvaluacionDesempeno::class, 'registrado_por', 'id');
    }

    public function desplazamientosPorUsuario(): HasMany
    {
        return $this->hasMany(Desplazamiento::class, 'usuario_id', 'id');
    }

    public function desplazamientosPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Desplazamiento::class, 'registrado_por', 'id');
    }

    public function progresionesCarreraPorUsuario(): HasMany
    {
        return $this->hasMany(ProgresionCarrera::class, 'usuario_id', 'id');
    }

    public function progresionesCarreraPorRegistradoPor(): HasMany
    {
        return $this->hasMany(ProgresionCarrera::class, 'registrado_por', 'id');
    }

    public function ratificacionesDocentesPorUsuario(): HasMany
    {
        return $this->hasMany(RatificacionDocente::class, 'usuario_id', 'id');
    }

    public function ratificacionesDocentesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(RatificacionDocente::class, 'registrado_por', 'id');
    }

    public function reconocimientosPorUsuario(): HasMany
    {
        return $this->hasMany(Reconocimiento::class, 'usuario_id', 'id');
    }

    public function reconocimientosPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Reconocimiento::class, 'registrado_por', 'id');
    }

    public function sancionesPorUsuario(): HasMany
    {
        return $this->hasMany(Sancion::class, 'usuario_id', 'id');
    }

    public function sancionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Sancion::class, 'registrado_por', 'id');
    }

    public function controversiasPorUsuario(): HasMany
    {
        return $this->hasMany(Controversia::class, 'usuario_id', 'id');
    }

    public function controversiasPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Controversia::class, 'registrado_por', 'id');
    }

    public function afiliacionesPorUsuario(): HasMany
    {
        return $this->hasMany(Afiliacion::class, 'usuario_id', 'id');
    }

    public function afiliacionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Afiliacion::class, 'registrado_por', 'id');
    }

    public function registrosSstBienestarPorUsuario(): HasMany
    {
        return $this->hasMany(RegistroSstBienestar::class, 'usuario_id', 'id');
    }

    public function registrosSstBienestarPorRegistradoPor(): HasMany
    {
        return $this->hasMany(RegistroSstBienestar::class, 'registrado_por', 'id');
    }

    public function desvinculacionesPorUsuario(): HasMany
    {
        return $this->hasMany(Desvinculacion::class, 'usuario_id', 'id');
    }

    public function desvinculacionesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(Desvinculacion::class, 'registrado_por', 'id');
    }

    public function requerimientosDocumentosPorUsuario(): HasMany
    {
        return $this->hasMany(RequerimientoDocumento::class, 'usuario_id', 'id');
    }

    public function requerimientosDocumentosPorSolicitadoPor(): HasMany
    {
        return $this->hasMany(RequerimientoDocumento::class, 'solicitado_por', 'id');
    }

    public function observacionesDocumentoPorRegistradaPor(): HasMany
    {
        return $this->hasMany(ObservacionDocumento::class, 'registrada_por', 'id');
    }

    public function observacionesDocumentoPorVerificadaPor(): HasMany
    {
        return $this->hasMany(ObservacionDocumento::class, 'verificada_por', 'id');
    }

    public function procesosFiscalizacionPorUsuario(): HasMany
    {
        return $this->hasMany(ProcesoFiscalizacion::class, 'usuario_id', 'id');
    }

    public function procesosFiscalizacionPorResponsable(): HasMany
    {
        return $this->hasMany(ProcesoFiscalizacion::class, 'responsable_id', 'id');
    }

    public function bitacoraAccesosLegajo(): HasMany
    {
        return $this->hasMany(BitacoraAccesoLegajo::class, 'usuario_id', 'id');
    }

    public function certificadosDiscapacidadPorUsuario(): HasMany
    {
        return $this->hasMany(CertificadoDiscapacidad::class, 'usuario_id', 'id');
    }

    public function certificadosDiscapacidadPorRegistradoPor(): HasMany
    {
        return $this->hasMany(CertificadoDiscapacidad::class, 'registrado_por', 'id');
    }

    public function gocesVacacionalesPorUsuario(): HasMany
    {
        return $this->hasMany(GoceVacacional::class, 'usuario_id', 'id');
    }

    public function gocesVacacionalesPorRegistradoPor(): HasMany
    {
        return $this->hasMany(GoceVacacional::class, 'registrado_por', 'id');
    }

    public function solicitudesFiscalizacion(): HasMany
    {
        return $this->hasMany(SolicitudFiscalizacion::class, 'registrado_por', 'id');
    }

    public function historialEstadosDocumento(): HasMany
    {
        return $this->hasMany(HistorialEstadoDocumento::class, 'cambiado_por', 'id');
    }

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
