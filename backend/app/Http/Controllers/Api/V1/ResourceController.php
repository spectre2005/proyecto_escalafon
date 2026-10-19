<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaEvento;
use App\Models\BitacoraAccesoLegajo;
use App\Models\DocumentoUsuario;
use App\Models\HistorialEstadoDocumento;
use App\Models\ObservacionDocumento;
use App\Models\ParametroSistema;
use App\Models\ReferenciaLegajoPasivo;
use App\Support\ConfiguracionParametros;
use App\Support\Documentos\DocumentosOperativos;
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
        return $this->filtrarSst($this->model::query()->orderBy('id'));
    }

    private function filtrarSst(Builder $query): Builder
    {
        if ($this->model === ReferenciaLegajoPasivo::class) {
            $query->whereIn('tipo_documento_id', DocumentosOperativos::tipos(request())->select('id'));
        }
        if (! request()->user()->veDatosSensibles()) {
            foreach ($this->camposDocumentoSst() as $campo) {
                $query->where(function (Builder $filtro) use ($campo): void {
                    if ($this->model === AuditoriaEvento::class) {
                        $filtro->where('entidad', '!=', 'documentos_usuario')->orWhereNotIn($campo,
                            DocumentoUsuario::query()->where('seccion_legajo_id', 10)->select('id'));
                    } else {
                        $filtro->whereNull($campo)->orWhereNotIn($campo, DocumentoUsuario::query()->where('seccion_legajo_id', 10)->select('id'));
                    }
                });
            }
        }

        return $query;
    }

    public function show(string $record): JsonResource
    {
        return new JsonResource($this->filtrarSst($this->model::query())->findOrFail($record));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedData($request);
        $this->protegerSst($data);
        $this->exigirCondiciones($data);
        try {
            $record = $this->model::query()->create($data);
        } catch (QueryException $exception) {
            return $this->constraintResponse($exception);
        }

        return (new JsonResource($record->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $record): JsonResource|JsonResponse
    {
        $model = $this->filtrarSst($this->model::query())->findOrFail($record);
        $this->protegerSst($model->getAttributes());
        $data = $this->validatedData($request, true);
        $this->protegerSst([...$model->getAttributes(), ...$data]);
        $this->exigirCondiciones($data, $model);
        try {
            $model->update($data);
        } catch (QueryException $exception) {
            return $this->constraintResponse($exception);
        }

        return new JsonResource($model->refresh());
    }

    public function destroy(string $record): JsonResponse
    {
        $model = $this->filtrarSst($this->model::query())->findOrFail($record);
        $this->protegerSst($model->getAttributes());
        try {
            $model->delete();
        } catch (QueryException $exception) {
            return $this->constraintResponse($exception);
        }

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    /**
     * Condiciones del perfil que hacen incompatible el registro (App\Support\CondicionesRegistro). $actual es el
     * registro que se corrige (null al crear). Por defecto no hay ninguna.
     */
    protected function exigirCondiciones(array $data, ?Model $actual = null): void {}

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

    /** @return list<string> */
    private function camposDocumentoSst(): array
    {
        return match ($this->model) {
            AuditoriaEvento::class => ['entidad_id'],
            HistorialEstadoDocumento::class => ['documento_usuario_id'],
            BitacoraAccesoLegajo::class => ['documento_id'],
            ObservacionDocumento::class => ['documento_id', 'documento_subsanacion_id'],
            default => [],
        };
    }

    private function protegerSst(array $datos): void
    {
        abort_if($this->model === ParametroSistema::class && in_array($datos['clave'] ?? null, ConfiguracionParametros::CLAVES, true), 409, 'Estos parámetros se modifican desde Configuración con motivo e historial.');
        abort_if($this->model === ReferenciaLegajoPasivo::class, 409, 'Registra las referencias desde Archivo para comprobar origen, folios y responsable.');
        abort_if(in_array($this->model, [AuditoriaEvento::class, HistorialEstadoDocumento::class, BitacoraAccesoLegajo::class, ObservacionDocumento::class], true), 409, 'La auditoría, bitácora e historial se registran mediante operaciones del sistema y se conservan sin alteraciones.');
        $ids = array_filter(array_map(fn (string $campo): mixed => $datos[$campo] ?? null, $this->camposDocumentoSst()));
        abort_if($ids !== [] && DocumentoUsuario::query()->where('seccion_legajo_id', 10)->whereIn('id', $ids)->exists(), 409,
            'El historial y la auditoría SST se registran mediante las operaciones de la Sección 10 y se conservan sin alteraciones.');
    }
}
