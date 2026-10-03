<?php

namespace Database\Seeders;

use App\Models\CertificadoDiscapacidad;
use Illuminate\Database\Seeder;

class CertificadoDiscapacidadSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        CertificadoDiscapacidad::factory()->create();
    }
}
