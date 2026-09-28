<?php

namespace Database\Seeders;

use App\Models\CompromisoDocumento;
use Illuminate\Database\Seeder;

class CompromisoDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        CompromisoDocumento::factory()->create();
    }
}
