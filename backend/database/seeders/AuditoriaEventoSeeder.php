<?php

namespace Database\Seeders;

use App\Models\AuditoriaEvento;
use Illuminate\Database\Seeder;

class AuditoriaEventoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        AuditoriaEvento::factory()->create();
    }
}
