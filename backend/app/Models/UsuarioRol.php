<?php

namespace App\Models;

use Database\Factories\UsuarioRolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsuarioRol extends Model
{
    /** @use HasFactory<UsuarioRolFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'usuario_roles';

    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'rol_id',
        'ambito_tipo_personal',
        'unidad_organica_id',
        'vigente_desde',
        'vigente_hasta',
        'asignado_por',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'created_at' => 'datetime',
            'revocado_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id', 'id');
    }

    public function unidadOrganica(): BelongsTo
    {
        return $this->belongsTo(UnidadOrganica::class, 'unidad_organica_id', 'id');
    }

    public function asignadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'asignado_por', 'id');
    }
}
