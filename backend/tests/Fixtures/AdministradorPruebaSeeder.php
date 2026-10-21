<?php

namespace Tests\Fixtures;

use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Fixture exclusiva de PHPUnit; ninguna cuenta real se obtiene de configuración. */
class AdministradorPruebaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        $email = mb_strtolower(trim((string) config('pruebas.administrador.email')));
        $password = (string) config('pruebas.administrador.password');
        if ($email === '' || $password === '') {
            $this->command?->warn('No se configuró la cuenta aislada de prueba.');

            return;
        }

        DB::transaction(function () use ($email, $password): void {
            $admin = Usuario::query()->firstOrCreate(['email_institucional' => $email], [
                'tipo_doc_identidad' => 'sistema', 'numero_doc_identidad' => 'ADMIN-SISTEMA',
                'nombres' => 'Administrador', 'apellido_paterno' => 'Prueba',
                'tipo_personal' => 'administrativo', 'regimen_laboral' => 'No aplica',
                'password' => $password, 'activo' => true,
            ]);
            $role = Rol::query()->firstOrCreate(['codigo' => 'ADMIN'], [
                'nombre' => 'Administrador', 'descripcion' => 'Administracion del sistema',
                've_datos_sensibles' => true, 'activo' => true,
            ]);
            UsuarioRol::query()->firstOrCreate(['usuario_id' => $admin->id, 'rol_id' => $role->id], [
                'ambito_tipo_personal' => 'ambos', 'vigente_desde' => '2020-01-01', 'asignado_por' => $admin->id,
            ]);
        });
    }
}
