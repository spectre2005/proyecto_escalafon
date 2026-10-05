<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;

class Seccion04CatalogoSeeder extends Seeder
{
    public const TIPOS = [
        ['EXP_CERTIFICADO_TRABAJO', 'Certificados y constancias de experiencia laboral', ['obligatorio' => true, 'acredita_perfil_puesto' => true, 'requiere_fedateo' => true]],
        ['EXP_RESOLUCION_FUNCIONES', 'Resoluciones y memorándums de asignación de funciones', ['requiere_num_resolucion' => true]],
        ['EXP_OTROS', 'Otros documentos de experiencia laboral', ['es_hook_universidad' => true]],
    ];

    public static function id(int $orden): string
    {
        return sprintf('00000004-0000-4000-8000-%012d', $orden);
    }

    public function run(): void
    {
        SeccionLegajo::query()->firstOrCreate(['id' => 4], ['nombre' => 'Experiencia laboral', 'es_nucleo_comun' => true, 'orden' => 4]);
        foreach (self::TIPOS as $indice => [$codigo,$nombre,$banderas]) {
            if (TipoDocumento::query()->where('seccion_legajo_id', 4)->where('codigo', $codigo)->exists()) {
                continue;
            }
            $tipo = new TipoDocumento(['seccion_legajo_id' => 4, 'codigo' => $codigo, 'nombre' => $nombre, 'orden' => $indice + 1, 'obligatorio' => false, 'permite_multiples' => true, 'es_universal' => true, ...$banderas]);
            $tipo->id = self::id($indice + 1);
            $tipo->save();
        }
    }
}
