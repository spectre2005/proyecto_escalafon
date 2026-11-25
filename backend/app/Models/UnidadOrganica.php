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
        'registrado_por',
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

    /** @return BelongsTo<Usuario, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }

    /** @return BelongsTo<UnidadOrganica, $this> */
    public function unidadPadre(): BelongsTo
    {
        return $this->belongsTo(UnidadOrganica::class, 'unidad_padre_id', 'id');
    }

    /** @return HasMany<UnidadOrganica, $this> */
    public function unidadesOrganicasPorUnidadPadre(): HasMany
    {
        return $this->hasMany(UnidadOrganica::class, 'unidad_padre_id', 'id');
    }

    /** @return HasMany<Usuario, $this> */
    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'unidad_organica_id', 'id');
    }

    /** @return HasMany<UsuarioRol, $this> */
    public function usuarioRoles(): HasMany
    {
        return $this->hasMany(UsuarioRol::class, 'unidad_organica_id', 'id');
    }

    /** @return HasMany<VinculoLaboral, $this> */
    public function vinculosLaborales(): HasMany
    {
        return $this->hasMany(VinculoLaboral::class, 'unidad_organica_id', 'id');
    }

    /** @return HasMany<Desplazamiento, $this> */
    public function desplazamientosPorUnidadOrigen(): HasMany
    {
        return $this->hasMany(Desplazamiento::class, 'unidad_origen_id', 'id');
    }

    /** @return HasMany<Desplazamiento, $this> */
    public function desplazamientosPorUnidadDestino(): HasMany
    {
        return $this->hasMany(Desplazamiento::class, 'unidad_destino_id', 'id');
    }
}
