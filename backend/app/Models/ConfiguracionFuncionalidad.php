<?php

namespace App\Models;

use App\Support\ConfiguracionFuncionalidades;
use Database\Factories\ConfiguracionFuncionalidadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado guardado de una funcionalidad configurable. Se escribe solo desde App\Support\ConfiguracionFuncionalidades
 * (motivo, restricciones y auditoría); no tiene CRUD genérico.
 */
class ConfiguracionFuncionalidad extends Model
{
    /** @use HasFactory<ConfiguracionFuncionalidadFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'configuracion_funcionalidades';

    protected $fillable = [
        'codigo',
        'activa',
        'motivo',
        'updated_by',
    ];

    /** Cualquier cambio invalida los estados que el servicio guarda en memoria durante la petición. */
    protected static function booted(): void
    {
        static::saved(function (): void {
            ConfiguracionFuncionalidades::olvidar();
        });
        static::deleted(function (): void {
            ConfiguracionFuncionalidades::olvidar();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by', 'id');
    }
}
