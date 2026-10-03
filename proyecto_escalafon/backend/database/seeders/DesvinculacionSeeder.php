<?php

namespace Database\Seeders;

use App\Models\Desvinculacion;
use Illuminate\Database\Seeder;

class DesvinculacionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Desvinculacion::factory()->create();
    }
}
