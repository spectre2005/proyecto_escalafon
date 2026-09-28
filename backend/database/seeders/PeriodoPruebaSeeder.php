<?php

namespace Database\Seeders;

use App\Models\PeriodoPrueba;
use Illuminate\Database\Seeder;

class PeriodoPruebaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        PeriodoPrueba::factory()->create();
    }
}
