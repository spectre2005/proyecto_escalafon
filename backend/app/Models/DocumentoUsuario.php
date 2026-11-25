<?php

namespace App\Models;

use App\Support\Documentos\DocumentoProtegido;
use Database\Factories\DocumentoUsuarioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentoUsuario extends Model
{
    /** @use HasFactory<DocumentoUsuarioFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'documentos_usuario';

    /**
     * Identidad del documento en el legajo: una vez registrado no cambia (Directiva, arts. 2.3 y 5.3). Para corregirlo
     * se sube uno nuevo que lo subsana; para retirarlo, se anula.
     */
    public const CAMPOS_INMUTABLES = ['usuario_id', 'legajo_id', 'tipo_documento_id', 'seccion_legajo_id', 'archivo_id',
        'folio_inicio', 'folio_fin', 'subsana_a_documento_id', 'created_by'];

    /**
     * Las reglas que no se rompen viven en el modelo y no dependen de cada controlador: un documento no se elimina,
     * su identidad no se edita y uno anulado no vuelve a otro estado. (Las escrituras masivas con query()->update() no
     * pasan por aquí; solo se usan para enlazar el vínculo o registrar la recepción, que no son campos inmutables.)
     */
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new DocumentoProtegido('Los documentos del legajo no se eliminan (Directiva, arts. 2.3 y 5.3): se anulan o se subsanan con un documento nuevo.');
        });
        static::updating(function (self $documento): void {
            $cambiados = array_intersect(self::CAMPOS_INMUTABLES, array_keys($documento->getDirty()));
            if ($cambiados !== []) {
                throw new DocumentoProtegido('No se puede modificar '.implode(', ', $cambiados).' de un documento registrado: sube uno nuevo que lo subsane.');
            }
            if ($documento->getOriginal('estado') === 'anulado' && $documento->isDirty('estado')) {
                throw new DocumentoProtegido('Un documento anulado no cambia de estado.');
            }
        });
    }

    /** Excluye los anulados: ningún listado ni contador los cuenta (CLAUDE.md, «Reglas que no se rompen»). */
    public function scopeNoAnulados(Builder $query): Builder
    {
        return $query->where('estado', '!=', 'anulado');
    }

    /** Solo la versión vigente: sin un documento posterior que lo subsane. */
    public function scopeSinSubsanar(Builder $query): Builder
    {
        return $query->whereDoesntHave('documentosUsuarioPorSubsanaADocumento');
    }

    protected $fillable = [
        'nombre',
        'funcion_asignada',
        'usuario_id',
        'legajo_id',
        'tipo_documento_id',
        'seccion_legajo_id',
        'vinculo_laboral_id',
        'archivo_id',
        'modalidad_presentacion',
        'motivo_incorporacion',
        'folio_inicio',
        'folio_fin',
        'fecha_recepcion',
        'recepcionado_por',
        'fecha_incorporacion',
        'emisor',
        'fecha_emision',
        'fecha_caducidad',
        'titular_nombre',
        'titular_documento',
        'relacion_titular',
        'num_resolucion',
        'estado',
        'fedateado',
        'fecha_fedateo',
        'fedatario_id',
        'fiscalizado',
        'fecha_fiscalizacion',
        'fiscalizado_por',
        'subsana_a_documento_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seccion_legajo_id' => 'integer',
            'folio_inicio' => 'integer',
            'folio_fin' => 'integer',
            'fecha_recepcion' => 'date',
            'fecha_incorporacion' => 'date',
            'fecha_emision' => 'date',
            'fecha_caducidad' => 'date',
            'fedateado' => 'boolean',
            'fecha_fedateo' => 'date',
            'fiscalizado' => 'boolean',
            'fecha_fiscalizacion' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    /** @return BelongsTo<Legajo, $this> */
    public function legajo(): BelongsTo
    {
        return $this->belongsTo(Legajo::class, 'legajo_id', 'id');
    }

    /** @return BelongsTo<TipoDocumento, $this> */
    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id', 'id');
    }

    /** @return BelongsTo<SeccionLegajo, $this> */
    public function seccionLegajo(): BelongsTo
    {
        return $this->belongsTo(SeccionLegajo::class, 'seccion_legajo_id', 'id');
    }

    /** @return BelongsTo<VinculoLaboral, $this> */
    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'vinculo_laboral_id', 'id');
    }

    /** @return BelongsTo<ArchivoDocumento, $this> */
    public function archivo(): BelongsTo
    {
        return $this->belongsTo(ArchivoDocumento::class, 'archivo_id', 'id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function recepcionadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'recepcionado_por', 'id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function fedatario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'fedatario_id', 'id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function fiscalizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'fiscalizado_por', 'id');
    }

    /** @return BelongsTo<DocumentoUsuario, $this> */
    public function subsanaADocumento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'subsana_a_documento_id', 'id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by', 'id');
    }

    /** @return HasMany<DocumentoUsuario, $this> */
    public function documentosUsuarioPorSubsanaADocumento(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'subsana_a_documento_id', 'id');
    }

    /** @return HasMany<VinculoAdenda, $this> */
    public function vinculoAdendas(): HasMany
    {
        return $this->hasMany(VinculoAdenda::class, 'documento_id', 'id');
    }

    /** @return HasMany<CompromisoDocumento, $this> */
    public function compromisoDocumentos(): HasMany
    {
        return $this->hasMany(CompromisoDocumento::class, 'documento_id', 'id');
    }

    /** @return HasMany<Familiar, $this> */
    public function familiares(): HasMany
    {
        return $this->hasMany(Familiar::class, 'documento_id', 'id');
    }

    /** @return HasMany<DeclaracionVerificacion, $this> */
    public function declaracionesVerificaciones(): HasMany
    {
        return $this->hasMany(DeclaracionVerificacion::class, 'documento_id', 'id');
    }

    /** @return HasMany<Induccion, $this> */
    public function inducciones(): HasMany
    {
        return $this->hasMany(Induccion::class, 'documento_id', 'id');
    }

    /** @return HasMany<PeriodoPrueba, $this> */
    public function periodosPrueba(): HasMany
    {
        return $this->hasMany(PeriodoPrueba::class, 'documento_id', 'id');
    }

    /** @return HasMany<FormacionAcademica, $this> */
    public function formacionAcademica(): HasMany
    {
        return $this->hasMany(FormacionAcademica::class, 'documento_id', 'id');
    }

    /** @return HasMany<Colegiatura, $this> */
    public function colegiaturas(): HasMany
    {
        return $this->hasMany(Colegiatura::class, 'documento_id', 'id');
    }

    /** @return HasMany<Capacitacion, $this> */
    public function capacitaciones(): HasMany
    {
        return $this->hasMany(Capacitacion::class, 'documento_id', 'id');
    }

    /** @return HasMany<Idioma, $this> */
    public function idiomas(): HasMany
    {
        return $this->hasMany(Idioma::class, 'documento_id', 'id');
    }

    /** @return HasMany<ProduccionIntelectual, $this> */
    public function produccionesIntelectuales(): HasMany
    {
        return $this->hasMany(ProduccionIntelectual::class, 'documento_id', 'id');
    }

    /** @return HasMany<ExperienciaLaboral, $this> */
    public function experienciaLaboral(): HasMany
    {
        return $this->hasMany(ExperienciaLaboral::class, 'documento_id', 'id');
    }

    /** @return HasMany<LicenciaPermiso, $this> */
    public function licenciasPermisos(): HasMany
    {
        return $this->hasMany(LicenciaPermiso::class, 'documento_id', 'id');
    }

    /** @return HasMany<PeriodoVacacional, $this> */
    public function periodosVacacionales(): HasMany
    {
        return $this->hasMany(PeriodoVacacional::class, 'documento_id', 'id');
    }

    /** @return HasMany<TiempoServicioReconocido, $this> */
    public function tiempoServiciosReconocidos(): HasMany
    {
        return $this->hasMany(TiempoServicioReconocido::class, 'documento_id', 'id');
    }

    /** @return HasMany<CompensacionBeneficio, $this> */
    public function compensacionesBeneficios(): HasMany
    {
        return $this->hasMany(CompensacionBeneficio::class, 'documento_id', 'id');
    }

    /** @return HasMany<EvaluacionDesempeno, $this> */
    public function evaluacionesDesempeno(): HasMany
    {
        return $this->hasMany(EvaluacionDesempeno::class, 'documento_id', 'id');
    }

    /** @return HasMany<Desplazamiento, $this> */
    public function desplazamientos(): HasMany
    {
        return $this->hasMany(Desplazamiento::class, 'documento_id', 'id');
    }

    /** @return HasMany<ProgresionCarrera, $this> */
    public function progresionesCarrera(): HasMany
    {
        return $this->hasMany(ProgresionCarrera::class, 'documento_id', 'id');
    }

    /** @return HasMany<RatificacionDocente, $this> */
    public function ratificacionesDocentes(): HasMany
    {
        return $this->hasMany(RatificacionDocente::class, 'documento_id', 'id');
    }

    /** @return HasMany<Reconocimiento, $this> */
    public function reconocimientos(): HasMany
    {
        return $this->hasMany(Reconocimiento::class, 'documento_id', 'id');
    }

    /** @return HasMany<RecursoImpugnatorio, $this> */
    public function recursosImpugnatorios(): HasMany
    {
        return $this->hasMany(RecursoImpugnatorio::class, 'documento_id', 'id');
    }

    /** @return HasMany<Sancion, $this> */
    public function sanciones(): HasMany
    {
        return $this->hasMany(Sancion::class, 'documento_id', 'id');
    }

    /** @return HasMany<Controversia, $this> */
    public function controversias(): HasMany
    {
        return $this->hasMany(Controversia::class, 'documento_id', 'id');
    }

    /** @return HasMany<Afiliacion, $this> */
    public function afiliaciones(): HasMany
    {
        return $this->hasMany(Afiliacion::class, 'documento_id', 'id');
    }

    /** @return HasMany<RegistroSstBienestar, $this> */
    public function registrosSstBienestar(): HasMany
    {
        return $this->hasMany(RegistroSstBienestar::class, 'documento_id', 'id');
    }

    /** @return HasMany<Desvinculacion, $this> */
    public function desvinculaciones(): HasMany
    {
        return $this->hasMany(Desvinculacion::class, 'documento_id', 'id');
    }

    /** @return HasMany<RequerimientoDocumento, $this> */
    public function requerimientosDocumentos(): HasMany
    {
        return $this->hasMany(RequerimientoDocumento::class, 'documento_id', 'id');
    }

    /** @return HasMany<ObservacionDocumento, $this> */
    public function observacionesDocumentoPorDocumento(): HasMany
    {
        return $this->hasMany(ObservacionDocumento::class, 'documento_id', 'id');
    }

    /** @return HasMany<ObservacionDocumento, $this> */
    public function observacionesDocumentoPorDocumentoSubsanacion(): HasMany
    {
        return $this->hasMany(ObservacionDocumento::class, 'documento_subsanacion_id', 'id');
    }

    /** @return HasMany<ProcesoFiscalizacion, $this> */
    public function procesosFiscalizacion(): HasMany
    {
        return $this->hasMany(ProcesoFiscalizacion::class, 'informe_documento_id', 'id');
    }

    /** @return HasMany<BitacoraAccesoLegajo, $this> */
    public function bitacoraAccesosLegajo(): HasMany
    {
        return $this->hasMany(BitacoraAccesoLegajo::class, 'documento_id', 'id');
    }

    /** @return HasMany<CertificadoDiscapacidad, $this> */
    public function certificadosDiscapacidad(): HasMany
    {
        return $this->hasMany(CertificadoDiscapacidad::class, 'documento_id', 'id');
    }

    /** @return HasMany<GoceVacacional, $this> */
    public function gocesVacacionales(): HasMany
    {
        return $this->hasMany(GoceVacacional::class, 'documento_id', 'id');
    }

    /** @return HasMany<SolicitudFiscalizacion, $this> */
    public function solicitudesFiscalizacionPorDocumento(): HasMany
    {
        return $this->hasMany(SolicitudFiscalizacion::class, 'documento_id', 'id');
    }

    /** @return HasMany<SolicitudFiscalizacion, $this> */
    public function solicitudesFiscalizacionPorDocumentoRespuesta(): HasMany
    {
        return $this->hasMany(SolicitudFiscalizacion::class, 'documento_respuesta_id', 'id');
    }

    /** @return HasMany<HistorialEstadoDocumento, $this> */
    public function historialEstadosDocumento(): HasMany
    {
        return $this->hasMany(HistorialEstadoDocumento::class, 'documento_usuario_id', 'id');
    }

    /** @return HasMany<NotificacionUsuario, $this> */
    public function notificacionesUsuario(): HasMany
    {
        return $this->hasMany(NotificacionUsuario::class, 'documento_id', 'id');
    }
}
