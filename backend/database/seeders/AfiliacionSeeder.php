<?php

namespace Database\Seeders;

use App\Models\Afiliacion;
use Illuminate\Database\Seeder;

class AfiliacionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Afiliacion::factory()->create();
    }
}
