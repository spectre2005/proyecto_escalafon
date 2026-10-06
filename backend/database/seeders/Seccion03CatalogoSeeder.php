<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;

class Seccion03CatalogoSeeder extends Seeder
{
    public const TIPOS = [
        ['FORM_ESTUDIOS_BASICOS', 'Certificado oficial de estudios básicos', ['requiere_estudios_basicos' => true, 'acredita_perfil_puesto' => true]],
        ['FORM_TITULO_GRADO_SUPERIOR', 'Título o grado de estudios superiores', ['acredita_perfil_puesto' => true, 'requiere_fedateo' => true, 'es_universal' => true]],
        ['FORM_COLEGIATURA', 'Colegiatura y habilitación profesional', ['requiere_colegiatura' => true, 'acredita_perfil_puesto' => true]],
        ['FORM_POSTGRADO', 'Diplomados y especialización', ['obligatorio' => false, 'permite_multiples' => true]],
        ['FORM_MAESTRIA_DOCTORADO', 'Diploma de Maestría o Doctorado', ['requiere_docente' => true, 'permite_multiples' => true, 'base_normativa' => 'LEY_30220']],
        ['FORM_CAPACITACION', 'Cursos y programas de capacitación', ['obligatorio' => false, 'permite_multiples' => true]],
        ['FORM_CONGRESOS', 'Congresos, seminarios y talleres', ['obligatorio' => false, 'permite_multiples' => true]],
        ['FORM_OTROS', 'Otros documentos de formación y capacitación', ['obligatorio' => false, 'permite_multiples' => true]],
    ];

    public static function id(int $orden): string
    {
        return sprintf('00000003-0000-4000-8000-%012d', $orden);
    }

    public function run(): void
    {
        SeccionLegajo::query()->firstOrCreate(['id' => 3], [
            'nombre' => 'Formación académica y capacitación', 'es_nucleo_comun' => true, 'orden' => 3,
        ]);
        foreach (self::TIPOS as $indice => [$codigo, $nombre, $banderas]) {
            if (TipoDocumento::query()->where('seccion_legajo_id', 3)->where('codigo', $codigo)->exists()) {
                continue;
            }
            $tipo = new TipoDocumento([
                'seccion_legajo_id' => 3, 'codigo' => $codigo, 'nombre' => $nombre, 'orden' => $indice + 1,
                'obligatorio' => true, 'permite_multiples' => false,
                'tiene_banderas' => isset($banderas['requiere_estudios_basicos']) || isset($banderas['requiere_colegiatura']) || isset($banderas['requiere_docente']),
                ...$banderas,
            ]);
            $tipo->id = self::id($indice + 1);
            $tipo->save();
        }
    }
}
