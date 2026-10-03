<?php

namespace App\Models;

use Database\Factories\CapacitacionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Capacitacion extends Model
{
    /** @use HasFactory<CapacitacionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'capacitaciones';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'tipo',
        'nombre',
        'entidad',
        'horas',
        'fecha_inicio',
        'fecha_fin',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'horas' => 'integer',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
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
