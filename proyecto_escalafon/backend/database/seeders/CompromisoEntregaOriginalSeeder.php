<?php

namespace Database\Seeders;

use App\Models\CompromisoEntregaOriginal;
use Illuminate\Database\Seeder;

class CompromisoEntregaOriginalSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        CompromisoEntregaOriginal::factory()->create();
    }
}
