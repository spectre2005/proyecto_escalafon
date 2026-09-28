<?php

namespace Database\Seeders;

use App\Models\Sancion;
use Illuminate\Database\Seeder;

class SancionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Sancion::factory()->create();
    }
}
