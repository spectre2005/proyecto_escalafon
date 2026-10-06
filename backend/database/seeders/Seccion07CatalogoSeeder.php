<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use App\Support\Seccion07;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de la sección 07 (Evaluación de desempeño, progresión en la carrera y desplazamiento) según el Anexo 01
 * de la Directiva N° 001-2023-SERVIR-GDSRH: 3 subsecciones (7.1 a 7.3) y 8 tipos de documento. 7.3 f) "Otros" es el
 * de "otros documentos requeridos por la universidad". El Anexo 05 añade al encargo la designación temporal.
 *
 * Idempotente, con las mismas reglas que Seccion02CatalogoSeeder:
 * - Un tipo se identifica por (sección 7, código, activo). Si no existe, se crea.
 * - Un tipo sin documentos asociados se corrige con los valores de abajo.
 * - Un tipo con documentos no se altera; si está mal puesto se desactiva y se crea uno nuevo.
 * - formulario_datos ya registrado con otro valor no se pisa: se avisa en consola.
 * - Tipos de la sección 7 que no están en esta lista se desactivan (activo = false), nunca se borran.
 *
 * TODO pendiente de confirmar con el cliente:
 * - Si la evaluación de desempeño (7.1) se exige a todo el personal o solo a algunos regímenes (hoy se exige a todos).
 * - 7.2 a): la sección dice "servidor civil de carrera"; solo aplica_carrera = true.
 * - Los documentos de 7.2 y 7.3 existen solo si hubo progresión o desplazamiento: no son obligatorios.
 */
class Seccion07CatalogoSeeder extends Seeder
{
    public const SECCION = Seccion07::SECCION;

    public const SUBSECCIONES = [
        '7.1' => 'Evaluación de desempeño',
        '7.2' => 'Documentación de progresión en la carrera',
        '7.3' => 'Documentación de desplazamiento',
    ];

    /** [código, nombre, descripción, valores propios]. Subsección, literal y registros que acredita: App\Support\Seccion07. */
    public const TIPOS = [
        ['S07_71A_GESTION_RENDIMIENTO', 'Formato para la gestión del rendimiento', 'Fijación de factores de evaluación, registro de evidencias, formato de reunión de seguimiento y notificación de la evaluación obtenida.', []],
        ['S07_71B_ACTA_RETROALIMENTACION', 'Actas de retroalimentación de resultados de desempeño y plan de mejora', null, []],
        ['S07_72A_RESOLUCION_PROGRESION', 'Resolución de progresión en la carrera', 'Para docentes, también la resolución de ratificación o promoción (pendiente de confirmar con el cliente).', [
            'aplica_plazo_determinado' => false, 'aplica_designado' => false, 'obligatorio' => false, 'requiere_num_resolucion' => true,
        ]],
        ['S07_73B_DESIGNACION', 'Designación', null, ['obligatorio' => false]],
        ['S07_73C_ROTACION', 'Rotación', null, ['obligatorio' => false]],
        ['S07_73D_DESTAQUE', 'Destaque', null, ['obligatorio' => false]],
        ['S07_73E_ENCARGO', 'Encargo de puestos o de funciones', 'Incluye la designación temporal de puesto o función (Anexo 05).', ['obligatorio' => false]],
        ['S07_73F_OTROS', 'Otros documentos de desplazamiento', 'Otros documentos requeridos por la universidad.', ['obligatorio' => false, 'es_hook_universidad' => true]],
    ];

    /**
     * Valores comunes; cada tipo sobrescribe los suyos. Todos admiten varios documentos (un ciclo, una progresión o un
     * desplazamiento por documento). TODO pendiente de confirmar con el cliente: aplica_* permisivos (true).
     * No es sección sensible: la calificación se protege con el permiso seccion07.ver_calificacion.
     */
    private const BASE = [
        'descripcion' => null, 'base_normativa' => 'SERVIR',
        'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true,
        'requiere_docente' => false, 'requiere_administrativo' => false, 'requiere_djbr' => false,
        'requiere_colegiatura' => false, 'requiere_derechohabientes' => false, 'requiere_periodo_prueba' => false,
        'requiere_discapacidad' => false, 'tiene_banderas' => false, 'es_universal' => false, 'obligatorio' => true,
        'acredita_perfil_puesto' => false, 'permite_multiples' => true, 'requiere_num_resolucion' => false,
        'requiere_fedateo' => false, 'es_dato_sensible' => false, 'es_dato_salud' => false,
        'es_hook_universidad' => false, 'formulario_datos' => null, 'activo' => true,
    ];

    /** @var list<string> Avisos para quien corre el seeder. */
    public array $avisos = [];

    public function run(): void
    {
        DB::transaction(function (): void {
            SeccionLegajo::query()->firstOrCreate(['id' => self::SECCION], [
                'nombre' => 'Evaluación, progresión y desplazamiento', 'es_nucleo_comun' => false, 'orden' => self::SECCION,
            ]);
            $subsecciones = $this->subsecciones();
            foreach (self::TIPOS as $orden => [$codigo, $nombre, $descripcion, $valores]) {
                [$subseccion, , $formulario] = Seccion07::TIPOS_DOCUMENTO[$codigo];
                $this->tipo($codigo, [...self::BASE, 'formulario_datos' => $formulario, ...$valores,
                    'seccion_legajo_id' => self::SECCION, 'subseccion_legajo_id' => $subsecciones[$subseccion],
                    'codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => $descripcion, 'orden' => $orden + 1,
                ]);
            }
            $sobrantes = TipoDocumento::query()->where('seccion_legajo_id', self::SECCION)->where('activo', true)
                ->whereNotIn('codigo', array_column(self::TIPOS, 0));
            foreach ($sobrantes->get() as $tipo) {
                $tipo->update(['activo' => false]);
                $this->avisos[] = "Tipo {$tipo->codigo} no está en la directiva para la sección 07: desactivado.";
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
