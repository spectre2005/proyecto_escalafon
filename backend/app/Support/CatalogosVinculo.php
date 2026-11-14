<?php

namespace App\Support;

use App\Models\ModalidadVinculo;
use App\Models\Puesto;
use App\Models\TipoProcesoSeleccion;
use App\Models\TipoVinculo;
use App\Models\UnidadOrganica;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Catálogos del vínculo que la ORH amplía al registrar personal y desde Configuración: unidades orgánicas, puestos,
 * modalidades de vínculo y procesos de selección; de los tres tipos de vínculo solo se edita la descripción. Nada se
 * borra: se desactiva, y lo ya registrado conserva su valor. El vínculo guarda la referencia y el texto del momento
 * (nombre_puesto, proceso_seleccion).
 *
 * El número de registro es el del legajo (uno por legajo, App\Support\NumeroLegajo): un vínculo no tiene número propio.
 *
 * Una sola fuente para el alta con vínculo (UsuarioController::storeConVinculo) y la sección 02 (Seccion02Controller).
 */
final class CatalogosVinculo
{
    /** Tipos de unidad orgánica (los del organigrama cargado en local; una unidad anterior conserva el suyo). */
    public const TIPOS_UNIDAD = [
        'alta_direccion' => 'Alta dirección', 'gobierno' => 'Órgano de gobierno', 'direccion' => 'Dirección', 'oficina' => 'Oficina',
        'unidad' => 'Unidad', 'academico' => 'Órgano académico', 'secretaria' => 'Secretaría', 'control' => 'Órgano de control',
        'especial' => 'Órgano especial',
    ];

    /** Prefijo del código correlativo de cada catálogo. */
    public const PREFIJOS = [UnidadOrganica::class => 'UO', Puesto::class => 'PU', ModalidadVinculo::class => 'MOD', TipoProcesoSeleccion::class => 'PS'];

    /** Tipos de vínculo que pasan por un proceso de selección (un designado no). */
    public const CON_PROCESO = ['carrera', 'plazo_determinado'];

    /** Siguiente código libre del catálogo (UO-031, PU-004…), contando solo los códigos con ese formato. */
    public static function siguienteCodigo(string $modelo): string
    {
        $prefijo = self::PREFIJOS[$modelo];
        $numero = $modelo::query()->where('codigo', 'like', $prefijo.'-%')->pluck('codigo')
            ->map(fn (string $codigo): int => preg_match('/^'.$prefijo.'-(\d+)$/', $codigo, $m) ? (int) $m[1] : 0)->max() ?? 0;
        do {
            $codigo = sprintf('%s-%03d', $prefijo, ++$numero);
        } while ($modelo::query()->where('codigo', $codigo)->exists());

        return $codigo;
    }

