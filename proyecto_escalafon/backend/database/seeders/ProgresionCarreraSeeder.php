<?php

namespace Database\Seeders;

use App\Models\ProgresionCarrera;
use Illuminate\Database\Seeder;

class ProgresionCarreraSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        ProgresionCarrera::factory()->create();
    }
}
