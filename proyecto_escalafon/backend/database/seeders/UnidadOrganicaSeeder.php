<?php

namespace Database\Seeders;

use App\Models\UnidadOrganica;
use Illuminate\Database\Seeder;

class UnidadOrganicaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        UnidadOrganica::factory()->create();
    }
}
