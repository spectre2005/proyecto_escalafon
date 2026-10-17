<?php

namespace App\Models;

use Database\Factories\NotificacionUsuarioFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificacionUsuario extends Model
{
    /** @use HasFactory<NotificacionUsuarioFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'notificaciones_usuario';

    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'aviso_vencimiento_id',
        'tipo',
        'titulo',
        'mensaje',
        'documento_id',
        'observacion_id',
        'requerimiento_id',
        'leida',
        'fecha_lectura',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'leida' => 'boolean',
            'fecha_lectura' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_id', 'id');
    }

    public function observacion(): BelongsTo
    {
        return $this->belongsTo(ObservacionDocumento::class, 'observacion_id', 'id');
    }

    public function requerimiento(): BelongsTo
    {
        return $this->belongsTo(RequerimientoDocumento::class, 'requerimiento_id', 'id');
    }
}
