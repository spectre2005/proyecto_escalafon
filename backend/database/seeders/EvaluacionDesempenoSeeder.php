<?php

namespace Database\Seeders;

use App\Models\EvaluacionDesempeno;
use Illuminate\Database\Seeder;

class EvaluacionDesempenoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        EvaluacionDesempeno::factory()->create();
    }
}
