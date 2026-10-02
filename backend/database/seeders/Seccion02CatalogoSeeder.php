<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de la sección 02 (Incorporación) según la Directiva N° 001-2023-SERVIR-GDSRH:
 * 4 subsecciones (2.1 a 2.4) y 13 tipos de documento (literales a a m).
 *
 * Idempotente: se puede correr varias veces sin duplicar. Reglas:
 * - Un tipo se identifica por (sección 2, código, activo). Si no existe, se crea.
 * - Un tipo sin documentos asociados se corrige con los valores de abajo.
 * - Un tipo con documentos no se altera; si está mal puesto se desactiva y se crea uno nuevo.
 * - formulario_datos ya registrado con otro valor no se pisa: se avisa en consola.
 * - Tipos de la sección 2 que no están en esta lista se desactivan (activo = false), nunca se borran.
 */
class Seccion02CatalogoSeeder extends Seeder
{
    public const SECCION = 2;

    public const SUBSECCIONES = [
        '2.1' => 'Documentación del proceso de selección',
        '2.2' => 'Documentación de formalización del vínculo',
        '2.3' => 'Documentación de la inducción del personal',
        '2.4' => 'Documentación del período de prueba',
    ];

    /**
     * TODO pendiente de confirmar con el cliente: la directiva no dice qué documento de formalización
     * (c a g) corresponde a cada régimen, ni si b) aplica a todo el personal de confianza o solo a los
     * designados (el cuerpo y el anexo de la directiva difieren). Hasta confirmarlo, aplica_* = true
     * en todos (valores permisivos) y d) y g) no son obligatorios porque solo existen si hubo renovación
     * o adenda.
     */
    public const TIPOS = [
        ['2.1', 'S02_A_RESULTADO_SELECCION', 'Resultado final del proceso de selección', 'Resultado final del proceso de selección donde el/la servidor/a resultó ganador/a.', []],
        ['2.1', 'S02_B_INFORME_REQUISITOS_PUESTO', 'Informe de ORH sobre el cumplimiento de requisitos del puesto', 'Informe de la Oficina de Recursos Humanos, o la que haga sus veces, que verifica el cumplimiento de los requisitos del puesto (Ley N° 31419 y su reglamento, y servidores de confianza).', []],
        ['2.2', 'S02_C_RESOLUCION_DESIGNACION', 'Resolución administrativa de designación', null, ['requiere_num_resolucion' => true, 'formulario_datos' => 'vinculos_laborales']],
        ['2.2', 'S02_D_RESOLUCION_RENOVACION', 'Resolución administrativa de renovación', null, ['requiere_num_resolucion' => true, 'permite_multiples' => true, 'obligatorio' => false, 'formulario_datos' => 'vinculos_laborales']],
        ['2.2', 'S02_E_RESOLUCION_CARRERA', 'Resolución administrativa del servicio civil de carrera', null, ['requiere_num_resolucion' => true, 'formulario_datos' => 'vinculos_laborales']],
        ['2.2', 'S02_F_CONTRATO', 'Contrato', null, ['formulario_datos' => 'vinculos_laborales']],
        ['2.2', 'S02_G_ADENDA', 'Adenda', null, ['permite_multiples' => true, 'obligatorio' => false, 'formulario_datos' => 'vinculo_adendas']],
        ['2.3', 'S02_H_REGISTRO_INDUCCION', 'Registro de inducción (general y específica)', null, ['permite_multiples' => true, 'formulario_datos' => 'inducciones']],
        ['2.3', 'S02_I_CARGO_PERFIL_PUESTO', 'Cargo de entrega del perfil del puesto y funciones', null, []],
        ['2.3', 'S02_J_CARGO_REGLAMENTO_INTERNO', 'Cargo de entrega del reglamento interno de servidores civiles', null, []],
        ['2.3', 'S02_K_CARGO_CODIGO_ETICA', 'Cargo de entrega del código de conducta y ética', null, []],
        ['2.3', 'S02_L_OTROS_INDUCCION', 'Otros documentos de inducción', 'Otros similares requeridos por la universidad.', ['permite_multiples' => true, 'obligatorio' => false, 'es_hook_universidad' => true]],
        ['2.4', 'S02_M_EVALUACION_PERIODO_PRUEBA', 'Evaluación y resultados del período de prueba', null, ['requiere_periodo_prueba' => true, 'tiene_banderas' => true, 'formulario_datos' => 'periodos_prueba']],
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
                'nombre' => 'Incorporación', 'es_nucleo_comun' => true, 'orden' => self::SECCION,
            ]);
            $subsecciones = $this->subsecciones();
            foreach (self::TIPOS as $orden => [$subseccion, $codigo, $nombre, $descripcion, $valores]) {
                $this->tipo($codigo, [...self::BASE, ...$valores,
                    'seccion_legajo_id' => self::SECCION, 'subseccion_legajo_id' => $subsecciones[$subseccion],
                    'codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => $descripcion, 'orden' => $orden + 1,
                ]);
            }
            $sobrantes = TipoDocumento::query()->where('seccion_legajo_id', self::SECCION)->where('activo', true)
                ->whereNotIn('codigo', array_column(self::TIPOS, 1));
            foreach ($sobrantes->get() as $tipo) {
                $tipo->update(['activo' => false]);
                $this->avisos[] = "Tipo {$tipo->codigo} no está en la directiva para la sección 02: desactivado.";
            }
        });
        foreach ($this->avisos as $aviso) {
            $this->command?->warn($aviso);
        }
    }

    /** @return array<string, string> código => id */
    private function subsecciones(): array
    {
        $ids = [];
        foreach (array_keys(self::SUBSECCIONES) as $orden => $codigo) {
            $registro = SubseccionLegajo::query()->firstOrNew(['seccion_legajo_id' => self::SECCION, 'codigo' => $codigo]);
            $registro->fill(['nombre' => self::SUBSECCIONES[$codigo], 'orden' => $orden + 1])->save();
            $ids[$codigo] = $registro->id;
        }
        // Solo 4 subsecciones: las demás de la sección 2 se quitan si ningún tipo las usa.
        $otras = SubseccionLegajo::query()->where('seccion_legajo_id', self::SECCION)->whereNotIn('id', $ids);
        foreach ($otras->get() as $otra) {
            if (TipoDocumento::query()->where('subseccion_legajo_id', $otra->id)->exists()) {
                $this->avisos[] = "Subsección {$otra->codigo} ({$otra->nombre}) tiene tipos asociados: no se quitó, revísala.";
            } else {
                $otra->delete();
            }
        }

        return $ids;
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
        // Con documentos: no se altera. Si está en otra subsección o con otro nombre, se crea bien y se desactiva.
        if ($existente->subseccion_legajo_id !== $valores['subseccion_legajo_id'] || $existente->nombre !== $valores['nombre']) {
            $existente->update(['activo' => false]);
            TipoDocumento::query()->create($valores);
            $this->avisos[] = "Tipo {$codigo} tenía documentos y estaba mal puesto: se desactivó y se creó uno nuevo.";
        }
    }
}
