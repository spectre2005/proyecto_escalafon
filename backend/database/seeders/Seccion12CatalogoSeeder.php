<?php

namespace Database\Seeders;

use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;

class Seccion12CatalogoSeeder extends Seeder
{
    public function run(): void
    {
        (new SeccionesLegajoCatalogoSeeder)->run();
        foreach (['S12_OTROS_DOCUMENTOS' => ['Otros documentos institucionales', false], 'S12_OTROS_SENSIBLES' => ['Otros documentos con datos sensibles o de salud', true]] as $codigo => [$nombre, $sensible]) {
            TipoDocumento::query()->firstOrCreate(['seccion_legajo_id' => 12, 'codigo' => $codigo], [
                'nombre' => $nombre, 'orden' => $sensible ? 2 : 1, 'obligatorio' => false, 'permite_multiples' => true,
                'descripcion' => 'Documentos adicionales considerados por la entidad, incluidos informes y respuestas de fiscalización. No es un requisito universal.',
                'es_hook_universidad' => true, 'es_dato_sensible' => $sensible, 'es_dato_salud' => false,
                'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true,
                'requiere_fedateo' => false, 'acredita_perfil_puesto' => false,
            ]);
        }
    }
}
