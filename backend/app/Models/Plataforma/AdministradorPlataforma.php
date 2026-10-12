<?php

namespace App\Models\Plataforma;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Cuenta del panel de superadministrador (guard «plataforma»). Vive en la base de plataforma y no es un usuario
 * institucional: no tiene roles ni permisos de legajo y no sirve para las rutas institucionales (Sanctum usa el guard web).
 */
class AdministradorPlataforma extends Authenticatable
{
    use HasUuids;

    protected $connection = 'plataforma';

    protected $table = 'administradores_plataforma';

    protected $fillable = ['nombre', 'email', 'password', 'activo', 'ultimo_acceso'];

    protected $hidden = ['password'];

    /** Sin «recordarme»: la tabla no guarda remember_token. */
    protected $rememberTokenName = '';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'activo' => 'boolean', 'ultimo_acceso' => 'datetime'];
    }
}
