<?php

namespace Database\Seeders;

use App\Models\NotificacionUsuario;
use Illuminate\Database\Seeder;

class NotificacionUsuarioSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        NotificacionUsuario::factory()->create();
    }
}
