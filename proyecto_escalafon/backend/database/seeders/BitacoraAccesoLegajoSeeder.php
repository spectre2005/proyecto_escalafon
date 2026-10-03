<?php

namespace Database\Seeders;

use App\Models\BitacoraAccesoLegajo;
use Illuminate\Database\Seeder;

class BitacoraAccesoLegajoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        BitacoraAccesoLegajo::factory()->create();
    }
}
