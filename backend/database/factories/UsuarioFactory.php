<?php

namespace Database\Factories;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Usuario> */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'numero_doc_identidad' => fake()->unique()->numerify('########'),
            'apellido_paterno' => fake()->lastName(),
            'nombres' => fake()->firstName(),
            'email_institucional' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'tipo_personal' => 'administrativo',
            'regimen_laboral' => '276',
        ];
    }
}
