<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Legajo;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class LegajoController extends ResourceController
{
    protected string $model = Legajo::class;

    public function update(Request $request, string $record): JsonResource|JsonResponse
    {
        return DB::transaction(function () use ($request, $record): JsonResource|JsonResponse {
            $data = $this->validatedData($request, true);
            $legajo = Legajo::query()->lockForUpdate()->findOrFail($record);
            foreach (['estado_legajo', 'fecha_pasivo', 'usuario_id', 'numero_legajo', 'fecha_apertura'] as $campo) {
                if ($request->exists($campo)) {
                    $actual = $legajo->getAttribute($campo);
                    $actual = $actual instanceof \DateTimeInterface ? $actual->format('Y-m-d') : $actual;
                    $nuevo = $data[$campo] ?? null;
                    if ($nuevo !== null && in_array($campo, ['fecha_pasivo', 'fecha_apertura'], true)) {
                        $nuevo = CarbonImmutable::parse($nuevo)->toDateString();
                    }
                    abort_if($nuevo != $actual, 409, 'La identidad y el ciclo del legajo se gestionan desde la ficha: no se cambia el estado ni se reabre desde el CRUD.');
                }
            }

            return parent::update($request, $record);
        });
    }

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'numero_legajo' => 'required|string|max:20',
        'estado_legajo' => 'sometimes|string|max:10',
        'formato_legajo' => 'sometimes|string|max:10',
        'ubicacion' => 'nullable|string|max:200',
        'fecha_apertura' => 'required|date',
        'fecha_pasivo' => 'nullable|date',
        'fecha_transferencia_archivo_central' => 'nullable|date',
    ];
}
