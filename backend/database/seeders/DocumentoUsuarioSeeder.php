<?php

namespace Database\Seeders;

use App\Models\DocumentoUsuario;
use Illuminate\Database\Seeder;

class DocumentoUsuarioSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        DocumentoUsuario::factory()->create();
    }
}
