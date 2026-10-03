<?php

namespace Database\Seeders;

use App\Models\GoceVacacional;
use Illuminate\Database\Seeder;

class GoceVacacionalSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        GoceVacacional::factory()->create();
    }
}
