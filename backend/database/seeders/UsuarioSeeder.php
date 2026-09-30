<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;

class UsuarioSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $users = [
            ['99000001', 'Persona Demo 01', 'Ficticia', 'Uno', 'demo01@example.test', 'docente', '276', true],
            ['99000002', 'Persona Demo 02', 'Ficticia', 'Dos', 'demo02@example.test', 'administrativo', '728', false],
            ['99000003', 'Persona Demo 03', 'Ficticia', 'Tres', 'demo03@example.test', 'docente', 'CAS', false],
            ['99000004', 'Persona Demo 04', 'Ficticia', 'Cuatro', 'demo04@example.test', 'administrativo', '276', true],
            ['99000005', 'Persona Demo 05', 'Ficticia', 'Cinco', 'demo05@example.test', 'docente', '728', false],
            ['99000006', 'Persona Demo 06', 'Ficticia', 'Seis', 'demo06@example.test', 'administrativo', 'CAS', false],
            ['99000007', 'Persona Demo 07', 'Ficticia', 'Siete', 'demo07@example.test', 'docente', '276', true],
            ['99000008', 'Persona Demo 08', 'Ficticia', 'Ocho', 'demo08@example.test', 'administrativo', '728', false],
            ['99000009', 'Persona Demo 09', 'Ficticia', 'Nueve', 'demo09@example.test', 'docente', 'CAS', false],
            ['99000010', 'Persona Demo 10', 'Ficticia', 'Diez', 'demo10@example.test', 'administrativo', '276', false],
        ];

        foreach ($users as [$document, $names, $lastName, $secondLastName, $email, $type, $regime, $active]) {
            Usuario::query()->firstOrCreate(
                ['numero_doc_identidad' => $document],
                [
                    'nombres' => $names,
                    'apellido_paterno' => $lastName,
                    'apellido_materno' => $secondLastName,
                    'email_institucional' => $email,
                    'password' => 'demo-no-login',
                    'tipo_personal' => $type,
                    'regimen_laboral' => $regime,
                    'activo' => $active,
                ],
            );
        }
    }
}
