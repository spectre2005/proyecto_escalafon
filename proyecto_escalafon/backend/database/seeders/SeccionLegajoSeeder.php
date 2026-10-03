<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use Illuminate\Database\Seeder;

class SeccionLegajoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        SeccionLegajo::factory()->create();
    }
}
