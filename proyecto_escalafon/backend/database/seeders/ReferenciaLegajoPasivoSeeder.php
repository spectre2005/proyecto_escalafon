<?php

namespace Database\Seeders;

use App\Models\ReferenciaLegajoPasivo;
use Illuminate\Database\Seeder;

class ReferenciaLegajoPasivoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        ReferenciaLegajoPasivo::factory()->create();
    }
}
