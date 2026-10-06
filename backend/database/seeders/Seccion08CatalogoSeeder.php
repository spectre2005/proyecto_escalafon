<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Seccion08CatalogoSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string, 3: array<string, bool>}> */
    public const TIPOS = [
        ['REC_CARTA_FELICITACION', 'Cartas, oficios, memorando de felicitación, condecoraciones, o diplomas otorgados en reconocimiento por excepcional participación en proyectos o eventos.', 'Literal 8.1.a de la Directiva.', []],
        ['REC_LABOR_DOCENTE', 'Certificados o constancias de reconocimiento excepcional por labor docente.', 'Literal 8.1.b de la Directiva.', ['requiere_docente' => true]],
        ['REC_TIEMPO_SERVICIOS', 'Certificados o constancias de reconocimientos por tiempo de servicios.', 'Literal 8.1.c de la Directiva.', []],
        ['SAN_RESOLUCION_SANCION', 'Documentos que formalizan y comunican las sanciones de amonestación escrita, suspensión y destitución.', 'Literal 8.2.a de la Directiva.', ['requiere_num_resolucion' => true, 'es_dato_sensible' => true]],
        ['SAN_RESOLUCION_RECURSO', 'Documentos por los que se declare fundado, infundado o improcedente los recursos impugnatorios relativos a las sanciones impuestas.', 'Literal 8.2.b de la Directiva.', ['requiere_num_resolucion' => true, 'es_dato_sensible' => true]],
    ];

    public static function id(int $orden): string
    {
        return sprintf('00000008-0000-4000-8000-%012d', $orden);
    }

    public function run(): void
    {
        DB::transaction(function (): void {
            SeccionLegajo::query()->firstOrCreate(['id' => 8], [
                'nombre' => 'Reconocimientos y sanciones', 'es_nucleo_comun' => false, 'orden' => 8,
            ]);

            foreach (self::TIPOS as $indice => [$codigo, $nombre, $descripcion, $banderas]) {
                $tipo = TipoDocumento::query()->firstOrNew(['seccion_legajo_id' => 8, 'codigo' => $codigo]);
                $tipo->fill([
                    'seccion_legajo_id' => 8, 'codigo' => $codigo, 'nombre' => $nombre,
                    'descripcion' => $descripcion, 'base_normativa' => 'SERVIR',
                    'obligatorio' => false, 'permite_multiples' => true,
                    'requiere_fedateo' => false, 'es_hook_universidad' => false,
                    'activo' => true, 'orden' => $indice + 1,
                    'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true,
                    ...$banderas,
                ]);
                $tipo->id ??= self::id($indice + 1);
                $tipo->save();
            }
        });
    }
}
