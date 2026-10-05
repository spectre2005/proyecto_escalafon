<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Support\Seccion05;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de la sección 05 (Movimientos del personal) según el Anexo 01 de la Directiva N° 001-2023-SERVIR-GDSRH:
 * literales a) a e), los documentos que el párrafo general nombra sin literal (suspensión y descanso médico) y el de
 * "otros documentos requeridos por la universidad". Sin subsecciones: la directiva no las define para esta sección.
 *
 * Idempotente, con las mismas reglas que Seccion02CatalogoSeeder:
 * - Un tipo se identifica por (sección 5, código, activo). Si no existe, se crea.
 * - Un tipo sin documentos asociados se corrige con los valores de abajo.
 * - Un tipo con documentos no se altera; si cambió su nombre, se desactiva y se crea uno nuevo.
 * - formulario_datos ya registrado con otro valor no se pisa: se avisa en consola.
 * - Tipos de la sección 5 que no están en esta lista se desactivan (activo = false), nunca se borran.
 *
 * TODO pendiente de confirmar con el cliente: ningún tipo es obligatorio (cada documento existe solo si hubo el
 * movimiento); falta definir si el rol de vacaciones se exige cada año.
 */
class Seccion05CatalogoSeeder extends Seeder
{
    public const SECCION = Seccion05::SECCION;

    /** [código, nombre, descripción, valores propios]. El bloque y los registros que acredita están en App\Support\Seccion05. */
    public const TIPOS = [
        ['S05_A_APRUEBA_LICENCIA_PERMISO', 'Documento que aprueba la licencia o permiso solicitado', 'Permisos (papeletas, memorándum u otros) y licencias solicitadas por el/la servidor/a.', ['formulario_datos' => 'licencias_permisos']],
        ['S05_E_LICENCIA_OTORGADA', 'Licencia otorgada', 'Maternidad, paternidad, lactancia, adopción, sindical, familiares graves, comité SST, asistencia médica a un familiar, ser bombero voluntario u otros.', ['formulario_datos' => 'licencias_permisos']],
        ['S05_SUSPENSION', 'Documento de suspensión', 'Contenido de la sección según el párrafo general del Anexo 01 (sin literal propio).', ['formulario_datos' => 'licencias_permisos']],
        ['S05_DESCANSO_MEDICO', 'Documento de descanso médico', 'Contenido de la sección según el párrafo general del Anexo 01 (sin literal propio). Dato de salud.', ['formulario_datos' => 'licencias_permisos', 'es_dato_salud' => true]],
        ['S05_B_GOCE_VACACIONAL', 'Documento que sustenta el goce vacacional', null, ['formulario_datos' => 'goces_vacacionales']],
        ['S05_C_ADELANTO_POSTERGACION', 'Documento que sustenta el adelanto o postergación de vacaciones', null, ['formulario_datos' => 'goces_vacacionales']],
        ['S05_D_ROL_VACACIONES', 'Rol de vacaciones', null, []],
        ['S05_OTROS_UNIVERSIDAD', 'Otros documentos de movimientos del personal', 'Otros documentos requeridos por la universidad.', ['es_hook_universidad' => true]],
    ];

    /** Valores comunes; cada tipo sobrescribe los suyos. Todos admiten varios documentos a lo largo del tiempo. */
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
                'nombre' => 'Movimientos del personal', 'es_nucleo_comun' => false, 'orden' => self::SECCION,
            ]);
            foreach (self::TIPOS as $orden => [$codigo, $nombre, $descripcion, $valores]) {
                $this->tipo($codigo, [...self::BASE, ...$valores,
                    'seccion_legajo_id' => self::SECCION, 'codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => $descripcion, 'orden' => $orden + 1,
                ]);
            }
            $sobrantes = TipoDocumento::query()->where('seccion_legajo_id', self::SECCION)->where('activo', true)
                ->whereNotIn('codigo', array_column(self::TIPOS, 0));
            foreach ($sobrantes->get() as $tipo) {
                $tipo->update(['activo' => false]);
                $this->avisos[] = "Tipo {$tipo->codigo} no está en la directiva para la sección 05: desactivado.";
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
        if ($existente->nombre !== $valores['nombre']) {
            $existente->update(['activo' => false]);
            TipoDocumento::query()->create($valores);
            $this->avisos[] = "Tipo {$codigo} tenía documentos y cambió de nombre: se desactivó y se creó uno nuevo.";
        }
    }
}
