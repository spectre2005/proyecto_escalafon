<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Plataforma\Institucion;
use App\Models\Plataforma\PeriodoServicio;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tiempo de uso pagado de una institución: historial de periodos, registro de un pago y anulación con motivo. Un
 * periodo no se edita ni se borra (si hubo un error, se anula y se registra otro) y no se cruza con otro vigente.
 * Al registrar un pago, una institución vencida vuelve a operar sola. Todo queda en la auditoría.
 */
class PeriodoServicioController extends Controller
{
    public function index(string $institucion): JsonResponse
    {
        $registro = Institucion::query()->findOrFail($institucion);

        return response()->json(['data' => [
            'servicio' => $registro->servicio(), 'dias_gracia' => $registro->dias_gracia,
            // «Hoy» en la zona horaria de los periodos, para proponer las fechas de un pago nuevo.
            'hoy' => Carbon::now((string) config('plataforma.zona_horaria'))->toDateString(),
            'periodos' => $registro->periodos()->with(['registradoPor:id,nombre', 'anuladoPor:id,nombre'])->orderByDesc('desde')->get()->map->datos(),
        ]]);
    }

    public function store(Request $request, string $institucion): JsonResponse
    {
        $this->sinCamposAjenos($request, ['desde', 'hasta', 'monto', 'comprobante', 'observacion']);
        $datos = $request->validate([
            'desde' => 'required|date_format:Y-m-d', 'hasta' => 'required|date_format:Y-m-d|after_or_equal:desde',
            'monto' => 'nullable|numeric|min:0|max:9999999999.99', 'comprobante' => 'nullable|string|max:100', 'observacion' => 'nullable|string|max:500',
        ], ['hasta.after_or_equal' => 'El fin del periodo no puede ser anterior a su inicio.']);
        $periodo = DB::connection('plataforma')->transaction(function () use ($request, $institucion, $datos): PeriodoServicio {
            $registro = Institucion::query()->lockForUpdate()->findOrFail($institucion);
            $cruce = $registro->periodos()->where('anulado', false)->where('desde', '<=', $datos['hasta'])->where('hasta', '>=', $datos['desde'])->orderBy('desde')->first();
            if ($cruce) {
                throw ValidationException::withMessages(['desde' => sprintf('Se cruza con el periodo del %s al %s. Empieza el día siguiente a su fin.',
                    $cruce->desde->format('d/m/Y'), $cruce->hasta->format('d/m/Y'))]);
            }
            $periodo = $registro->periodos()->create([...$datos, 'comprobante' => $this->texto($datos['comprobante'] ?? null),
                'observacion' => $this->texto($datos['observacion'] ?? null), 'registrado_por' => Auth::guard('plataforma')->id()]);
            AuditoriaOperaciones::registrar('periodo.registrado', $request, Auth::guard('plataforma')->id(), $registro->id, null, $this->huella($periodo));

            return $periodo;
        });

        return response()->json(['data' => $periodo->load('registradoPor:id,nombre')->datos()], 201);
    }

    public function anular(Request $request, string $institucion, string $periodo): JsonResponse
    {
        $this->sinCamposAjenos($request, ['motivo']);
        $motivo = trim($request->validate(['motivo' => 'required|string|min:5|max:500'])['motivo']);
        $registro = DB::connection('plataforma')->transaction(function () use ($request, $institucion, $periodo, $motivo): PeriodoServicio {
            $registro = PeriodoServicio::query()->where('institucion_id', $institucion)->lockForUpdate()->findOrFail($periodo);
            abort_if($registro->anulado, 409, 'El periodo ya está anulado.');
            $antes = $this->huella($registro);
            $registro->update(['anulado' => true, 'motivo_anulacion' => $motivo, 'anulado_por' => Auth::guard('plataforma')->id(), 'anulado_en' => now()]);
            AuditoriaOperaciones::registrar('periodo.anulado', $request, Auth::guard('plataforma')->id(), $institucion, $antes, $this->huella($registro), $motivo);

            return $registro;
        });

        return response()->json(['data' => $registro->load(['registradoPor:id,nombre', 'anuladoPor:id,nombre'])->datos()]);
    }

    /** @return array<string, mixed> */
    private function huella(PeriodoServicio $periodo): array
    {
        return ['periodo_id' => $periodo->id, 'desde' => $periodo->desde->toDateString(), 'hasta' => $periodo->hasta->toDateString(),
            'monto' => $periodo->monto, 'comprobante' => $periodo->comprobante, 'anulado' => $periodo->anulado];
    }

    private function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    private function sinCamposAjenos(Request $request, array $permitidos): void
    {
        $ajenos = array_diff(array_keys($request->all()), $permitidos);
        if ($ajenos !== []) {
            throw ValidationException::withMessages(array_fill_keys($ajenos, 'Campo no permitido.'));
        }
    }
}
