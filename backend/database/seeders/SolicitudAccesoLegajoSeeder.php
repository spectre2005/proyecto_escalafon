<?php

namespace Database\Seeders;

use App\Models\SolicitudAccesoLegajo;
use Illuminate\Database\Seeder;

class SolicitudAccesoLegajoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        SolicitudAccesoLegajo::factory()->create();
    }
}
