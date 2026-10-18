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

    protected $fillable = ['nombre', 'email', 'password', 'activo', 'ultimo_acceso', 'debe_cambiar_password'];

    protected $hidden = ['password'];

    /** Sin «recordarme»: la tabla no guarda remember_token. */
    protected $rememberTokenName = '';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'activo' => 'boolean', 'ultimo_acceso' => 'datetime', 'debe_cambiar_password' => 'boolean'];
    }

    /** Reglas de contraseña de un superadministrador (comando, panel y cambio de la propia). */
    public static function reglasPassword(): array
    {
        return ['required', 'string', 'min:12', 'max:255', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'];
    }

    /** @return array<string, string> */
    public static function mensajesPassword(string $campo = 'password'): array
    {
        return ["{$campo}.regex" => 'La contraseña debe tener letras y números.', "{$campo}.min" => 'La contraseña debe tener al menos 12 caracteres.',
            "{$campo}.confirmed" => 'Las contraseñas no coinciden.'];
    }

    /** Datos administrativos de la cuenta: nunca la contraseña ni su hash. @return array<string, mixed> */
    public function datos(): array
    {
        return [...$this->only(['id', 'nombre', 'email']), 'activo' => (bool) $this->activo, 'debe_cambiar_password' => (bool) $this->debe_cambiar_password,
            'ultimo_acceso' => $this->ultimo_acceso?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
