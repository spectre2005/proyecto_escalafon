<?php

namespace Database\Seeders;

use App\Models\ModalidadVinculo;
use App\Models\RegimenNormativo;
use App\Support\CatalogosVinculo;
use Illuminate\Database\Seeder;

/**
 * Modalidades de vínculo de partida, comunes a toda institución e idempotentes (también en producción). Son las mismas
 * equivalencias que ya usa la sugerencia del tipo de vínculo (App\Support\RegimenesLaborales::sugerencia): docente
 * ordinario → carrera, docente contratado y CAS → plazo determinado, cargo de confianza → designado, D.L. 276 → carrera.
 * TODO pendiente de confirmar con el cliente: la lista completa de modalidades. La ORH agrega, corrige o desactiva las
 * suyas desde Configuración o desde el registro de personal.
 */
class ModalidadVinculoSeeder extends Seeder
{
    public function run(): void
    {
        $ley = fn (string $codigo): ?string => RegimenNormativo::query()->where('codigo', $codigo)->value('id');
        foreach ([
            ['Docente ordinario', 'carrera', 'docente', '30220'],
            ['Nombrado (D.L. 276)', 'carrera', 'administrativo', '276'],
            ['Docente contratado', 'plazo_determinado', 'docente', '30220'],
            ['CAS (D.L. 1057)', 'plazo_determinado', 'administrativo', '1057'],
            ['Cargo de confianza', 'designado', 'administrativo', null],
        ] as [$nombre, $regimen, $tipo, $codigoLey]) {
            if (ModalidadVinculo::query()->where('nombre', $nombre)->where('regimen_laboral', $regimen)->exists()) {
                continue;
            }
            ModalidadVinculo::query()->create(['codigo' => CatalogosVinculo::siguienteCodigo(ModalidadVinculo::class), 'nombre' => $nombre,
                'regimen_laboral' => $regimen, 'tipo_personal' => $tipo, 'regimen_normativo_id' => $codigoLey ? $ley($codigoLey) : null, 'activo' => true]);
        }
    }
}
