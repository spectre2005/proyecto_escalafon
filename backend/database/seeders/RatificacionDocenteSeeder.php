<?php

namespace Database\Seeders;

use App\Models\RatificacionDocente;
use Illuminate\Database\Seeder;

class RatificacionDocenteSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        RatificacionDocente::factory()->create();
    }
}
