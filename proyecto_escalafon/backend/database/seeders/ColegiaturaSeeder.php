<?php

namespace Database\Seeders;

use App\Models\Colegiatura;
use Illuminate\Database\Seeder;

class ColegiaturaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Colegiatura::factory()->create();
    }
}
