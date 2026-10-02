<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

/**
 * Registros que no se editan ni se eliminan (Directiva, arts. 2.3 y 5.3):
 * solo se crean y, si hay un error, se anulan con motivo.
 */
abstract class RegistroAnulableController extends ResourceController
{
    /** Columna por la que se ordena el listado, del más reciente al más antiguo. */
    protected string $orden = 'created_at';

    protected function indexQuery(): Builder
    {
        return $this->model::query()->where('anulado', false)->orderByDesc($this->orden)->orderBy('id');
    }

    public function update(Request $request, string $record): JsonResource|JsonResponse
    {
        $model = $this->model::query()->findOrFail($record);
        $unknown = array_diff(array_keys($request->all()), ['anulado', 'motivo_anulacion']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Este registro no se edita: solo se puede anular con un motivo.'));
        }
        $data = $request->validate([
            'anulado' => 'required|accepted',
            'motivo_anulacion' => 'required|string|min:5|max:1000',
        ]);
        if ($model->anulado) {
            return response()->json(['message' => 'El registro ya está anulado.'], 409);
        }
        $model->update(['anulado' => true, 'motivo_anulacion' => trim($data['motivo_anulacion'])]);

        return new JsonResource($model->refresh());
    }

    public function destroy(string $record): JsonResponse
    {
        $this->model::query()->findOrFail($record);

        return response()->json(['message' => 'Los registros de esta sección no se eliminan; se anulan con motivo.'], 405);
    }

    protected function validatedData(Request $request, bool $updating = false): array
    {
        // Las reglas de las subclases no aceptan autor ni anulación: el autor es quien inició sesión.
        $data = parent::validatedData($request, $updating);
        $data['registrado_por'] = $request->user()->getAuthIdentifier();

        return $data;
    }
}
