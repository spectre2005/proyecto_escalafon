<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Compatibilidad con instalaciones anteriores: sembrar datos nunca crea ni modifica cuentas de acceso. */
class SuperadministradorSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn('Crea el primer superadministrador con php artisan plataforma:crear-administrador. Las demás cuentas se gestionan desde el panel.');
    }
}
