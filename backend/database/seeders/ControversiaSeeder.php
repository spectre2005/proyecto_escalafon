<?php

namespace Database\Seeders;

use App\Models\Controversia;
use Illuminate\Database\Seeder;

class ControversiaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Controversia::factory()->create();
    }
}
