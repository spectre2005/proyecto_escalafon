<?php

namespace App\Models;

use Database\Factories\SubseccionLegajoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubseccionLegajo extends Model
{
    /** @use HasFactory<SubseccionLegajoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'subsecciones_legajo';

    public $timestamps = false;

    protected $fillable = [
        'seccion_legajo_id',
        'codigo',
        'nombre',
        'orden',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seccion_legajo_id' => 'integer',
            'orden' => 'integer',
        ];
    }

    public function seccionLegajo(): BelongsTo
    {
        return $this->belongsTo(SeccionLegajo::class, 'seccion_legajo_id', 'id');
    }

    public function tiposDocumento(): HasMany
    {
        return $this->hasMany(TipoDocumento::class, 'subseccion_legajo_id', 'id');
    }
}
