<?php

namespace Database\Seeders;

use App\Models\UsuarioRol;
use Illuminate\Database\Seeder;

class UsuarioRolSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        UsuarioRol::factory()->create();
    }
}
