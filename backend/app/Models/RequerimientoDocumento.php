<?php

namespace App\Models;

use Database\Factories\RequerimientoDocumentoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RequerimientoDocumento extends Model
{
    /** @use HasFactory<RequerimientoDocumentoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'requerimientos_documentos';

    protected $fillable = [
        'usuario_id',
        'tipo_documento_id',
        'origen',
        'descripcion',
        'fecha_requerimiento',
        'plazo_dias_habiles',
        'fecha_limite',
        'prorroga_hasta',
        'estado',
        'documento_id',
        'solicitado_por',
        'cerrado_en',
        'ciclo_id', 'politica_id', 'documento_objetivo_id', 'motivo_renovacion',
        'unidad_plazo', 'antiguedad_maxima_dias', 'fecha_vencimiento_evidencia',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_requerimiento' => 'date',
            'plazo_dias_habiles' => 'integer',
            'fecha_limite' => 'date',
            'prorroga_hasta' => 'date',
            'cerrado_en' => 'datetime',
            'fecha_vencimiento_evidencia' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    public function presentacion(): HasOne
    {
        return $this->hasOne(PresentacionRenovacion::class, 'requerimiento_id')->where('actual', true);
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id', 'id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_id', 'id');
    }

    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitado_por', 'id');
    }

    public function notificacionesUsuario(): HasMany
    {
        return $this->hasMany(NotificacionUsuario::class, 'requerimiento_id', 'id');
    }
}
