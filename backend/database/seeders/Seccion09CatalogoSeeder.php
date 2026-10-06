<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Support\Seccion09;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de la sección 09 (Relaciones laborales individuales y colectivas) según el Anexo 01 de la Directiva
 * N° 001-2023-SERVIR-GDSRH: literales a) a c) y el de "otros documentos requeridos por la universidad".
 * Sin subsecciones: la directiva no las define para esta sección.
 *
 * Idempotente, con las mismas reglas que Seccion05CatalogoSeeder:
 * - Un tipo se identifica por (sección 9, código, activo). Si no existe, se crea.
 * - Un tipo sin documentos asociados se corrige con los valores de abajo.
 * - Un tipo con documentos no se altera; si cambió su nombre, se desactiva y se crea uno nuevo.
 * - formulario_datos ya registrado con otro valor no se pisa: se avisa en consola.
 * - Tipos de la sección 9 que no están en esta lista se desactivan (activo = false), nunca se borran.
 *
 * Ningún tipo es obligatorio: una persona puede no tener controversias ni afiliación sindical. Todos son datos
 * sensibles (afiliación sindical, Directiva definición "e"; la sección 09 es sensible en todo el proyecto).
 * TODO pendiente de confirmar con el cliente: si los "reclamos de servidores/as civiles" del Anexo 05 son documentos
 * de a) o un tipo aparte (hoy van en a) o en "otros").
 */
class Seccion09CatalogoSeeder extends Seeder
{
    public const SECCION = Seccion09::SECCION;

    /** [código, nombre, descripción]. Bloque, literal y registros que acredita: App\Support\Seccion09. */
    public const TIPOS = [
        ['S09_A_CONTROVERSIAS_INDIVIDUALES', 'Documentación relacionada a controversias individuales', 'Controversias en la entidad, ante el Tribunal del Servicio Civil, el Poder Judicial u otra instancia.'],
        ['S09_B_CONTROVERSIAS_COLECTIVAS', 'Documentación relacionada a controversias colectivas', 'Controversias en la entidad, ante el Tribunal del Servicio Civil, el Poder Judicial u otra instancia.'],
        ['S09_C_AFILIACION_SINDICATO', 'Resolución, acta o documento en el que se indique la afiliación al sindicato', null],
        ['S09_OTROS_UNIVERSIDAD', 'Otros documentos de relaciones laborales', 'Otros documentos requeridos por la universidad.'],
    ];

    /** Valores comunes; cada tipo sobrescribe los suyos. Todos admiten varios documentos a lo largo del tiempo. */
    private const BASE = [
        'subseccion_legajo_id' => null, 'descripcion' => null, 'base_normativa' => 'SERVIR',
        'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true,
        'requiere_docente' => false, 'requiere_administrativo' => false, 'requiere_djbr' => false,
        'requiere_colegiatura' => false, 'requiere_derechohabientes' => false, 'requiere_periodo_prueba' => false,
        'requiere_discapacidad' => false, 'tiene_banderas' => false, 'es_universal' => false, 'obligatorio' => false,
        'acredita_perfil_puesto' => false, 'permite_multiples' => true, 'requiere_num_resolucion' => false,
        'requiere_fedateo' => false, 'es_dato_sensible' => true, 'es_dato_salud' => false,
        'es_hook_universidad' => false, 'formulario_datos' => null, 'activo' => true,
    ];

    /** @var list<string> Avisos para quien corre el seeder. */
    public array $avisos = [];

    public function run(): void
    {
        DB::transaction(function (): void {
            SeccionLegajo::query()->firstOrCreate(['id' => self::SECCION], [
                'nombre' => 'Relaciones laborales', 'es_nucleo_comun' => false, 'orden' => self::SECCION,
            ]);
            foreach (self::TIPOS as $orden => [$codigo, $nombre, $descripcion]) {
                $this->tipo($codigo, [...self::BASE,
                    'formulario_datos' => Seccion09::TIPOS_DOCUMENTO[$codigo][2],
                    'es_hook_universidad' => $codigo === 'S09_OTROS_UNIVERSIDAD',
                    'seccion_legajo_id' => self::SECCION, 'codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => $descripcion, 'orden' => $orden + 1,
                ]);
            }
            $sobrantes = TipoDocumento::query()->where('seccion_legajo_id', self::SECCION)->where('activo', true)
                ->whereNotIn('codigo', array_column(self::TIPOS, 0));
            foreach ($sobrantes->get() as $tipo) {
                $tipo->update(['activo' => false]);
                $this->avisos[] = "Tipo {$tipo->codigo} no está en la directiva para la sección 09: desactivado.";
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
        // Con documentos: no se altera. Si cambió el nombre, se crea bien y se desactiva el anterior.
        if ($existente->nombre !== $valores['nombre']) {
            $existente->update(['activo' => false]);
            TipoDocumento::query()->create($valores);
            $this->avisos[] = "Tipo {$codigo} tenía documentos y otro nombre: se desactivó y se creó uno nuevo.";
        }
    }
}
