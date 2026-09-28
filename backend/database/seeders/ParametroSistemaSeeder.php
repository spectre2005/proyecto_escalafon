<?php

namespace Database\Seeders;

use App\Models\ParametroSistema;
use Illuminate\Database\Seeder;

class ParametroSistemaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        ParametroSistema::factory()->create();
    }
}
