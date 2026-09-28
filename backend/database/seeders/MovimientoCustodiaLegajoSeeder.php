<?php

namespace Database\Seeders;

use App\Models\MovimientoCustodiaLegajo;
use Illuminate\Database\Seeder;

class MovimientoCustodiaLegajoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        MovimientoCustodiaLegajo::factory()->create();
    }
}