    /**
     * Comprueba que modalidad, puesto y proceso de selección correspondan al vínculo y completa el texto del momento (el
     * nombre del puesto y del proceso salen del catálogo). $actual es el vínculo que se corrige (null al crear); un elemento desactivado solo se acepta si
     * ya estaba guardado. $prefijo antepone la clave de los errores (vinculo. en el alta).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function aplicar(array $data, ?string $tipoPersonal, ?VinculoLaboral $actual = null, string $prefijo = ''): array
    {
        $valor = fn (string $campo): mixed => array_key_exists($campo, $data) ? $data[$campo] : $actual?->getAttribute($campo);
        $errores = [];
        $regimen = $valor('regimen_laboral');
        $tipos = RegimenesLaborales::TIPOS_VINCULO;

        $modalidad = self::elemento(ModalidadVinculo::class, 'modalidad_vinculo_id', $data, $actual, $errores);
        if ($modalidad) {
            if ($regimen && $modalidad->regimen_laboral !== $regimen) {
                $errores['modalidad_vinculo_id'] = "La modalidad «{$modalidad->nombre}» es de ".mb_strtolower($tipos[$modalidad->regimen_laboral])
                    .'. Elige una modalidad de '.mb_strtolower($tipos[$regimen] ?? $regimen).' o cambia el tipo de vínculo.';
            } elseif ($modalidad->tipo_personal && $tipoPersonal && $modalidad->tipo_personal !== $tipoPersonal) {
                $errores['modalidad_vinculo_id'] = "La modalidad «{$modalidad->nombre}» es solo para personal {$modalidad->tipo_personal}.";
            }
            if ($modalidad->exige_fin_previsto && ! $valor('fecha_fin_prevista') && ! $valor('fecha_cese')) {
                $errores['fecha_fin_prevista'] = "La modalidad «{$modalidad->nombre}» exige la fecha de fin prevista.";
            }
        }

        $puesto = self::elemento(Puesto::class, 'puesto_id', $data, $actual, $errores);
        if ($puesto) {
            if ($tipoPersonal && $puesto->tipo_personal !== $tipoPersonal) {
                $errores['puesto_id'] = "El puesto «{$puesto->nombre}» es de personal {$puesto->tipo_personal}.";
            }
            $data['nombre_puesto'] = $puesto->nombre;
        }

        $proceso = self::elemento(TipoProcesoSeleccion::class, 'tipo_proceso_seleccion_id', $data, $actual, $errores);
        if ($proceso) {
            if ($regimen && ! in_array($regimen, self::CON_PROCESO, true)) {
                $errores['tipo_proceso_seleccion_id'] = 'Un vínculo designado no pasa por proceso de selección: deja el proceso vacío.';
            }
            if (array_key_exists('tipo_proceso_seleccion_id', $data)) {
                $data['proceso_seleccion'] = $proceso->nombre;
            }
        } elseif (array_key_exists('tipo_proceso_seleccion_id', $data) && ! array_key_exists('proceso_seleccion', $data)) {
            $data['proceso_seleccion'] = null;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages(collect($errores)->mapWithKeys(fn (string $mensaje, string $campo): array => [$prefijo.$campo => $mensaje])->all());
        }

        return $data;
    }

    /**
     * Elemento del catálogo elegido en el vínculo, o null. Uno desactivado solo vale si ya estaba guardado en el vínculo.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $modelo
     * @return T|null
     */
    private static function elemento(string $modelo, string $campo, array $data, ?VinculoLaboral $actual, array &$errores): ?Model
    {
        $id = array_key_exists($campo, $data) ? $data[$campo] : $actual?->getAttribute($campo);
        if (! $id) {
            return null;
        }
        $elemento = $modelo::query()->find($id);
        if ($elemento && (string) $id !== (string) $actual?->getAttribute($campo) && ! $elemento->activo) {
            $errores[$campo] = "«{$elemento->nombre}» está desactivado en el catálogo. Elige otra opción o reactívalo en Configuración.";
        }

        return $elemento;
    }

    /**
     * Opciones para los formularios del vínculo: los catálogos (con los desactivados, que el formulario solo muestra si ya
     * están guardados), las listas fijas y el número que recibirá el próximo legajo que se abra.
     *
     * @return array<string, mixed>
     */
    public static function opciones(): array
    {
        return [
            'unidades' => UnidadOrganica::query()->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'tipo', 'unidad_padre_id', 'activo']),
            'modalidades' => ModalidadVinculo::query()->orderBy('regimen_laboral')->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre', 'regimen_laboral', 'tipo_personal', 'regimen_normativo_id', 'tiene_periodo_prueba', 'exige_fin_previsto', 'activo']),
            'puestos' => Puesto::query()->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre', 'tipo_personal', 'unidad_organica_id', 'grupo_ocupacional', 'es_cargo_confianza', 'obligado_djbr', 'exige_colegiatura', 'tiene_periodo_prueba', 'activo']),
            'procesos' => TipoProcesoSeleccion::query()->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'activo']),
            'tipos_vinculo' => TipoVinculo::query()->orderBy('orden')->get(['id', 'codigo', 'nombre', 'descripcion']),
            'tipos_unidad' => self::TIPOS_UNIDAD,
            'siguiente_legajo' => NumeroLegajo::siguiente(),
        ];
    }
}
