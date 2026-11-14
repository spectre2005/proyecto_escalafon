<?php

namespace App\Models;

use Database\Factories\TipoVinculoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Tipo de vínculo de la directiva (carrera, plazo determinado, designado). Solo se edita su descripción. Catálogo de App\Support\CatalogosVinculo. */
class TipoVinculo extends Model
{
    /** @use HasFactory<TipoVinculoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'tipos_vinculo';

    protected $fillable = ['codigo', 'nombre', 'descripcion', 'orden'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }
}
