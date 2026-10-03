<?php

namespace Database\Seeders;

use App\Models\FormacionAcademica;
use Illuminate\Database\Seeder;

class FormacionAcademicaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        FormacionAcademica::factory()->create();
    }
}
