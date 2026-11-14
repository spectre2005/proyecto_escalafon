<?php

namespace App\Models;

use Database\Factories\PuestoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Denominación de puesto con los valores por defecto de sus condiciones. Catálogo de App\Support\CatalogosVinculo. */
class Puesto extends Model
{
    /** @use HasFactory<PuestoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'puestos';

    protected $fillable = ['codigo', 'nombre', 'tipo_personal', 'unidad_organica_id', 'grupo_ocupacional', 'es_cargo_confianza', 'obligado_djbr', 'exige_colegiatura', 'tiene_periodo_prueba', 'activo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['es_cargo_confianza' => 'boolean', 'obligado_djbr' => 'boolean', 'exige_colegiatura' => 'boolean', 'tiene_periodo_prueba' => 'boolean', 'activo' => 'boolean'];
    }

    public function unidadOrganica(): BelongsTo
    {
        return $this->belongsTo(UnidadOrganica::class, 'unidad_organica_id');
    }

    public function vinculosLaborales(): HasMany
    {
        return $this->hasMany(VinculoLaboral::class, 'puesto_id');
    }
}
