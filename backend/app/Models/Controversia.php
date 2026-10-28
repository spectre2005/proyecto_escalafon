<?php

namespace App\Models;

use App\Models\Concerns\PerteneceALegajo;
use Database\Factories\ControversiaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Controversia extends Model
{
    /** @use HasFactory<ControversiaFactory> */
    use HasFactory;

    use HasUuids;
    use PerteneceALegajo;

    protected $table = 'controversias';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'tipo',
        'instancia',
        'materia',
        'fecha_inicio',
        'estado',
        'fecha_resolucion',
        'resultado',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_resolucion' => 'date',
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
