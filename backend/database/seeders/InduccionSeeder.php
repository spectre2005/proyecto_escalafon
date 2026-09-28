<?php

namespace Database\Seeders;

use App\Models\Induccion;
use Illuminate\Database\Seeder;

class InduccionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Induccion::factory()->create();
    }
}
