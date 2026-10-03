<?php

namespace Database\Seeders;

use App\Models\ExperienciaLaboral;
use Illuminate\Database\Seeder;

class ExperienciaLaboralSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        ExperienciaLaboral::factory()->create();
    }
}
