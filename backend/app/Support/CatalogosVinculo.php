<?php

namespace App\Support;

use App\Models\Puesto;
use App\Models\UnidadOrganica;
use App\Models\VinculoLaboral;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Listas de puestos y unidades orgánicas de la institución, que empiezan vacías y aprenden de lo que se registra
 * (decisión del 17/11/2026). Quien registra un vínculo (alta manual o sección 02) agrega a la lista el puesto o la unidad
 * que escribe si aún no existe; el administrador corrige nombres y desactiva (nunca se borra) en Configuración.
 *
 * Dos nombres son el mismo si coinciden sin mayúsculas, tildes ni espacios repetidos: «Analista», «ANÁLISTA » y
 * «analista» son un solo puesto. Si ya existe, el vínculo toma el nombre de la lista. Uno desactivado no se vuelve a
 * crear ni se puede elegir de nuevo (un vínculo que ya lo tenía se sigue corrigiendo en lo demás).
 */
final class CatalogosVinculo
{
    public static function limpiar(?string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $texto));
    }

    public static function normalizar(?string $texto): string
    {
        return Str::upper(Str::ascii(self::limpiar($texto)));
    }

    /**
     * Prepara los datos del vínculo antes de guardarlo: resuelve unidad_organica_nombre (texto escrito) a
     * unidad_organica_id, creando la unidad si es nueva, y registra el puesto en la lista. $prefijo es el de los errores
     * (en el alta, «vinculo.»). Se llama dentro de la transacción del vínculo: si este falla, no queda nada creado.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function aplicar(array $data, ?string $tipoPersonal, string $actor, ?VinculoLaboral $actual = null, string $prefijo = ''): array
    {
        $nombreUnidad = self::limpiar($data['unidad_organica_nombre'] ?? null);
        unset($data['unidad_organica_nombre']);
        if ($nombreUnidad !== '') {
            $data['unidad_organica_id'] = self::unidad($nombreUnidad, $actor, $actual, $prefijo)->id;
        }

        if (array_key_exists('nombre_puesto', $data) && $tipoPersonal !== null) {
            $nombre = self::limpiar($data['nombre_puesto']);
            $sinCambio = $actual && self::normalizar($actual->nombre_puesto) === self::normalizar($nombre) && $actual->tipo_personal === $tipoPersonal;
            if ($sinCambio) {
                unset($data['nombre_puesto']);
            } elseif ($nombre !== '') {
                $data['nombre_puesto'] = self::puesto($nombre, $tipoPersonal, $actor, $prefijo)->nombre;
            }
        }

        return $data;
    }

    private static function puesto(string $nombre, string $tipoPersonal, string $actor, string $prefijo): Puesto
    {
        $existente = Puesto::query()->where('tipo_personal', $tipoPersonal)->where('nombre_normalizado', self::normalizar($nombre))->first();
        if ($existente && ! $existente->activo) {
            throw ValidationException::withMessages([$prefijo.'nombre_puesto' => "El puesto «{$existente->nombre}» está desactivado en la lista de puestos. Elige otro o pide al administrador que lo reactive."]);
        }

        return $existente ?? Puesto::query()->create(['nombre' => $nombre, 'tipo_personal' => $tipoPersonal, 'activo' => true, 'registrado_por' => $actor]);
    }

    private static function unidad(string $nombre, string $actor, ?VinculoLaboral $actual, string $prefijo): UnidadOrganica
    {
        $normalizado = self::normalizar($nombre);
        $existente = UnidadOrganica::query()->get(['id', 'nombre', 'activo'])->first(fn (UnidadOrganica $u): bool => self::normalizar($u->nombre) === $normalizado);
        if ($existente && ! $existente->activo && $existente->id !== $actual?->unidad_organica_id) {
            throw ValidationException::withMessages([$prefijo.'unidad_organica_nombre' => "La unidad orgánica «{$existente->nombre}» está desactivada. Elige otra o pide al administrador que la reactive."]);
        }

        return $existente ?? UnidadOrganica::query()->create(['nombre' => $nombre, 'activo' => true, 'registrado_por' => $actor]);
    }

    /** Busca una unidad con el mismo nombre normalizado (para no duplicar al corregir en Configuración). */
    public static function unidadConNombre(string $nombre, ?string $excepto = null): ?UnidadOrganica
    {
        $normalizado = self::normalizar($nombre);

        return UnidadOrganica::query()->when($excepto, fn ($q) => $q->whereKeyNot($excepto))->get(['id', 'nombre'])
            ->first(fn (UnidadOrganica $u): bool => self::normalizar($u->nombre) === $normalizado);
    }
}
