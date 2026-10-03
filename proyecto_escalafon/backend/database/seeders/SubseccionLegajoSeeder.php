<?php

namespace Database\Seeders;

use App\Models\SubseccionLegajo;
use Illuminate\Database\Seeder;

class SubseccionLegajoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        SubseccionLegajo::factory()->create();
    }
}
