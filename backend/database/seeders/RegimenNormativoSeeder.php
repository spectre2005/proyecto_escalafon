<?php

namespace Database\Seeders;

use App\Models\RegimenNormativo;
use Illuminate\Database\Seeder;

class RegimenNormativoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        RegimenNormativo::factory()->create();
    }
}
