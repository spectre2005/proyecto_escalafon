<?php

namespace Database\Seeders;

use App\Models\RegimenNormativo;
use Illuminate\Database\Seeder;

class RegimenNormativoSeeder extends Seeder
{
    public function run(): void
    {
        // Catálogo nacional e idempotente: también en producción (lo carga el aprovisionamiento de cada institución).
        foreach ([
            ['30220', 'Ley N° 30220 - Ley Universitaria', 'docente'],
            ['1057', 'Decreto Legislativo N° 1057 (CAS)', 'administrativo'],
            ['276', 'Decreto Legislativo N° 276', 'administrativo'],
            ['728', 'Decreto Legislativo N° 728', 'administrativo'],
            ['30057', 'Ley N° 30057 - Ley del Servicio Civil', 'administrativo'],
        ] as [$code, $name, $staffType]) {
            RegimenNormativo::query()->firstOrCreate(['codigo' => $code], [
                'nombre' => $name, 'aplica_a' => $staffType, 'activo' => true,
            ]);
        }
    }
}
