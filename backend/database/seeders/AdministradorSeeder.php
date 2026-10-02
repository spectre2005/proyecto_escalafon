<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdministradorSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $admin = Usuario::query()->firstOrCreate(['email_institucional' => 'admin@uniq.edu.pe'], [
                'tipo_doc_identidad' => 'sistema',
                'numero_doc_identidad' => 'ADMIN-SISTEMA',
                'nombres' => 'Administrador',
                'apellido_paterno' => 'Sistema',
                'tipo_personal' => 'administrativo',
                'regimen_laboral' => 'No aplica',
                'password' => '123456',
                'activo' => true,
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
