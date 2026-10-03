<?php

namespace Database\Seeders;

use App\Models\RegistroSstBienestar;
use Illuminate\Database\Seeder;

class RegistroSstBienestarSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        RegistroSstBienestar::factory()->create();
    }
}
