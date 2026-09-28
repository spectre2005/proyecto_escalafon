<?php

namespace Database\Seeders;

use App\Models\VinculoAdenda;
use Illuminate\Database\Seeder;

class VinculoAdendaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        VinculoAdenda::factory()->create();
    }
}
