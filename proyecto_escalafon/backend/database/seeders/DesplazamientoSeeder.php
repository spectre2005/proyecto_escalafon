<?php

namespace Database\Seeders;

use App\Models\Desplazamiento;
use Illuminate\Database\Seeder;

class DesplazamientoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Desplazamiento::factory()->create();
    }
}
