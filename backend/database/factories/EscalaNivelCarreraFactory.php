<?php

namespace Database\Factories;

use App\Models\EscalaNivelCarrera;
use App\Models\RegimenNormativo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EscalaNivelCarrera> */
class EscalaNivelCarreraFactory extends Factory
{
    protected $model = EscalaNivelCarrera::class;

    public function definition(): array
    {
        return [
            'regimen_normativo_id' => RegimenNormativo::factory(),
            'grupo_ocupacional' => 'tecnico',
            'codigo' => 'N-'.fake()->unique()->numerify('###'),
            'orden' => 1,
            'activo' => true,
        ];
    }
}
