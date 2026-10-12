<?php

namespace App\Console\Commands;

use App\Models\Plataforma\AdministradorPlataforma;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Crea una cuenta de superadministrador de la plataforma. Es la única forma de crearla (no hay registro público ni
 * credenciales predeterminadas): la contraseña se escribe dos veces sin mostrarse y no se acepta por parámetro.
 */
class CrearAdministradorPlataforma extends Command
{
    protected $signature = 'plataforma:crear-administrador {--nombre= : Nombre de la persona} {--email= : Correo de acceso}';

    protected $description = 'Crea una cuenta de superadministrador de la plataforma (pide la contraseña de forma interactiva)';

    public function handle(): int
    {
        $nombre = trim((string) ($this->option('nombre') ?: $this->ask('Nombre')));
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Correo de acceso'))));
        $password = (string) $this->secret('Contraseña (mínimo 12 caracteres, con letras y números)');
        $confirmacion = (string) $this->secret('Repite la contraseña');

        $validacion = Validator::make(['nombre' => $nombre, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmacion], [
            'nombre' => 'required|string|min:3|max:150',
            'email' => 'required|email:rfc|max:255|unique:plataforma.administradores_plataforma,email',
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ], ['password.regex' => 'La contraseña debe tener letras y números.', 'password.confirmed' => 'Las contraseñas no coinciden.']);
        if ($validacion->fails()) {
            foreach ($validacion->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $administrador = AdministradorPlataforma::query()->create(['nombre' => $nombre, 'email' => $email, 'password' => $password, 'activo' => true]);
        AuditoriaOperaciones::registrar('administrador.creado', null, null, null, null, $administrador->only(['id', 'nombre', 'email']), 'Creado con plataforma:crear-administrador.');
        $this->info("Superadministrador creado: {$email}.");

        return self::SUCCESS;
    }
}
