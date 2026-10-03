<?php

namespace Database\Factories;

use App\Models\ArchivoDocumento;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArchivoDocumento> */
class ArchivoDocumentoFactory extends Factory
{
    protected $model = ArchivoDocumento::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'storage_path' => substr(fake()->word(), 0, 500),
            'nombre_original' => substr(fake()->word(), 0, 255),
            'tamano_bytes' => 1,
            'hash_sha256' => hash('sha256', fake()->uuid()),
            'subido_por' => Usuario::factory(),
        ];
    }
}
