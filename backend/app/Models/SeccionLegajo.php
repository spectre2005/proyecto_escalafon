<?php

namespace App\Models;

use Database\Factories\SeccionLegajoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeccionLegajo extends Model
{
    /** @use HasFactory<SeccionLegajoFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $table = 'secciones_legajo';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'nombre',
        'descripcion',
        'es_nucleo_comun',
        'orden',
        'activa',
        'disponibilidad',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'es_nucleo_comun' => 'boolean',
            'orden' => 'integer',
            'activa' => 'boolean',
        ];
    }

    public function subseccionesLegajo(): HasMany
    {
        return $this->hasMany(SubseccionLegajo::class, 'seccion_legajo_id', 'id');
    }

    public function tiposDocumento(): HasMany
    {
        return $this->hasMany(TipoDocumento::class, 'seccion_legajo_id', 'id');
    }

    public function legajoSecciones(): HasMany
    {
        return $this->hasMany(LegajoSeccion::class, 'seccion_legajo_id', 'id');
    }

    public function documentosUsuario(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'seccion_legajo_id', 'id');
    }
}
