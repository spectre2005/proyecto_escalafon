<?php

namespace Database\Seeders;

use App\Models\Legajo;
use Illuminate\Database\Seeder;

class LegajoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Legajo::factory()->create();
    }
}
