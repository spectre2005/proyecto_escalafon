<?php

namespace App\Models;

use Database\Factories\RolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rol extends Model
{
    /** @use HasFactory<RolFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'roles';

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        've_datos_sensibles',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            've_datos_sensibles' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function usuarioRoles(): HasMany
    {
        return $this->hasMany(UsuarioRol::class, 'rol_id', 'id');
    }
}
