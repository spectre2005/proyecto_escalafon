<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Seccion06CatalogoSeeder extends Seeder
{
    public const TIPOS = [
        ['COMP_RECONOCIMIENTO_TIEMPO', 'Documento de reconocimiento de tiempo de servicios', ['requiere_num_resolucion' => true]],
        ['COMP_PAGO_COMPENSACION', 'Pago de compensaciones económicas y no económicas', ['es_dato_sensible' => true]],
        ['COMP_RETENCION_TRIBUTARIA', 'Certificado de retención de quinta, cuarta categoría u otros', ['es_dato_sensible' => true]],
        ['COMP_LIQUIDACION_BENEFICIOS', 'Liquidación de beneficios sociales', ['es_dato_sensible' => true]],
        ['COMP_OTROS', 'Otros documentos de compensaciones', ['es_hook_universidad' => true]],
    ];

    public static function id(int $orden): string
    {
        return sprintf('00000006-0000-4000-8000-%012d', $orden);
    }

    public function run(): void
    {
        DB::transaction(function (): void {
            SeccionLegajo::query()->firstOrCreate(['id' => 6], ['nombre' => 'Compensaciones', 'es_nucleo_comun' => false, 'orden' => 6]);
            foreach (self::TIPOS as $indice => [$codigo, $nombre, $banderas]) {
                if (TipoDocumento::query()->where('seccion_legajo_id', 6)->where('codigo', $codigo)->exists()) {
                    continue;
                }
                $tipo = new TipoDocumento(['seccion_legajo_id' => 6, 'codigo' => $codigo, 'nombre' => $nombre, 'orden' => $indice + 1,
                    'descripcion' => $indice === 4 ? 'Otros documentos que requiera cada entidad, por la naturaleza de sus labores.' : 'Se incorpora cuando corresponda; no es un requisito universal.',
                    'obligatorio' => false, 'permite_multiples' => true, 'acredita_perfil_puesto' => false, 'requiere_fedateo' => false, ...$banderas]);
                $tipo->id = self::id($indice + 1);
                $tipo->save();
            }
        });
    }
}
