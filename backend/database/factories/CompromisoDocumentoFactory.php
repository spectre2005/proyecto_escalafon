<?php

namespace Database\Factories;

use App\Models\CompromisoDocumento;
use App\Models\CompromisoEntregaOriginal;
use App\Models\TipoDocumento;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompromisoDocumento> */
class CompromisoDocumentoFactory extends Factory
{
    protected $model = CompromisoDocumento::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'compromiso_id' => CompromisoEntregaOriginal::factory(),
            'tipo_documento_id' => TipoDocumento::factory(),
        ];
    }
}
