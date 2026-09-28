<?php

namespace Database\Seeders;

use App\Models\Capacitacion;
use Illuminate\Database\Seeder;

class CapacitacionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Capacitacion::factory()->create();
    }
}
