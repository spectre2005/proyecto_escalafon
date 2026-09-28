<?php

namespace Database\Seeders;

use App\Models\ProcesoFiscalizacion;
use Illuminate\Database\Seeder;

class ProcesoFiscalizacionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        ProcesoFiscalizacion::factory()->create();
    }
}
