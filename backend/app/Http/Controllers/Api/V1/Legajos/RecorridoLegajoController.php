<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\RecorridoLegajo;
use App\Models\Usuario;
use App\Support\AuditoriaRecorrido;
use App\Support\EstadoRecorridoLegajo;
use App\Support\VerificacionRegistroDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecorridoLegajoController extends Controller
{
    public function show(Request $request, string $usuario): JsonResponse
    {
        $legajo = $this->legajo($usuario);
        $recorrido = RecorridoLegajo::query()->where('legajo_id', $legajo->id)->where('actor_usuario_id', $request->user()->id)->first();

        return response()->json(['data' => EstadoRecorridoLegajo::calcular($legajo, $request->user(), $recorrido)]);
    }

    public function actuar(Request $request, string $usuario): JsonResponse
    {
        $data = $request->validate([
            'accion' => 'required|in:iniciar,anterior,continuar,omitir,ir,verificar',
            'seccion_actual_id' => 'present|nullable|integer', 'seccion_destino_id' => 'required_if:accion,ir|nullable|integer',
            'documento_id' => 'required_if:accion,verificar|nullable|uuid',
        ]);
        $legajo = $this->legajo($usuario);

        return DB::transaction(function () use ($request, $data, $legajo, $usuario): JsonResponse {
            Legajo::query()->whereKey($legajo->id)->lockForUpdate()->firstOrFail();
            $recorrido = RecorridoLegajo::query()->firstOrCreate(['legajo_id' => $legajo->id, 'actor_usuario_id' => $request->user()->id], ['secciones_omitidas' => []]);
            $nuevo = $recorrido->wasRecentlyCreated;
            $recorrido = RecorridoLegajo::query()->whereKey($recorrido->id)->lockForUpdate()->firstOrFail();
            $antes = $recorrido->only(['seccion_actual_id', 'secciones_omitidas']);
            $estado = EstadoRecorridoLegajo::calcular($legajo, $request->user(), $recorrido);
            $secciones = collect($estado['secciones']);
            abort_if($secciones->isEmpty(), 409, 'El legajo no tiene secciones configuradas con pantallas disponibles. Solicita revisar su catálogo.');
            $accion = $data['accion'];
            if ($accion !== 'iniciar') {
                abort_if($nuevo, 409, 'Inicia el registro guiado antes de continuar.');
                abort_if(($data['seccion_actual_id'] ?? null) !== $recorrido->seccion_actual_id, 409, 'El recorrido cambió en otra ventana. Actualízalo para continuar.');
            }
            $avisos = [];
            $documentoVerificado = null;
            if ($accion === 'verificar') {
                $doc = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('legajo_id', $legajo->id)->with(['archivo', 'tipoDocumento'])->findOrFail($data['documento_id']);
                abort_unless(EstadoRecorridoLegajo::accesoSeccion($request->user(), $doc->seccion_legajo_id)
                    && EstadoRecorridoLegajo::accesoTipo($request->user(), $doc->tipoDocumento), 403, 'Tu perfil no permite verificar este documento.');
                abort_if($doc->seccion_legajo_id !== $recorrido->seccion_actual_id, 422, 'El documento pertenece a otra sección. Vuelve a ella para verificarlo.');
                $errores = VerificacionRegistroDocumento::errores($doc);
                if ($errores !== []) {
                    throw ValidationException::withMessages(['documento_id' => $errores]);
                }
                $documentoVerificado = $doc->id;
            } elseif ($accion === 'iniciar') {
                $actual = $secciones->firstWhere('id', $recorrido->seccion_actual_id);
                $destino = $actual ?? $secciones->first(fn (array $seccion): bool => ! in_array($seccion['estado'], ['completa', 'omitida', 'no_aplica'], true));
                if ($nuevo) {
                    $destino = $secciones->first();
                }
                $recorrido->seccion_actual_id = $this->siguienteAplicable($secciones->all(), $destino['id'] ?? null, $avisos);
            } elseif ($accion === 'ir') {
                abort_unless($secciones->contains('id', $data['seccion_destino_id']), 422, 'La sección no pertenece al recorrido configurado de este legajo.');
                $recorrido->seccion_actual_id = $this->siguienteAplicable($secciones->all(), $data['seccion_destino_id'], $avisos);
            } else {
                $indice = $secciones->search(fn (array $seccion): bool => $seccion['id'] === $recorrido->seccion_actual_id);
                abort_if($indice === false, 409, 'Selecciona una sección del resumen para continuar.');
                $actual = $secciones[$indice];
                if ($accion === 'anterior') {
                    $anteriores = $secciones->take($indice)->reverse();
                    $destino = $anteriores->first(fn (array $seccion): bool => $seccion['estado'] !== 'no_aplica');
                    abort_unless($destino, 422, 'Ya estás en la primera sección aplicable.');
                    $recorrido->seccion_actual_id = $destino['id'];
                } else {
                    if (! $actual['puede_continuar']) {
                        throw ValidationException::withMessages(['seccion_actual_id' => $actual['motivo']]);
                    }
                    if ($actual['solo_opcionales'] && $accion !== 'omitir') {
                        throw ValidationException::withMessages(['accion' => '¿Quieres agregar algún documento opcional antes de continuar? Elige Omitir por ahora para continuar.']);
                    }
                    abort_if($accion === 'omitir' && ! $actual['solo_opcionales'], 422, 'Solo puedes omitir secciones con documentos opcionales.');
                    if ($accion === 'omitir') {
                        $recorrido->secciones_omitidas = array_values(array_unique([...$recorrido->secciones_omitidas, $actual['id']]));
                    }
                    $recorrido->seccion_actual_id = $this->siguienteAplicable($secciones->all(), $secciones->get($indice + 1)['id'] ?? null, $avisos);
                }
            }
            $recorrido->save();
            $resultado = EstadoRecorridoLegajo::calcular($legajo, $request->user(), $recorrido);
            if ($recorrido->seccion_actual_id === null && $accion !== 'verificar') {
                $pendiente = collect($resultado['secciones'])->first(fn (array $seccion): bool => ! in_array($seccion['estado'], ['completa', 'omitida', 'no_aplica'], true));
                if ($pendiente) {
                    $recorrido->update(['seccion_actual_id' => $pendiente['id']]);
                    $resultado['seccion_actual_id'] = $pendiente['id'];
                    $avisos[] = 'Quedan secciones pendientes. Puedes completarlas desde el resumen.';
                }
            }
            AuditoriaRecorrido::registrar($recorrido, $request, $usuario, $accion, $antes,
                [...$recorrido->only(['seccion_actual_id', 'secciones_omitidas']), 'documento_verificado' => $documentoVerificado, 'avisos' => $avisos]);

            return response()->json(['data' => [...$resultado, 'avisos' => $avisos, 'documento_verificado' => $documentoVerificado]]);
        });
    }

    private function legajo(string $usuario): Legajo
    {
        Usuario::query()->findOrFail($usuario);
        $legajo = Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->orderByDesc('fecha_apertura')->orderByDesc('id')->first();
        abort_unless($legajo, 409, 'Primero abre un legajo para esta persona.');

        return $legajo;
    }

    private function siguienteAplicable(array $secciones, ?int $destino, array &$avisos): ?int
    {
        $buscar = false;
        foreach ($secciones as $seccion) {
            $buscar = $buscar || $seccion['id'] === $destino;
            if (! $buscar) {
                continue;
            }
            if ($seccion['estado'] === 'no_aplica') {
                $avisos[] = $seccion['nombre'].': Esta sección no aplica para tu puesto.';

                continue;
            }

            return $seccion['id'];
        }

        return null;
    }
}
