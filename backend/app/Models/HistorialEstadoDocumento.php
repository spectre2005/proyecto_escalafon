<?php

namespace App\Models;

use App\Support\RegistroInmutable;
use Database\Factories\HistorialEstadoDocumentoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistorialEstadoDocumento extends Model
{
    /** @use HasFactory<HistorialEstadoDocumentoFactory> */
    use HasFactory;

    use HasUuids;
    use RegistroInmutable;

    protected $table = 'historial_estados_documento';

    public $timestamps = false;

    protected $fillable = [
        'documento_usuario_id',
        'estado_anterior',
        'estado_nuevo',
        'cambiado_por',
        'motivo',
        'observacion_id',
        'ip_origen',
        'fecha_cambio',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_cambio' => 'datetime',
        ];
    }

    public function documentoUsuario(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_usuario_id', 'id');
    }

    public function cambiadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cambiado_por', 'id');
    }

    public function observacion(): BelongsTo
    {
        return $this->belongsTo(ObservacionDocumento::class, 'observacion_id', 'id');
    }
}
