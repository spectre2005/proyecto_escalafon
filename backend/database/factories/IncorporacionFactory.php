<?php

namespace Database\Factories;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class IncorporacionFactory extends Factory
{
    public function definition(): array
    {
        return ['usuario_id' => Usuario::factory(), 'registrado_por' => Usuario::factory(), 'cargo' => 'Analista', 'estado' => 'registrado',
            'documentos' => [['id' => (string) Str::uuid(), 'nombre' => 'DNI', 'estado' => 'pendiente', 'versiones' => []]], 'tareas' => [], 'historial' => []];
    }
}
