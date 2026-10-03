<?php

namespace Database\Seeders;

use App\Models\HistorialEstadoDocumento;
use Illuminate\Database\Seeder;

class HistorialEstadoDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        HistorialEstadoDocumento::factory()->create();
    }
}
