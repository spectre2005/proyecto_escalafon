<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use App\Support\Seccion10;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de la sección 10 (SST y Bienestar Social) según el Anexo 01 de la Directiva N° 001-2023-SERVIR-GDSRH:
 * subsecciones 10.1 y 10.2, sus literales, el de accidentes y enfermedades del párrafo general y el de "otros documentos
 * requeridos por la universidad" (sin subsección). 10.2 c) Descansos médicos no tiene tipo: se consulta la sección 05.
 *
 * Idempotente, con las mismas reglas que Seccion07CatalogoSeeder:
 * - Un tipo se identifica por (sección 10, código, activo). Si no existe, se crea.
 * - Un tipo sin documentos asociados se corrige con los valores de abajo.
 * - Un tipo con documentos no se altera; si está mal puesto se desactiva y se crea uno nuevo.
 * - formulario_datos ya registrado con otro valor no se pisa: se avisa en consola.
 * - Tipos de la sección 10 que no están en esta lista se desactivan (activo = false), nunca se borran.
 *
 * TODO pendiente de confirmar con el cliente: ningún tipo es obligatorio (aunque la sección figure como núcleo común,
 * la directiva no dice qué documento se exige a todos; p. ej., la entrega del Reglamento de SST). Solo los accidentes y
 * enfermedades se marcan como dato de salud; toda la sección ya exige datos_sensibles.ver.
 */
class Seccion10CatalogoSeeder extends Seeder
{
    public const SECCION = Seccion10::SECCION;

    public const SUBSECCIONES = [
        '10.1' => 'Seguridad y Salud en el Trabajo (SST)',
        '10.2' => 'Bienestar social',
    ];

    /** [código, nombre, descripción]. Subsección, literal y registros que acredita: App\Support\Seccion10. */
    public const TIPOS = [
        ['S10_1A_REGLAMENTO_SST', 'Cargo de entrega del Reglamento de Seguridad y Salud en el Trabajo', null],
        ['S10_1B_SIMULACROS', 'Registro de entrenamiento y simulacros de emergencia', 'Entrenamientos y simulacros de emergencia donde participe el/la servidor/a civil.'],
        ['S10_1C_ASISTENCIA_CAPACITACION', 'Lista de asistencia de capacitaciones brindadas por la entidad', null],
        ['S10_1X_ACCIDENTES_ENFERMEDADES', 'Documentación de accidentes de trabajo y enfermedades ocupacionales', 'Párrafo general de la sección 10. Dato de salud: sin diagnósticos ni detalle clínico en los datos del registro.'],
        ['S10_2A_SEGUROS', 'Trámites y gestiones de seguro', null],
        ['S10_2B_SUBSIDIOS', 'Trámites y gestiones de subsidios', null],
        ['S10_2D_AFILIACION_EPS', 'Afiliación a Entidades Prestadoras de Salud (EPS) o Seguro Social', null],
        ['S10_OTROS_UNIVERSIDAD', 'Otros documentos de SST y bienestar social', 'Otros documentos requeridos por la universidad.'],
    ];

    /** Valores comunes; cada tipo sobrescribe los suyos. Todos admiten varios documentos. */
    private const BASE = [
        'descripcion' => null, 'base_normativa' => 'SERVIR',
        'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true,
        'requiere_docente' => false, 'requiere_administrativo' => false, 'requiere_djbr' => false,
        'requiere_colegiatura' => false, 'requiere_derechohabientes' => false, 'requiere_periodo_prueba' => false,
        'requiere_discapacidad' => false, 'tiene_banderas' => false, 'es_universal' => false, 'obligatorio' => false,
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
                'nombre' => 'SST y bienestar', 'es_nucleo_comun' => true, 'orden' => self::SECCION,
            ]);
            $subsecciones = $this->subsecciones();
            foreach (self::TIPOS as $orden => [$codigo, $nombre, $descripcion]) {
                [$subseccion, , $formulario] = Seccion10::TIPOS_DOCUMENTO[$codigo];
                $this->tipo($codigo, [...self::BASE,
                    'formulario_datos' => $formulario,
                    'es_dato_salud' => in_array($codigo, Seccion10::DOCUMENTOS_SALUD, true),
                    'es_hook_universidad' => $codigo === 'S10_OTROS_UNIVERSIDAD',
                    'seccion_legajo_id' => self::SECCION, 'subseccion_legajo_id' => $subseccion ? $subsecciones[$subseccion] : null,
                    'codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => $descripcion, 'orden' => $orden + 1,
                ]);
            }
            $sobrantes = TipoDocumento::query()->where('seccion_legajo_id', self::SECCION)->where('activo', true)
                ->whereNotIn('codigo', array_column(self::TIPOS, 0));
            foreach ($sobrantes->get() as $tipo) {
                $tipo->update(['activo' => false]);
                $this->avisos[] = "Tipo {$tipo->codigo} no está en la directiva para la sección 10: desactivado.";
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
        if ($existente->subseccion_legajo_id !== $valores['subseccion_legajo_id'] || $existente->nombre !== $valores['nombre']) {
            $existente->update(['activo' => false]);
            TipoDocumento::query()->create($valores);
            $this->avisos[] = "Tipo {$codigo} tenía documentos y estaba mal puesto: se desactivó y se creó uno nuevo.";
        }
    }
}
