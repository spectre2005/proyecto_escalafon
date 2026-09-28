<?php

namespace Database\Seeders;

use App\Models\LicenciaPermiso;
use Illuminate\Database\Seeder;

class LicenciaPermisoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        LicenciaPermiso::factory()->create();
    }
}
