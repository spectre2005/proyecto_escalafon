<?php

namespace Database\Factories;

use App\Models\PerfilAdministrativo;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PerfilAdministrativo> */
class PerfilAdministrativoFactory extends Factory
{
    protected $model = PerfilAdministrativo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vinculo_laboral_id' => VinculoLaboral::factory(),
        ];
    }
}
