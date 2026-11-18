<?php

namespace App\Models;

use App\Support\CatalogosVinculo;
use Database\Factories\PuestoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Puesto de la lista de la institución (denominación que se ofrece al registrar un vínculo). El vínculo guarda su propio
 * texto (vinculos_laborales.nombre_puesto): corregir aquí el nombre no cambia los vínculos ya registrados.
 */
class Puesto extends Model
{
    /** @use HasFactory<PuestoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'puestos';

    protected $fillable = ['nombre', 'tipo_personal', 'activo', 'registrado_por'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activo' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Puesto $puesto): void {
            $puesto->nombre = CatalogosVinculo::limpiar($puesto->nombre);
            $puesto->nombre_normalizado = CatalogosVinculo::normalizar($puesto->nombre);
        });
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
