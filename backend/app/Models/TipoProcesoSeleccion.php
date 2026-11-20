<?php

namespace App\Models;

use Database\Factories\TipoProcesoSeleccionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Proceso de selección (proceso CAS, concurso público, concurso docente…). Catálogo de App\Support\CatalogosVinculo.
 * tipo_personal y regimen_normativo_id dicen a quién corresponde (vacíos = a todos).
 */
class TipoProcesoSeleccion extends Model
{
    /** @use HasFactory<TipoProcesoSeleccionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'tipos_proceso_seleccion';

    protected $fillable = ['codigo', 'nombre', 'tipo_personal', 'regimen_normativo_id', 'activo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
