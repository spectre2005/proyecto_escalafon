<?php

namespace Database\Seeders;

use App\Models\SolicitudFiscalizacion;
use Illuminate\Database\Seeder;

class SolicitudFiscalizacionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        SolicitudFiscalizacion::factory()->create();
    }
}
