<?php

namespace Database\Seeders;

use App\Models\Feriado;
use Illuminate\Database\Seeder;

class FeriadoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Feriado::factory()->create();
    }
}
