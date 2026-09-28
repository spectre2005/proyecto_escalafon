<?php

namespace App\Models;

use Database\Factories\ObservacionDocumentoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ObservacionDocumento extends Model
{
    /** @use HasFactory<ObservacionDocumentoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'observaciones_documento';

    protected $fillable = [
        'documento_id',
        'tipo_observacion',
        'descripcion',
        'estado',
        'registrada_por',
        'fecha_registro',
        'plazo_subsanacion',
        'prorroga_hasta',
        'documento_subsanacion_id',
        'fecha_subsanacion',
        'verificada_por',
        'comentario_cierre',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_registro' => 'datetime',
            'plazo_subsanacion' => 'date',
            'prorroga_hasta' => 'date',
            'fecha_subsanacion' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_id', 'id');
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrada_por', 'id');
    }

    public function documentoSubsanacion(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_subsanacion_id', 'id');
    }

    public function verificadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'verificada_por', 'id');
    }

    public function historialEstadosDocumento(): HasMany
    {
        return $this->hasMany(HistorialEstadoDocumento::class, 'observacion_id', 'id');
    }

    public function notificacionesUsuario(): HasMany
    {
        return $this->hasMany(NotificacionUsuario::class, 'observacion_id', 'id');
    }
}
