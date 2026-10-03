<?php

namespace Database\Seeders;

use App\Models\PerfilDocente;
use Illuminate\Database\Seeder;

class PerfilDocenteSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        PerfilDocente::factory()->create();
    }
}
