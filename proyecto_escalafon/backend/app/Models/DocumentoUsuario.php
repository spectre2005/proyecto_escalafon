<?php

namespace App\Models;

use Database\Factories\DocumentoUsuarioFactory;
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

    protected $fillable = [
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
            'fedateado' => 'boolean',
            'fecha_fedateo' => 'date',
            'fiscalizado' => 'boolean',
            'fecha_fiscalizacion' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    public function legajo(): BelongsTo
    {
        return $this->belongsTo(Legajo::class, 'legajo_id', 'id');
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id', 'id');
    }

    public function seccionLegajo(): BelongsTo
    {
        return $this->belongsTo(SeccionLegajo::class, 'seccion_legajo_id', 'id');
    }

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'vinculo_laboral_id', 'id');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(ArchivoDocumento::class, 'archivo_id', 'id');
    }

    public function recepcionadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'recepcionado_por', 'id');
    }

    public function fedatario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'fedatario_id', 'id');
    }

    public function fiscalizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'fiscalizado_por', 'id');
    }

    public function subsanaADocumento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'subsana_a_documento_id', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by', 'id');
    }

    public function documentosUsuarioPorSubsanaADocumento(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'subsana_a_documento_id', 'id');
    }

    public function vinculoAdendas(): HasMany
    {
        return $this->hasMany(VinculoAdenda::class, 'documento_id', 'id');
    }

    public function compromisoDocumentos(): HasMany
    {
        return $this->hasMany(CompromisoDocumento::class, 'documento_id', 'id');
    }

    public function familiares(): HasMany
    {
        return $this->hasMany(Familiar::class, 'documento_id', 'id');
    }

    public function declaracionesVerificaciones(): HasMany
    {
        return $this->hasMany(DeclaracionVerificacion::class, 'documento_id', 'id');
    }

    public function inducciones(): HasMany
    {
        return $this->hasMany(Induccion::class, 'documento_id', 'id');
    }

    public function periodosPrueba(): HasMany
    {
        return $this->hasMany(PeriodoPrueba::class, 'documento_id', 'id');
    }

    public function formacionAcademica(): HasMany
    {
        return $this->hasMany(FormacionAcademica::class, 'documento_id', 'id');
    }

    public function colegiaturas(): HasMany
    {
        return $this->hasMany(Colegiatura::class, 'documento_id', 'id');
    }

    public function capacitaciones(): HasMany
    {
        return $this->hasMany(Capacitacion::class, 'documento_id', 'id');
    }

    public function experienciaLaboral(): HasMany
    {
        return $this->hasMany(ExperienciaLaboral::class, 'documento_id', 'id');
    }

    public function licenciasPermisos(): HasMany
    {
        return $this->hasMany(LicenciaPermiso::class, 'documento_id', 'id');
    }

    public function periodosVacacionales(): HasMany
    {
        return $this->hasMany(PeriodoVacacional::class, 'documento_id', 'id');
    }

    public function tiempoServiciosReconocidos(): HasMany
    {
        return $this->hasMany(TiempoServicioReconocido::class, 'documento_id', 'id');
    }

    public function compensacionesBeneficios(): HasMany
    {
        return $this->hasMany(CompensacionBeneficio::class, 'documento_id', 'id');
    }

    public function evaluacionesDesempeno(): HasMany
    {
        return $this->hasMany(EvaluacionDesempeno::class, 'documento_id', 'id');
    }

    public function desplazamientos(): HasMany
    {
        return $this->hasMany(Desplazamiento::class, 'documento_id', 'id');
    }

    public function progresionesCarrera(): HasMany
    {
        return $this->hasMany(ProgresionCarrera::class, 'documento_id', 'id');
    }

    public function ratificacionesDocentes(): HasMany
    {
        return $this->hasMany(RatificacionDocente::class, 'documento_id', 'id');
    }

    public function reconocimientos(): HasMany
    {
        return $this->hasMany(Reconocimiento::class, 'documento_id', 'id');
    }

    public function sanciones(): HasMany
    {
        return $this->hasMany(Sancion::class, 'documento_id', 'id');
    }

    public function controversias(): HasMany
    {
        return $this->hasMany(Controversia::class, 'documento_id', 'id');
    }

    public function afiliaciones(): HasMany
    {
        return $this->hasMany(Afiliacion::class, 'documento_id', 'id');
    }

    public function registrosSstBienestar(): HasMany
    {
        return $this->hasMany(RegistroSstBienestar::class, 'documento_id', 'id');
    }

    public function desvinculaciones(): HasMany
    {
        return $this->hasMany(Desvinculacion::class, 'documento_id', 'id');
    }

    public function requerimientosDocumentos(): HasMany
    {
        return $this->hasMany(RequerimientoDocumento::class, 'documento_id', 'id');
    }

    public function observacionesDocumentoPorDocumento(): HasMany
    {
        return $this->hasMany(ObservacionDocumento::class, 'documento_id', 'id');
    }

    public function observacionesDocumentoPorDocumentoSubsanacion(): HasMany
    {
        return $this->hasMany(ObservacionDocumento::class, 'documento_subsanacion_id', 'id');
    }

    public function procesosFiscalizacion(): HasMany
    {
        return $this->hasMany(ProcesoFiscalizacion::class, 'informe_documento_id', 'id');
    }

    public function bitacoraAccesosLegajo(): HasMany
    {
        return $this->hasMany(BitacoraAccesoLegajo::class, 'documento_id', 'id');
    }

    public function certificadosDiscapacidad(): HasMany
    {
        return $this->hasMany(CertificadoDiscapacidad::class, 'documento_id', 'id');
    }

    public function gocesVacacionales(): HasMany
    {
        return $this->hasMany(GoceVacacional::class, 'documento_id', 'id');
    }

    public function solicitudesFiscalizacionPorDocumento(): HasMany
    {
        return $this->hasMany(SolicitudFiscalizacion::class, 'documento_id', 'id');
    }

    public function solicitudesFiscalizacionPorDocumentoRespuesta(): HasMany
    {
        return $this->hasMany(SolicitudFiscalizacion::class, 'documento_respuesta_id', 'id');
    }

    public function historialEstadosDocumento(): HasMany
    {
        return $this->hasMany(HistorialEstadoDocumento::class, 'documento_usuario_id', 'id');
    }

    public function notificacionesUsuario(): HasMany
    {
        return $this->hasMany(NotificacionUsuario::class, 'documento_id', 'id');
    }
}
