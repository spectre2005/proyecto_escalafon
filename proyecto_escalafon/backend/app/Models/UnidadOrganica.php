<?php

namespace App\Models;

use Database\Factories\UnidadOrganicaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnidadOrganica extends Model
{
    /** @use HasFactory<UnidadOrganicaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'unidades_organicas';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'unidad_padre_id',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function unidadPadre(): BelongsTo
    {
        return $this->belongsTo(UnidadOrganica::class, 'unidad_padre_id', 'id');
    }

    public function unidadesOrganicasPorUnidadPadre(): HasMany
    {
        return $this->hasMany(UnidadOrganica::class, 'unidad_padre_id', 'id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'unidad_organica_id', 'id');
    }

    public function usuarioRoles(): HasMany
    {
        return $this->hasMany(UsuarioRol::class, 'unidad_organica_id', 'id');
    }

    public function vinculosLaborales(): HasMany
    {
        return $this->hasMany(VinculoLaboral::class, 'unidad_organica_id', 'id');
    }

    public function desplazamientosPorUnidadOrigen(): HasMany
    {
        return $this->hasMany(Desplazamiento::class, 'unidad_origen_id', 'id');
    }

    public function desplazamientosPorUnidadDestino(): HasMany
    {
        return $this->hasMany(Desplazamiento::class, 'unidad_destino_id', 'id');
    }
}
