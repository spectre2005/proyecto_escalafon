<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Legajos\DocumentoLegajoController;
use App\Http\Controllers\Controller;
use App\Jobs\LeerDocumentoConIa;
use App\Models\LecturaIa;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\ConfiguracionFuncionalidades;
use App\Support\Documentos\ArchivoPdf;
use App\Support\Documentos\EscanerSecciones;
use App\Support\Parametros;
use App\Support\Seccion01;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EscanerSeccionesController extends Controller
{
    /** Lecturas en curso a la vez en la institución (cada una ocupa un proceso de PHP mientras espera su turno). */
    public const MAXIMO_EN_CURSO = 3;

    public const PREFIJO_TEMPORAL = 'escalafon-lectura-';

    /** Documentos cuyo titular es un familiar: no se comparan con el DNI ni el nombre del servidor. */
    public const DE_FAMILIARES = [Seccion01::PARTIDA_MATRIMONIO, Seccion01::DNI_CONYUGE, Seccion01::DNI_HIJOS];

    /**
     * Borra copias de PDF y carpetas de OCR de más de una hora: las de lecturas interrumpidas (p. ej. un reinicio del
     * servidor) no deben quedarse con datos personales (Ley 29733).
     */
    public static function limpiarTemporales(): void
    {
        $limite = time() - 3600;
        foreach (glob(sys_get_temp_dir().'/'.self::PREFIJO_TEMPORAL.'*.pdf') ?: [] as $archivo) {
            if (@filemtime($archivo) < $limite) {
                @unlink($archivo);
            }
        }
        foreach (glob(sys_get_temp_dir().'/escalafon-ocr-*', GLOB_ONLYDIR) ?: [] as $carpeta) {
            if (@filemtime($carpeta) < $limite) {
                array_map('unlink', glob($carpeta.'/*') ?: []);
                @rmdir($carpeta);
            }
        }
    }

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless((bool) config('escaner.activo'), 409, 'La lectura de documentos con IA no está activa en este servidor.');
        $datos = $request->validate([
            'usuario_id' => 'required|uuid|exists:usuarios,id',
            'tipo_documento_id' => 'required|uuid|exists:tipos_documento,id',
            'campos' => 'required|array|min:1|max:22',
            'campos.*' => ['required', 'string', 'distinct', Rule::in(EscanerSecciones::CAMPOS)],
            'pdf' => ['required', 'file', 'max:'.Parametros::entero('tamano_maximo_pdf_kb')],
        ]);
        $tipo = TipoDocumento::query()->findOrFail($datos['tipo_documento_id']);
        $seccion = (int) $tipo->seccion_legajo_id;
        abort_unless($tipo->activo && $seccion >= 1 && $seccion <= 11, 422, 'Selecciona un tipo de documento activo.');
        abort_unless(Legajo::query()->where('usuario_id', $datos['usuario_id'])->where('estado_legajo', 'activo')->exists(), 409, 'Abre un legajo activo antes de escanear.');
        $persona = Usuario::query()->findOrFail($datos['usuario_id']);
        $restriccion = DocumentoLegajoController::restriccion($request->user(), $seccion, $tipo);
        abort_if($restriccion !== null, 403, (string) $restriccion);
        abort_if($seccion === 7 && ! $request->user()->can('seccion07.ver'), 403);
        abort_if(ConfiguracionFuncionalidades::inactivaPorTipo($tipo->id) !== null, 409, 'Esta funcionalidad está desactivada.');
        abort_unless(ArchivoPdf::esPdf($request->file('pdf')), 422, 'Adjunta un PDF válido.');
        // Cada lectura ocupa un proceso de PHP mientras espera su turno: una por persona y pocas a la vez.
        $enCurso = LecturaIa::query()->whereIn('estado', LecturaIa::EN_CURSO)->where('updated_at', '>=', now()->subMinutes(LecturaIa::MINUTOS_SIN_AVANCE));
        abort_if((clone $enCurso)->where('registrado_por', $request->user()->id)->exists(), 429, 'Ya tienes una lectura en curso: espera a que termine.');
        abort_if($enCurso->count() >= self::MAXIMO_EN_CURSO, 429, 'Hay varias lecturas en espera. Vuelve a intentarlo en unos minutos.');
        self::limpiarTemporales();
        $lectura = LecturaIa::query()->create([
            'usuario_id' => $persona->id, 'tipo_documento_id' => $tipo->id, 'registrado_por' => $request->user()->id,
            'modelo' => (string) config('escaner.modelo'), 'estado' => 'en_espera',
            'campos_pedidos' => $datos['campos'], 'campos_propuestos' => [], 'campos_sugeridos' => [],
        ]);
        // Copia propia del PDF (la lectura sigue después de responder, cuando el archivo subido ya se borró). Se crea
        // con la lectura ya registrada: si algo falla antes, no queda ninguna copia.
        $ruta = sys_get_temp_dir().'/'.self::PREFIJO_TEMPORAL.bin2hex(random_bytes(12)).'.pdf';
        if (! copy($request->file('pdf')->getRealPath(), $ruta)) {
            $lectura->update(['estado' => 'error', 'error' => 'No se pudo preparar el PDF para leerlo.']);
            abort(500, 'No se pudo preparar el PDF para leerlo.');
        }
        chmod($ruta, 0600);
        // Después de responder: la pantalla consulta el avance y puede seguir usándose mientras tanto.
        LeerDocumentoConIa::dispatchAfterResponse($lectura->id, $ruta, $tipo->nombre, $datos['campos'], [
            'dni' => $persona->numero_doc_identidad, 'nombres' => $persona->nombres,
            'apellido_paterno' => $persona->apellido_paterno, 'apellido_materno' => $persona->apellido_materno,
        ], ! in_array($tipo->codigo, self::DE_FAMILIARES, true));

        return response()->json(['data' => ['lectura_id' => $lectura->id, 'estado' => $lectura->estado]], 202)
            ->header('Cache-Control', 'private, no-store');
    }
}
