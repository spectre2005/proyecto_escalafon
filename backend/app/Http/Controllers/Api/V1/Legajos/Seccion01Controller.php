<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\Concerns\AnulaConMotivo;
use App\Http\Controllers\Controller;
use App\Models\CertificadoDiscapacidad;
use App\Models\DeclaracionVerificacion;
use App\Models\Familiar;
use App\Models\Usuario;
use App\Support\Catalogos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Sección 01 desde la ficha: registro de declaraciones juradas y verificaciones
 * (Anexo 01, literales c, d, e, f, h y l) y certificados de discapacidad (literal k),
 * y anulación con motivo de esos registros y de los familiares.
 * Los certificados permiten corregir sus datos y reemplazar su PDF; las declaraciones se anulan.
 */
class Seccion01Controller extends Controller
{
    use AnulaConMotivo;

    private const MODELOS = [
        'familiares' => Familiar::class,
        'declaraciones-verificaciones' => DeclaracionVerificacion::class,
        'certificados-discapacidad' => CertificadoDiscapacidad::class,
    ];

    public function storeDeclaracion(Request $request, string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $data = $request->validate([
            'tipo' => ['required', Rule::in(Catalogos::TIPOS_DECLARACION)],
            'anio' => 'nullable|integer|between:1950,'.(now()->year + 1),
            'fecha' => 'required|date_format:Y-m-d|before_or_equal:today',
            'resultado' => ['required', Rule::in(Catalogos::RESULTADOS_DECLARACION)],
            'vigente_hasta' => 'nullable|date_format:Y-m-d|after_or_equal:fecha',
            'observacion' => 'nullable|string|max:300',
        ]);

        return $this->crear(DeclaracionVerificacion::class, $request, $usuario, $data);
    }

    public function storeCertificado(Request $request, string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);

        return $this->guardarCertificado($request, $usuario);
    }

    public function updateCertificado(Request $request, string $usuario, string $certificado): JsonResponse
    {
        return $this->guardarCertificado($request, $usuario, $certificado);
    }

    public function descargarCertificado(string $usuario, string $certificado): StreamedResponse
    {
        $record = CertificadoDiscapacidad::query()->where('usuario_id', $usuario)->findOrFail($certificado);
        $disk = Storage::disk('local');
        abort_unless($record->pdf_path && $disk->exists($record->pdf_path), 404, 'Este certificado no tiene un PDF disponible.');

        return $disk->download($record->pdf_path, $record->pdf_nombre, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function guardarCertificado(Request $request, string $usuario, ?string $certificado = null): JsonResponse
    {
        $disk = Storage::disk('local');
        $path = null;
        $previousPath = null;
        try {
            $record = DB::transaction(function () use ($request, $usuario, $certificado, &$path, &$previousPath): CertificadoDiscapacidad {
                $record = $certificado
                    ? CertificadoDiscapacidad::query()->where('usuario_id', $usuario)->lockForUpdate()->findOrFail($certificado)
                    : new CertificadoDiscapacidad(['usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);
                abort_if($record->anulado, 409, 'Un certificado anulado no se puede editar.');
                $data = $this->validarCertificado($request, $usuario);
                unset($data['pdf']);
                $record->fill($data);
                if ($request->hasFile('pdf')) {
                    $file = $request->file('pdf');
                    $path = $file->store('certificados-discapacidad/'.$usuario, 'local');
                    abort_unless($path, 500, 'No se pudo guardar el PDF.');
                    $previousPath = $record->pdf_path;
                    $record->pdf_path = $path;
                    $record->pdf_nombre = $file->getClientOriginalName();
                }
                $record->save();

                return $record->refresh();
            });
        } catch (Throwable $exception) {
            if ($path) {
                $disk->delete($path);
            }
            throw $exception;
        }
        if ($previousPath && $previousPath !== $path) {
            $disk->delete($previousPath);
        }

        return (new JsonResource($record))->response()->setStatusCode($certificado ? 200 : 201);
    }

    /** @return array<string, mixed> */
    private function validarCertificado(Request $request, string $usuario): array
    {
        return $request->validate([
            'persona' => ['required', Rule::in(Catalogos::PERSONAS_CERTIFICADO)],
            // Solo un familiar vigente de esta misma persona.
            'familiar_id' => ['nullable', 'required_if:persona,familiar', 'prohibited_if:persona,servidor', 'uuid',
                // Condiciones en closure: en ->where('anulado', false) el false se pierde al pasar la regla a texto.
                Rule::exists('familiares', 'id')->where(fn ($query) => $query->where('usuario_id', $usuario)->where('anulado', false))],
            'numero_conadis' => 'required|string|max:30',
            'fecha_emision' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'vigente_hasta' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_emision',
            'pdf' => 'nullable|file|mimes:pdf|extensions:pdf|max:2048',
        ], [
            'familiar_id.exists' => 'Selecciona un familiar vigente registrado en esta ficha.',
            'pdf.mimes' => 'Selecciona un archivo PDF válido.',
            'pdf.extensions' => 'El archivo debe tener extensión .pdf.',
            'pdf.max' => 'El PDF no debe superar los 2 MB.',
        ]);
    }

    public function anularRegistro(Request $request, string $usuario, string $tipo, string $registro): JsonResource|JsonResponse
    {
        $modelo = self::MODELOS[$tipo] ?? abort(404);

        return $this->anular($request, $modelo::query()->where('usuario_id', $usuario)->findOrFail($registro));
    }

    /** @param class-string<Model> $modelo */
    private function crear(string $modelo, Request $request, string $usuario, array $data): JsonResponse
    {
        $registro = $modelo::query()->create([...$data, 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);

        return (new JsonResource($registro->refresh()))->response()->setStatusCode(201);
    }
}
