<?php

namespace App\Models;

use Database\Factories\ProcesoSeleccionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcesoSeleccion extends Model
{
    /** @use HasFactory<ProcesoSeleccionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'procesos_seleccion';

    protected $fillable = ['regimen_normativo_id', 'codigo', 'anio'];

    protected function casts(): array
    {
        return ['anio' => 'integer'];
    }

    public function regimenNormativo(): BelongsTo
    {
        return $this->belongsTo(RegimenNormativo::class);
    }

    public function vinculosLaborales(): HasMany
    {
        return $this->hasMany(VinculoLaboral::class);
    }
}
