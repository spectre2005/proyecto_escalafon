<?php

namespace Database\Seeders;

use App\Models\TiempoServicioReconocido;
use Illuminate\Database\Seeder;

class TiempoServicioReconocidoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        TiempoServicioReconocido::factory()->create();
    }
}
