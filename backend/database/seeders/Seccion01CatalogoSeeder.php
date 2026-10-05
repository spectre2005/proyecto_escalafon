<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de la sección 01 (Información personal y familiar) según la Directiva N° 001-2023-SERVIR-GDSRH:
 * tipos de documento de los literales a a l del Anexo 01, el espacio para otros documentos de la universidad
 * y dos tipos que solo figuran en el checklist del Anexo 05.
 *
 * La directiva no define subsecciones para la sección 01: subseccion_legajo_id queda vacío.
 *
 * Idempotente, con las mismas reglas que Seccion02CatalogoSeeder:
 * - Un tipo se identifica por (sección 1, código, activo). Si no existe, se crea.
 * - Un tipo sin documentos asociados se corrige con los valores de abajo.
 * - Un tipo con documentos no se altera; si está mal puesto se desactiva y se crea uno nuevo.
 * - formulario_datos ya registrado con otro valor no se pisa: se avisa en consola.
 * - Tipos de la sección 1 que no están en esta lista se desactivan (activo = false), nunca se borran.
 */
class Seccion01CatalogoSeeder extends Seeder
{
    public const SECCION = 1;

    /**
     * TODO pendiente de confirmar con el cliente:
     * - El Anexo 01 no distingue regímenes: aplica_* = true en todos.
     * - f) no se ata a ninguna bandera: el esquema no tiene una que identifique los cargos de libre designación
     *   y remoción (Ley N° 31419). Hasta confirmarlo es opcional (se incorpora si existe).
     * - g), i) y j) no se atan a tiene_derechohabientes ni al estado civil: la directiva no lo dice. Hasta
     *   confirmarlo son opcionales, porque solo existen si hay cónyuge, concubino/a o hijos/as menores de edad.
     * - Si c), d) y e) admiten varias presentaciones (renovaciones) no lo dice la directiva: un documento por tipo;
     *   la corrección entra como documento que subsana al anterior.
     */
    public const TIPOS = [
        ['S01_A_FICHA_DATOS', 'Ficha de datos de el/la servidor/a civil', null, []],
        ['S01_B_DOCUMENTO_IDENTIDAD', 'Copia de Documento Nacional de Identidad – DNI, o de ser el caso, copia de título de nacionalidad, certificado de haber adquirido la nacionalidad por matrimonio o visa de residencia en el país', null, []],
        ['S01_C_ANTECEDENTES_PENALES', 'Declaración jurada o Certificado de antecedentes penales', null, ['formulario_datos' => 'declaraciones_verificaciones']],
        ['S01_D_ANTECEDENTES_POLICIALES', 'Declaración jurada o certificado de antecedentes policiales', null, ['formulario_datos' => 'declaraciones_verificaciones']],
        ['S01_E_DJ_BIENES_RENTAS', 'Declaración Jurada de bienes y rentas, en caso corresponda', null,
            ['requiere_djbr' => true, 'tiene_banderas' => true, 'es_dato_sensible' => true, 'formulario_datos' => 'declaraciones_verificaciones']],
        ['S01_F_DJ_NO_IMPEDIMENTOS_31419', 'Declaración Jurada de no tener impedimentos para ser designado funcionario público o directivo público de libre designación y remoción, en el marco de la Ley N° 31419', null,
            ['obligatorio' => false, 'formulario_datos' => 'declaraciones_verificaciones']],
        ['S01_G_PARTIDA_MATRIMONIO_CONCUBINATO', 'Copia de partida de matrimonio o constancia de concubinato (unión de hecho)', null, ['obligatorio' => false]],
        ['S01_H_IMPEDIMENTOS_INHABILITACIONES', 'Información de impedimentos e inhabilitaciones (RNSSC, REDJUM, entre otros)', null,
            ['permite_multiples' => true, 'formulario_datos' => 'declaraciones_verificaciones']],
        ['S01_I_DNI_CONYUGE_CONCUBINO', 'Copia del DNI de el/la cónyuge o concubino/a', null, ['obligatorio' => false, 'formulario_datos' => 'familiares']],
        ['S01_J_DNI_HIJOS_MENORES', 'Copia de DNI de los/las hijos/as menores de edad', null, ['obligatorio' => false, 'permite_multiples' => true, 'formulario_datos' => 'familiares']],
        ['S01_K_CERTIFICADO_DISCAPACIDAD', 'Certificado de discapacidad de el/la servidor/a o de sus hijos/as emitido por el CONADIS, en caso corresponda', null,
            ['requiere_discapacidad' => true, 'tiene_banderas' => true, 'es_dato_salud' => true, 'permite_multiples' => true, 'formulario_datos' => 'certificados_discapacidad']],
        ['S01_L_OTRAS_DJ', 'Otras declaraciones juradas que correspondan', null, ['obligatorio' => false, 'permite_multiples' => true, 'formulario_datos' => 'declaraciones_verificaciones']],
        ['S01_OTROS_UNIVERSIDAD', 'Otros documentos requeridos por la universidad', null, ['obligatorio' => false, 'permite_multiples' => true, 'es_hook_universidad' => true]],
        // TODO pendiente de decidir si manda el Anexo 01 o el 05: estos dos solo figuran en el checklist del Anexo 05.
        ['S01_X_ANTECEDENTES_JUDICIALES', 'Certificado o reporte de búsqueda de la PIDE de antecedentes judiciales', 'Figura en el Anexo 05; no en el Anexo 01.',
            ['obligatorio' => false, 'formulario_datos' => 'declaraciones_verificaciones']],
        ['S01_X_DJ_INTERESES', 'Declaración Jurada de Intereses, en caso corresponda', 'Figura en el Anexo 05; no en el Anexo 01.',
            ['obligatorio' => false, 'formulario_datos' => 'declaraciones_verificaciones']],
    ];

