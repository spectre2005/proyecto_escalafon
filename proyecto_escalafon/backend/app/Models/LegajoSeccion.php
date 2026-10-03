<?php

namespace App\Models;

use Database\Factories\LegajoSeccionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegajoSeccion extends Model
{
    /** @use HasFactory<LegajoSeccionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'legajo_secciones';

    public $timestamps = false;

    protected $fillable = [
        'legajo_id',
        'seccion_legajo_id',
        'ultimo_folio',
        'ultima_actualizacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seccion_legajo_id' => 'integer',
            'ultimo_folio' => 'integer',
            'ultima_actualizacion' => 'datetime',
        ];
    }

    public function legajo(): BelongsTo
    {
        return $this->belongsTo(Legajo::class, 'legajo_id', 'id');
    }

    public function seccionLegajo(): BelongsTo
    {
        return $this->belongsTo(SeccionLegajo::class, 'seccion_legajo_id', 'id');
    }
}
