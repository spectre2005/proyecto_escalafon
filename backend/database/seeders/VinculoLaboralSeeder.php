<?php

namespace Database\Seeders;

use App\Models\VinculoLaboral;
use Illuminate\Database\Seeder;

class VinculoLaboralSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        VinculoLaboral::factory()->create();
    }
}
