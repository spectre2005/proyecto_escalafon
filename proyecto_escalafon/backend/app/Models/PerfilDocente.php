<?php

namespace App\Models;

use Database\Factories\PerfilDocenteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerfilDocente extends Model
{
    /** @use HasFactory<PerfilDocenteFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'perfil_docente';

    protected $fillable = [
        'vinculo_laboral_id',
        'tipo_personal',
        'condicion_docente',
        'categoria',
        'dedicacion',
        'subtipo_extraordinario',
        'funcion_previa',
        'codigo_renacyt',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'vinculo_laboral_id', 'id');
    }

    public function ratificacionesDocentes(): HasMany
    {
        return $this->hasMany(RatificacionDocente::class, 'perfil_docente_id', 'id');
    }
}
