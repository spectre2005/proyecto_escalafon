<?php

namespace Database\Seeders;

use App\Models\PeriodoVacacional;
use Illuminate\Database\Seeder;

class PeriodoVacacionalSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        PeriodoVacacional::factory()->create();
    }
}
