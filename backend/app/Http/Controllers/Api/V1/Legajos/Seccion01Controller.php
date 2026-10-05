<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\Concerns\AnulaConMotivo;
use App\Http\Controllers\Controller;
use App\Models\BitacoraAccesoLegajo;
use App\Models\CertificadoDiscapacidad;
use App\Models\DeclaracionVerificacion;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\Usuario;
use App\Support\Catalogos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Sección 01 desde la ficha: registro de declaraciones juradas y verificaciones
 * (Anexo 01, literales c, d, e, f, h y l) y certificados de discapacidad (literal k),
 * y anulación con motivo de esos registros y de los familiares.
 * Las declaraciones y certificados permiten corregir sus datos y adjuntar un PDF si aún no lo tienen.
 * Un PDF registrado no se reemplaza ni se borra (Directiva, arts. 2.3 y 5.3).
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

        return $this->guardarConPdf($request, $usuario, 'declaraciones-verificaciones');
    }

    public function updateDeclaracion(Request $request, string $usuario, string $declaracion): JsonResponse
    {
        return $this->guardarConPdf($request, $usuario, 'declaraciones-verificaciones', $declaracion);
    }

    public function descargarDeclaracion(Request $request, string $usuario, string $declaracion): StreamedResponse
    {
        return $this->descargarPdf($request, $usuario, DeclaracionVerificacion::query()->where('usuario_id', $usuario)->findOrFail($declaracion));
    }

    /** @return array<string, mixed> */
    private function validarDeclaracion(Request $request): array
    {
        return $request->validate([
            'tipo' => ['required', Rule::in(Catalogos::TIPOS_DECLARACION)],
            'anio' => 'nullable|integer|between:1950,'.(now()->year + 1),
            'fecha' => 'required|date_format:Y-m-d|before_or_equal:today',
            'resultado' => ['required', Rule::in(Catalogos::RESULTADOS_DECLARACION)],
            'vigente_hasta' => 'nullable|date_format:Y-m-d|after_or_equal:fecha',
            'observacion' => 'nullable|string|max:300',
            'pdf' => 'nullable|file|mimes:pdf|extensions:pdf|max:2048',
        ], [
            'pdf.mimes' => 'Selecciona un archivo PDF válido.',
            'pdf.extensions' => 'El archivo debe tener extensión .pdf.',
            'pdf.max' => 'El PDF no debe superar los 2 MB.',
        ]);
    }

    public function storeCertificado(Request $request, string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);

        return $this->guardarConPdf($request, $usuario, 'certificados-discapacidad');
    }

    public function updateCertificado(Request $request, string $usuario, string $certificado): JsonResponse
    {
        return $this->guardarConPdf($request, $usuario, 'certificados-discapacidad', $certificado);
    }

    public function descargarCertificado(Request $request, string $usuario, string $certificado): StreamedResponse
    {
        return $this->descargarPdf($request, $usuario, CertificadoDiscapacidad::query()->where('usuario_id', $usuario)->findOrFail($certificado));
    }

    /**
     * Entrega el PDF y deja registro en bitacora_accesos_legajo, como el visor de documentos del legajo.
     * Estos PDF no están en documentos_usuario (documento_id queda vacío) y la bitácora exige un legajo:
     * se usa el legajo activo más reciente de la persona o, si no tiene, el más reciente. Sin legajo no se
     * puede registrar el acceso y no se entrega el archivo.
     */
    private function descargarPdf(Request $request, string $usuario, Model $record): StreamedResponse
    {
        $disk = Storage::disk('local');
        abort_unless($record->pdf_path && $disk->exists($record->pdf_path), 404, 'Este registro no tiene un PDF disponible.');
        $legajo = Legajo::query()->where('usuario_id', $usuario)
            ->orderByRaw("CASE WHEN estado_legajo = 'activo' THEN 0 ELSE 1 END")->orderByDesc('fecha_apertura')->value('id');
        abort_unless($legajo, 409, 'La persona no tiene legajo: no se puede registrar el acceso al PDF en la bitácora.');
        BitacoraAccesoLegajo::query()->create([
            'legajo_id' => $legajo, 'documento_id' => null, 'usuario_id' => $request->user()->getAuthIdentifier(),
            'accion' => 'descargar', 'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300), 'fecha_acceso' => now(),
        ]);

        return $disk->download($record->pdf_path, $record->pdf_nombre, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function guardarConPdf(Request $request, string $usuario, string $tipo, ?string $registro = null): JsonResponse
    {
        $disk = Storage::disk('local');
        $path = null;
        try {
            $record = DB::transaction(function () use ($request, $usuario, $tipo, $registro, &$path): Model {
                $modelo = self::MODELOS[$tipo];
                $record = $registro
                    ? $modelo::query()->where('usuario_id', $usuario)->lockForUpdate()->findOrFail($registro)
                    : new $modelo(['usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);
                abort_if($record->anulado, 409, 'Un registro anulado no se puede editar.');
                $data = $tipo === 'declaraciones-verificaciones'
                    ? $this->validarDeclaracion($request)
                    : $this->validarCertificado($request, $usuario);
                unset($data['pdf']);
                $record->fill($data);
                if ($request->hasFile('pdf')) {
                    if ($record->pdf_path) {
                        throw ValidationException::withMessages(['pdf' => 'Este registro ya tiene un PDF y no se puede reemplazar. Para corregirlo, anula el registro y registra uno nuevo.']);
                    }
                    $file = $request->file('pdf');
                    $path = $file->store($tipo.'/'.$usuario, 'local');
                    abort_unless($path, 500, 'No se pudo guardar el PDF.');
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

        return (new JsonResource($record))->response()->setStatusCode($registro ? 200 : 201);
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
}
