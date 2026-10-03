<?php

namespace Database\Seeders;

use App\Models\Reconocimiento;
use Illuminate\Database\Seeder;

class ReconocimientoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Reconocimiento::factory()->create();
    }
}
