<?php

namespace Database\Factories;

use App\Models\LecturaIa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LecturaIa> */
class LecturaIaFactory extends Factory
{
    protected $model = LecturaIa::class;

    public function definition(): array
    {
        return [
            'usuario_id' => (string) Str::uuid(),
            'tipo_documento_id' => (string) Str::uuid(),
            'registrado_por' => (string) Str::uuid(),
            'modelo' => 'qwen3:1.7b',
            'segundos' => fake()->randomFloat(1, 5, 120),
            'titular' => 'coincide',
            'campos_pedidos' => ['emisor', 'fecha_emision'],
            'campos_propuestos' => ['emisor'],
            'campos_sugeridos' => [],
            'resultado' => null,
        ];
    }
}
