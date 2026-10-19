<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\AuditoriaOperaciones;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class OperacionInstitucionController extends Controller
{
    public const CAMPOS = ['razon_social', 'ruc', 'contacto_nombre', 'contacto_email', 'contacto_telefono', 'numero_contrato', 'fecha_inicio_contrato', 'version_contrato'];

    public function contrato(string $institucion): JsonResponse
    {
        return response()->json(['data' => $this->datos(Institucion::query()->findOrFail($institucion))]);
    }

    public function guardarContrato(Request $request, string $institucion): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), [...self::CAMPOS, 'motivo']) !== [], 422, 'Campo no permitido.');
        $datos = $request->validate(['version_contrato' => 'required|integer|min:0', 'motivo' => 'required|string|min:5|max:1000',
            'razon_social' => 'present|nullable|string|max:255', 'ruc' => 'present|nullable|regex:/^[0-9]{11}$/',
            'contacto_nombre' => 'present|nullable|string|max:150', 'contacto_email' => 'present|nullable|email|max:255', 'contacto_telefono' => 'present|nullable|string|max:30',
            'numero_contrato' => 'present|nullable|string|max:100', 'fecha_inicio_contrato' => 'present|nullable|date_format:Y-m-d']);
        $institucion = DB::connection('plataforma')->transaction(function () use ($request, $institucion, $datos): Institucion {
            $modelo = Institucion::query()->lockForUpdate()->findOrFail($institucion);
            abort_if((int) $modelo->version_contrato !== (int) $datos['version_contrato'], 409, 'Los datos cambiaron. Recarga y revisa antes de guardar.');
            abort_if($modelo->estado === Institucion::ARCHIVADA, 409, 'Los datos contractuales de la institución archivada se conservan sin cambios.');
            $antes = $this->datos($modelo);
            $modelo->forceFill([...collect($datos)->except(['motivo', 'version_contrato'])->all(), 'version_contrato' => $modelo->version_contrato + 1])->save();
            AuditoriaOperaciones::registrar('institucion.contrato_actualizado', $request, $request->user('plataforma')->id, $modelo->id, $antes, $this->datos($modelo), $datos['motivo']);

            return $modelo;
        });

        return response()->json(['data' => $this->datos($institucion)]);
    }

    public function consumo(Request $request, string $institucion): JsonResponse
    {
        $modelo = Institucion::query()->findOrFail($institucion);
        abort_unless($modelo->aprovisionada(), 409, 'La institución todavía no está aprovisionada.');
        $metricas = ['base_datos' => 'no_disponible', 'personas' => null, 'legajos' => null, 'documentos' => null, 'ultimo_acceso' => null, 'archivos' => null, 'bytes_archivos' => null, 'almacenamiento' => 'no_disponible', 'respaldo' => 'sin_verificacion', 'medido_en' => now()->toIso8601String()];
        try {
            $metricas = [...$metricas, ...ConexionInstitucion::usar($modelo, static fn (): array => [
                'base_datos' => 'disponible', 'personas' => DB::table('usuarios')->count(), 'legajos' => DB::table('legajos')->count(),
                'documentos' => DB::table('documentos_usuario')->count(), 'ultimo_acceso' => DB::table('usuarios')->max('ultimo_acceso')])];
        } catch (Throwable $error) {
            report($error);
        }
        $raiz = storage_path('app/private/'.$modelo->almacenamiento);
        $base = realpath(storage_path('app/private'));
        $real = realpath($raiz);
        if ($real && $base && str_starts_with($real, $base.DIRECTORY_SEPARATOR) && ! is_link($raiz)) {
            try {
                $bytes = 0;
                $archivos = 0;
                foreach (File::allFiles($raiz, true) as $f) {
                    if ($f->isLink() || ! $f->isFile() || ! str_starts_with((string) $f->getRealPath(), $real.DIRECTORY_SEPARATOR)) {
                        continue;
                    }
                    $bytes += $f->getSize();
                    $archivos++;
                }
                $metricas = [...$metricas, 'almacenamiento' => 'disponible', 'bytes_archivos' => $bytes, 'archivos' => $archivos];
            } catch (Throwable $error) {
                report($error);
            }
        }
        AuditoriaOperaciones::registrar('institucion.consumo_consultado', $request, $request->user('plataforma')->id, $modelo->id);

        return response()->json(['data' => $metricas]);
    }

    private function datos(Institucion $institucion): array
    {
        return [...$institucion->only(self::CAMPOS), 'fecha_inicio_contrato' => $institucion->fecha_inicio_contrato?->format('Y-m-d')];
    }
}
