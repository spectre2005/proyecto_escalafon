<?php

namespace Database\Seeders;

use App\Models\RequerimientoDocumento;
use Illuminate\Database\Seeder;

class RequerimientoDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        RequerimientoDocumento::factory()->create();
    }
}
