<?php

namespace App\Models;

use Database\Factories\EscalaNivelCarreraFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un nivel de la escala de carrera de una ley y un grupo ocupacional (catálogo de App\Support\CatalogosVinculo). */
class EscalaNivelCarrera extends Model
{
    /** @use HasFactory<EscalaNivelCarreraFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'escalas_nivel_carrera';

    protected $fillable = ['regimen_normativo_id', 'grupo_ocupacional', 'codigo', 'nombre', 'orden', 'activo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['orden' => 'integer', 'activo' => 'boolean'];
    }

    public function regimenNormativo(): BelongsTo
    {
        return $this->belongsTo(RegimenNormativo::class, 'regimen_normativo_id');
    }
}
