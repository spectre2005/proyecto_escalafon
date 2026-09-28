<?php

namespace Database\Seeders;

use App\Models\CompensacionBeneficio;
use Illuminate\Database\Seeder;

class CompensacionBeneficioSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        CompensacionBeneficio::factory()->create();
    }
}
