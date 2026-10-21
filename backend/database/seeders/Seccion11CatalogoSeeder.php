<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Support\Seccion11;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de la sección 11 (Desvinculación) según el Anexo 01 de la Directiva N° 001-2023-SERVIR-GDSRH: literales a) a g),
 * la aceptación de renuncia del Anexo 05 y "otros documentos requeridos por la universidad". Sin subsecciones.
 *
 * Idempotente, con las mismas reglas que Seccion05CatalogoSeeder (crea, corrige sin documentos, no altera con documentos,
 * no pisa formulario_datos, desactiva sobrantes sin borrar).
 *
 * Ningún tipo es obligatorio: cada documento existe solo si hubo el cese y según el régimen. aplica_* indica el régimen
 * de referencia de la directiva (a designados, b carrera, g plazo determinado del DL 1057); es informativo.
 * La liquidación (f) es dato sensible (ingresos económicos, Directiva definición "e"), como en la sección 06.
 * TODO pendiente de confirmar con el cliente: si algún documento se exige al cesar (informe de gestión, entrega de cargo).
 */
class Seccion11CatalogoSeeder extends Seeder
{
    public const SECCION = Seccion11::SECCION;

    /** [código, nombre, descripción, valores propios]. Bloque, literal y registros que acredita: App\Support\Seccion11. */
    public const TIPOS = [
        ['S11_A_TERMINO_DESIGNACION', 'Resolución de término de designación', null, ['aplica_carrera' => false, 'aplica_plazo_determinado' => false, 'requiere_num_resolucion' => true]],
        ['S11_B_TERMINO_CARRERA', 'Resolución de término del servicio civil de carrera', null, ['aplica_plazo_determinado' => false, 'aplica_designado' => false, 'requiere_num_resolucion' => true]],
        ['S11_C_TERMINO_VINCULO', 'Memorando de término de vínculo del servicio civil', 'Anexo 05: documento de término de vínculo del servicio civil.', []],
        ['S11_D_INFORME_GESTION', 'Informe de gestión', null, []],
        ['S11_E_ENTREGA_CARGO', 'Entrega de cargo/puesto', 'Anexo 05: formato de entrega de cargo/puesto.', []],
        ['S11_F_LIQUIDACION', 'Liquidación de beneficios sociales y/o pensión', 'Dato sensible (ingresos económicos).', ['es_dato_sensible' => true]],
        ['S11_G_NO_RENOVACION_CAS', 'Carta de no renovación de contrato a plazo determinado (DL 1057)', 'Solo para el régimen del Decreto Legislativo N° 1057 (plazo determinado).', ['aplica_carrera' => false, 'aplica_designado' => false]],
        ['S11_X_ACEPTACION_RENUNCIA', 'Documento que acredite la aceptación de renuncia', 'Anexo 05 (formato del legajo); no figura en el Anexo 01.', []],
        ['S11_OTROS_UNIVERSIDAD', 'Otros documentos de desvinculación', 'Otros documentos requeridos por la universidad.', ['es_hook_universidad' => true]],
    ];

    /** Valores comunes; cada tipo sobrescribe los suyos. Todos admiten varios documentos (una persona puede tener varios ceses). */
    private const BASE = [
        'subseccion_legajo_id' => null, 'descripcion' => null, 'base_normativa' => 'SERVIR',
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
                'nombre' => 'Desvinculación', 'es_nucleo_comun' => false, 'orden' => self::SECCION,
            ]);
            foreach (self::TIPOS as $orden => [$codigo, $nombre, $descripcion, $valores]) {
                $this->tipo($codigo, [...self::BASE, ...$valores,
                    // Todos los tipos con vínculo usan el formulario de desvinculaciones; "otros" solo guarda metadatos.
                    'formulario_datos' => Seccion11::bloque($codigo) === 'otros' ? null : 'desvinculaciones',
                    'seccion_legajo_id' => self::SECCION, 'codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => $descripcion, 'orden' => $orden + 1,
                ]);
            }
            $sobrantes = TipoDocumento::query()->where('seccion_legajo_id', self::SECCION)->where('activo', true)
                ->whereNotIn('codigo', array_column(self::TIPOS, 0));
            foreach ($sobrantes->get() as $tipo) {
                $tipo->update(['activo' => false]);
                $this->avisos[] = "Tipo {$tipo->codigo} no está en la directiva para la sección 11: desactivado.";
            }
        });
        foreach ($this->avisos as $aviso) {
            $this->command?->warn($aviso);
        }
    }

    private function tipo(string $codigo, array $valores): void
    {
        if (Schema::hasColumn('tipos_documento', 'aplica_plazo_indeterminado')) {
            $valores['aplica_plazo_indeterminado'] = $valores['aplica_carrera'] && $valores['aplica_plazo_determinado'] && $valores['aplica_designado'];
        }
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
        if ($existente->nombre !== $valores['nombre']) {
            $existente->update(['activo' => false]);
            TipoDocumento::query()->create($valores);
            $this->avisos[] = "Tipo {$codigo} tenía documentos y otro nombre: se desactivó y se creó uno nuevo.";
        }
    }
}
