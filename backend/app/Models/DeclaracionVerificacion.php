<?php

namespace App\Models;

use Database\Factories\DeclaracionVerificacionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeclaracionVerificacion extends Model
{
    /** @use HasFactory<DeclaracionVerificacionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'declaraciones_verificaciones';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'tipo',
        'anio',
        'fecha',
        'resultado',
        'vigente_hasta',
        'observacion',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'fecha' => 'date',
            'vigente_hasta' => 'date',
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
