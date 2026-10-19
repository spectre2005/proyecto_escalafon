<?php

namespace App\Models;

use Database\Factories\SolicitudAccesoLegajoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SolicitudAccesoLegajo extends Model
{
    /** @use HasFactory<SolicitudAccesoLegajoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'solicitudes_acceso_legajo';

    protected $fillable = [
        'legajo_id',
        'solicitante_usuario_id',
        'solicitante_nombre',
        'solicitante_documento',
        'entidad_procedencia',
        'tipo_solicitante',
        'detalle_requerido',
        'sustento',
        'forma_entrega',
        'incluye_datos_sensibles',
        'compromiso_confidencialidad',
        'estado',
        'resuelta_por',
        'fecha_resolucion',
        'motivo_resolucion',
        'vigente_hasta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'documentos_autorizados' => 'array',
            'incluye_datos_sensibles' => 'boolean',
            'compromiso_confidencialidad' => 'boolean',
            'fecha_resolucion' => 'datetime',
            'vigente_hasta' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function legajo(): BelongsTo
    {
        return $this->belongsTo(Legajo::class, 'legajo_id', 'id');
    }

    public function solicitanteUsuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitante_usuario_id', 'id');
    }

    public function resueltaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'resuelta_por', 'id');
    }

    public function bitacoraAccesosLegajo(): HasMany
    {
        return $this->hasMany(BitacoraAccesoLegajo::class, 'solicitud_acceso_id', 'id');
    }
}