    /** Valores comunes; cada tipo sobrescribe los suyos. */
    private const BASE = [
        'descripcion' => null, 'base_normativa' => 'SERVIR',
        'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true,
        'requiere_docente' => false, 'requiere_administrativo' => false, 'requiere_djbr' => false,
        'requiere_colegiatura' => false, 'requiere_derechohabientes' => false, 'requiere_periodo_prueba' => false,
        'requiere_discapacidad' => false, 'tiene_banderas' => false, 'es_universal' => false, 'obligatorio' => true,
        'acredita_perfil_puesto' => false, 'permite_multiples' => false, 'requiere_num_resolucion' => false,
        'requiere_fedateo' => false, 'es_dato_sensible' => false, 'es_dato_salud' => false,
        'es_hook_universidad' => false, 'formulario_datos' => null, 'activo' => true,
    ];

    /** @var list<string> Avisos para quien corre el seeder. */
    public array $avisos = [];

    public function run(): void
    {
        DB::transaction(function (): void {
            SeccionLegajo::query()->firstOrCreate(['id' => self::SECCION], [
                'nombre' => 'Información personal y familiar', 'es_nucleo_comun' => true, 'orden' => self::SECCION,
            ]);
            foreach (self::TIPOS as $orden => [$codigo, $nombre, $descripcion, $valores]) {
                $this->tipo($codigo, [...self::BASE, ...$valores,
                    'seccion_legajo_id' => self::SECCION, 'subseccion_legajo_id' => null,
                    'codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => $descripcion, 'orden' => $orden + 1,
                ]);
            }
            $sobrantes = TipoDocumento::query()->where('seccion_legajo_id', self::SECCION)->where('activo', true)
                ->whereNotIn('codigo', array_column(self::TIPOS, 0));
            foreach ($sobrantes->get() as $tipo) {
                $tipo->update(['activo' => false]);
                $this->avisos[] = "Tipo {$tipo->codigo} no está en la directiva para la sección 01: desactivado.";
            }
        });
        foreach ($this->avisos as $aviso) {
            $this->command?->warn($aviso);
        }
    }

    private function tipo(string $codigo, array $valores): void
    {
        $existente = TipoDocumento::query()->where('seccion_legajo_id', self::SECCION)->where('codigo', $codigo)->where('activo', true)->first();
        if (! $existente) {
            TipoDocumento::query()->create($valores);

            return;
        }
        if ($existente->formulario_datos !== null && $existente->formulario_datos !== $valores['formulario_datos']) {
            $this->avisos[] = "Tipo {$codigo}: formulario_datos = '{$existente->formulario_datos}' en la base, se esperaba '".($valores['formulario_datos'] ?? 'null')."'. No se modificó.";
            $valores['formulario_datos'] = $existente->formulario_datos;
        }
        if (! $existente->documentosUsuario()->exists()) {
            $existente->update($valores);

            return;
        }
        // Con documentos: no se altera. Si está en una subsección o con otro nombre, se crea bien y se desactiva.
        if ($existente->subseccion_legajo_id !== null || $existente->nombre !== $valores['nombre']) {
            $existente->update(['activo' => false]);
            TipoDocumento::query()->create($valores);
            $this->avisos[] = "Tipo {$codigo} tenía documentos y estaba mal puesto: se desactivó y se creó uno nuevo.";
        }
    }
}
