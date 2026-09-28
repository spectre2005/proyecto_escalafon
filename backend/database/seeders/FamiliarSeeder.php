<?php

namespace Database\Seeders;

use App\Models\Familiar;
use Illuminate\Database\Seeder;

class FamiliarSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Familiar::factory()->create();
    }
}
