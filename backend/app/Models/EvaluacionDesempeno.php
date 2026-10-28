<?php

namespace App\Models;

use App\Models\Concerns\PerteneceALegajo;
use Database\Factories\EvaluacionDesempenoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluacionDesempeno extends Model
{
    /** @use HasFactory<EvaluacionDesempenoFactory> */
    use HasFactory;

    use HasUuids;
    use PerteneceALegajo;

    protected $table = 'evaluaciones_desempeno';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'ciclo_anio',
        'fecha_evaluacion',
        'calificacion',
        'puntaje',
        'plan_mejora',
        'evaluador_id',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ciclo_anio' => 'integer',
            'fecha_evaluacion' => 'date',
            'puntaje' => 'decimal:2',
            'plan_mejora' => 'boolean',
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

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'evaluador_id', 'id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
