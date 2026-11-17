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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EscanerSeccionesController extends Controller
{
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
        // Copia propia del PDF: el archivo subido se borra al terminar la petición y la lectura sigue después.
        $ruta = sys_get_temp_dir().'/escalafon-lectura-'.bin2hex(random_bytes(12)).'.pdf';
        abort_unless(copy($request->file('pdf')->getRealPath(), $ruta), 500, 'No se pudo preparar el PDF para leerlo.');
        chmod($ruta, 0600);
        $lectura = LecturaIa::query()->create([
            'usuario_id' => $persona->id, 'tipo_documento_id' => $tipo->id, 'registrado_por' => $request->user()->id,
            'modelo' => (string) config('escaner.modelo'), 'estado' => 'en_espera',
            'campos_pedidos' => $datos['campos'], 'campos_propuestos' => [], 'campos_sugeridos' => [],
        ]);
        // Después de responder: la pantalla consulta el avance y puede seguir usándose mientras tanto.
        LeerDocumentoConIa::dispatchAfterResponse($lectura->id, $ruta, $tipo->nombre, $datos['campos'], [
            'dni' => $persona->numero_doc_identidad, 'nombres' => $persona->nombres,
            'apellido_paterno' => $persona->apellido_paterno, 'apellido_materno' => $persona->apellido_materno,
        ]);

        return response()->json(['data' => ['lectura_id' => $lectura->id, 'estado' => $lectura->estado]], 202)
            ->header('Cache-Control', 'private, no-store');
    }
}
