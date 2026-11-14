<?php

namespace App\Models;

use Database\Factories\ModalidadVinculoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Modalidad dentro de un tipo de vínculo (p. ej. CAS dentro de plazo determinado). Catálogo de App\Support\CatalogosVinculo. */
class ModalidadVinculo extends Model
{
    /** @use HasFactory<ModalidadVinculoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'modalidades_vinculo';

    protected $fillable = ['codigo', 'nombre', 'regimen_laboral', 'tipo_personal', 'regimen_normativo_id', 'tiene_periodo_prueba', 'exige_fin_previsto', 'activo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tiene_periodo_prueba' => 'boolean', 'exige_fin_previsto' => 'boolean', 'activo' => 'boolean'];
    }

    public function regimenNormativo(): BelongsTo
    {
        return $this->belongsTo(RegimenNormativo::class, 'regimen_normativo_id');
    }

    public function vinculosLaborales(): HasMany
    {
        return $this->hasMany(VinculoLaboral::class, 'modalidad_vinculo_id');
    }
}
