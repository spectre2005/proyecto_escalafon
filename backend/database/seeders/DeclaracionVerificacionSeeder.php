<?php

namespace Database\Seeders;

use App\Models\DeclaracionVerificacion;
use Illuminate\Database\Seeder;

class DeclaracionVerificacionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        DeclaracionVerificacion::factory()->create();
    }
}
