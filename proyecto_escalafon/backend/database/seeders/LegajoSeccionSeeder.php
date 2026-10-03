<?php

namespace Database\Seeders;

use App\Models\LegajoSeccion;
use Illuminate\Database\Seeder;

class LegajoSeccionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        LegajoSeccion::factory()->create();
    }
}
