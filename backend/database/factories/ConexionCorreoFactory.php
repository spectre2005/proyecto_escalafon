<?php

namespace Database\Factories;

use App\Models\ConexionCorreo;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConexionCorreo> Conexión de correo ficticia: tokens inventados, nunca credenciales reales. */
class ConexionCorreoFactory extends Factory
{
    protected $model = ConexionCorreo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'clave' => 'gmail_'.fake()->unique()->lexify('????????'),
            'email' => fake()->unique()->safeEmail(),
            'access_token' => 'token-ficticio-'.fake()->lexify('????????'),
            'refresh_token' => 'renovacion-ficticia-'.fake()->lexify('????????'),
            'expira_en' => now()->addHour(),
            'habilitada' => true,
            'conectado_por' => Usuario::factory(),
            'alcances' => ['https://www.googleapis.com/auth/gmail.send'],
        ];
    }
}
