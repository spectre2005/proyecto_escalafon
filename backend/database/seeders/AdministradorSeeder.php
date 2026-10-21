<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Compatibilidad con instalaciones anteriores: sembrar datos nunca crea ni modifica cuentas de acceso. */
class AdministradorSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn('Los administradores se crean desde el panel de plataforma: institución → Administradores → Agregar administrador.');
    }
}
