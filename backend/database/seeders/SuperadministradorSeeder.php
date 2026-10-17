<?php

namespace Database\Seeders;

use App\Models\Plataforma\AdministradorPlataforma;
use Illuminate\Database\Seeder;

/**
 * Superadministrador de prueba del panel de plataforma (solo local/testing), para que todos los equipos que clonan el
 * proyecto entren con la misma cuenta. En producción la cuenta se crea con plataforma:crear-administrador.
 */
class SuperadministradorSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $email = mb_strtolower(trim((string) config('plataforma.administrador_prueba.email')));
        $password = (string) config('plataforma.administrador_prueba.password');
        if ($email === '' || $password === '') {
            $this->command?->warn('SuperadministradorSeeder: define PLATAFORMA_ADMIN_EMAIL y PLATAFORMA_ADMIN_PASSWORD en backend/.env. No se creó el superadministrador.');

            return;
        }

        AdministradorPlataforma::query()->firstOrCreate(['email' => $email], [
            'nombre' => 'Superadministrador de prueba', 'password' => $password, 'activo' => true,
        ]);
    }
}
