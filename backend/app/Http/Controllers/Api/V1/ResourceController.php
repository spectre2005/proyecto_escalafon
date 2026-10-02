<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

abstract class ResourceController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;

    /** @var array<string, string> */
    protected array $rules = [];

    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection($this->indexQuery()->paginate(100));
    }

    /** Consulta del listado; las subclases pueden filtrar u ordenar distinto. */
    protected function indexQuery(): Builder
    {
        return $this->model::query()->orderBy('id');
    }

    public function show(string $record): JsonResource
    {
        return new JsonResource($this->model::query()->findOrFail($record));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedData($request);
        try {
            $record = $this->model::query()->create($data);
        } catch (QueryException $exception) {
            return $this->constraintResponse($exception);
        }

        return (new JsonResource($record->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $record): JsonResource|JsonResponse
    {
        $model = $this->model::query()->findOrFail($record);
        $data = $this->validatedData($request, true);
        try {
            $model->update($data);
        } catch (QueryException $exception) {
            return $this->constraintResponse($exception);
        }

        return new JsonResource($model->refresh());
    }

    public function destroy(string $record): JsonResponse
    {
        $model = $this->model::query()->findOrFail($record);
        try {
            $model->delete();
        } catch (QueryException $exception) {
            return $this->constraintResponse($exception);
        }

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    protected function validatedData(Request $request, bool $updating = false): array
    {
        $rules = $this->rules;
        if ($updating) {
            unset($rules['id']);
            $rules = array_map(fn (string $rule): string => 'sometimes|'.$rule, $rules);
        }
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Campo no permitido.'));
        }

        return $request->validate($rules);
    }

    protected function constraintResponse(QueryException $exception): JsonResponse
    {
        if (! str_starts_with((string) $exception->getCode(), '23')) {
            throw $exception;
        }

        return response()->json(['message' => 'La operacion entra en conflicto con un registro duplicado o relacionado.'], 409);
    }
}
