<?php

namespace Database\Seeders;

use App\Models\ArchivoDocumento;
use Illuminate\Database\Seeder;

class ArchivoDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        ArchivoDocumento::factory()->create();
    }
}
