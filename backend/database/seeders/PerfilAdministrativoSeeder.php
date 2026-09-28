<?php

namespace Database\Seeders;

use App\Models\PerfilAdministrativo;
use Illuminate\Database\Seeder;

class PerfilAdministrativoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        PerfilAdministrativo::factory()->create();
    }
}
