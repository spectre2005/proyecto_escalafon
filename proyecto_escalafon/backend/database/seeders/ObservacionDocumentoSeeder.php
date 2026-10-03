<?php

namespace Database\Seeders;

use App\Models\ObservacionDocumento;
use Illuminate\Database\Seeder;

class ObservacionDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        ObservacionDocumento::factory()->create();
    }
}
