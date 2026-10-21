<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ubigeo;
use App\Models\Usuario;
use App\Support\ConsultaDni;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IdentidadDniController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate(['dni' => ['required', 'string', 'regex:/\A[0-9]{8}\z/']]);
        $existente = Usuario::query()->where('numero_doc_identidad', $datos['dni'])->first(['id', 'nombres', 'apellido_paterno', 'apellido_materno']);
        if ($existente) {
            $resultado = ['dni' => $datos['dni'], 'registrada' => true];
            if ($request->user()->can('legajo.ver')) {
                $resultado['persona_existente'] = ['id' => $existente->id,
                    'nombre' => trim($existente->nombres.' '.$existente->apellido_paterno.' '.$existente->apellido_materno)];
            }
        } else {
            $resultado = [...$this->datosDni($datos['dni']), 'registrada' => false];
        }

        return response()->json(['data' => $resultado])->header('Cache-Control', 'no-store');
    }

    public function paraEdicion(Request $request, string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $datos = $request->validate([
            'dni' => ['required', 'string', 'regex:/\A[0-9]{8}\z/'],
            'actualizar' => ['sometimes', 'boolean'],
        ]);
        if (Usuario::query()->whereKeyNot($usuario)->where('numero_doc_identidad', $datos['dni'])->exists()) {
            throw ValidationException::withMessages(['dni' => 'Ese DNI ya pertenece a otra persona registrada. Revisa el número antes de continuar.']);
        }

        return response()->json(['data' => $this->datosDni($datos['dni'], (bool) ($datos['actualizar'] ?? false))])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function datosDni(string $dni, bool $actualizar = false): array
    {
        $resultado = ConsultaDni::consultar($dni, conUbicacion: true, actualizar: $actualizar);
        $resultado['ubicacion'] = $this->ubicacionDelCatalogo($resultado['ubicacion']);

        return $resultado;
    }

    /** @return array{id: string, region: string, provincia: string, distrito: string}|null */
    private function ubicacionDelCatalogo(?array $ubicacion): ?array
    {
        if ($ubicacion === null) {
            return null;
        }
        $normalizar = fn (string $valor): string => mb_strtolower(Str::ascii(Str::squish($valor)));
        $coincidencias = Ubigeo::query()->get(['id', 'region', 'provincia', 'distrito'])
            ->filter(fn (Ubigeo $fila): bool => $normalizar($fila->region) === $normalizar($ubicacion['region'])
                && $normalizar($fila->provincia) === $normalizar($ubicacion['provincia'])
                && $normalizar($fila->distrito) === $normalizar($ubicacion['distrito']));

        return $coincidencias->count() === 1 ? $coincidencias->first()->only(['id', 'region', 'provincia', 'distrito']) : null;
    }
}
