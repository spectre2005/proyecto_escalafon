<?php

namespace App\Models;

use Database\Factories\TiempoServicioReconocidoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TiempoServicioReconocido extends Model
{
    /** @use HasFactory<TiempoServicioReconocidoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'tiempo_servicios_reconocidos';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'numero_resolucion',
        'fecha_resolucion',
        'anios',
        'meses',
        'dias',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_resolucion' => 'date',
            'anios' => 'integer',
            'meses' => 'integer',
            'dias' => 'integer',
            'anulado' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
