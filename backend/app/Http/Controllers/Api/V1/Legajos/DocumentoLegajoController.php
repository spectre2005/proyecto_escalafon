<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\BitacoraAccesoLegajo;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\ArchivoPdf;
use App\Support\Documentos\CargaDocumento;
use App\Support\Documentos\EstadoDocumento;
use App\Support\Documentos\Folios;
use App\Support\Documentos\FormulariosDatos;
use App\Support\Parametros;
use App\Support\ResumenSeccion04;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos del legajo (PDF + metadatos), común a todas las secciones: carga (documento.subir),
 * recepción (documento.recepcionar) y visor (legajo.ver, con registro en bitacora_accesos_legajo).
 * Un documento no se elimina ni se edita su archivo: la corrección es un documento nuevo que lo subsana.
 */
class DocumentoLegajoController extends Controller
{
    /** Secciones que se bloquean sin datos_sensibles.ver (01, 09 y 10). */
    public const SECCIONES_SENSIBLES = [1, 9, 10];

    public const MODALIDADES = ['original', 'copia_simple', 'copia_fedateada', 'digital_firmado'];

    public const RELACIONES_TITULAR = ['servidor', 'conyuge_concubino', 'hijo', 'otro'];

    private const CAMPOS = ['seccion_legajo_id', 'tipo_documento_id', 'pdf', 'folio_inicio', 'folio_fin', 'fecha_incorporacion', 'emisor', 'fecha_emision',
        'titular_nombre', 'titular_documento', 'num_resolucion', 'modalidad_presentacion', 'relacion_titular', 'motivo_incorporacion',
        'subsana_a_documento_id', 'enlace_id'];

    /** Folio sugerido para la próxima carga en una sección: ultimo_folio + 1. */
    public function folio(Request $request, string $usuario): JsonResponse
    {
        $seccion = (int) $request->validate(['seccion' => 'required|integer|exists:secciones_legajo,id'])['seccion'];
        $this->exigirSensibles($request, $seccion);
        $legajo = $this->legajoAbierto($usuario);
        $ultimo = Folios::ultimo($legajo, $seccion);

        return response()->json(['data' => ['seccion_legajo_id' => $seccion, 'ultimo_folio' => $ultimo, 'folio_sugerido' => $ultimo + 1,
            'tamano_maximo_kb' => Parametros::entero('tamano_maximo_pdf_kb')]]);
    }

    public function store(Request $request, string $usuario): JsonResponse
    {
        $legajo = $this->legajoAbierto($usuario);
        $desconocidos = array_diff(array_keys($request->all()), self::CAMPOS);
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
        $seccion = (int) $request->validate(['seccion_legajo_id' => 'required|integer|exists:secciones_legajo,id'])['seccion_legajo_id'];
        $request->validate(['tipo_documento_id' => ['required', 'uuid', Rule::exists('tipos_documento', 'id')->where('seccion_legajo_id', $seccion)->where('activo', true)]],
            ['tipo_documento_id.exists' => 'El tipo de documento no pertenece a la sección indicada (o está inactivo).']);
        $tipo = TipoDocumento::query()->findOrFail($request->input('tipo_documento_id'));
        abort_if($seccion === 4 && isset(ResumenSeccion04::ITEMS[$tipo->codigo]), 422, 'Usa Agregar documento en la Sección 04 para registrar sus datos de experiencia.');
        $this->exigirSensibles($request, $seccion, $tipo);

        $maximo = Parametros::entero('tamano_maximo_pdf_kb');
        $data = $request->validate([
            'pdf' => ['required', 'file', 'max:'.$maximo],
            'folio_inicio' => 'required|integer|min:1',
            'folio_fin' => 'required|integer|gte:folio_inicio',
            'fecha_incorporacion' => 'required|date_format:Y-m-d|before_or_equal:today',
            'emisor' => 'required|string|max:200',
            'fecha_emision' => 'required|date_format:Y-m-d|before_or_equal:today',
            'titular_nombre' => 'required|string|max:200',
            'titular_documento' => 'required|string|max:20',
            'num_resolucion' => [$tipo->requiere_num_resolucion ? 'required' : 'nullable', 'string', 'max:60'],
            'modalidad_presentacion' => ['required', Rule::in(self::MODALIDADES)],
            'relacion_titular' => ['required', Rule::in(self::RELACIONES_TITULAR)],
            'motivo_incorporacion' => 'nullable|string|max:300',
            'subsana_a_documento_id' => 'nullable|uuid',
            'enlace_id' => 'nullable|uuid',
        ], [
            'pdf.max' => "El PDF no debe superar los {$maximo} KB.",
            'folio_fin.gte' => 'El folio final no puede ser menor que el inicial.',
            'num_resolucion.required' => 'Este tipo de documento exige el N.° de resolución.',
        ]);
        if (! ArchivoPdf::esPdf($request->file('pdf'))) {
            throw ValidationException::withMessages(['pdf' => 'El archivo no es un PDF válido (se comprueba su contenido, no solo la extensión).']);
        }
        if (! empty($data['enlace_id']) && ! FormulariosDatos::soportado($tipo->formulario_datos)) {
            throw ValidationException::withMessages(['enlace_id' => 'Este tipo de documento no tiene formulario de datos que enlazar.']);
        }

        $metadatos = collect($data)->except(['pdf', 'subsana_a_documento_id', 'enlace_id'])->map(fn ($valor) => is_string($valor) ? trim($valor) : $valor)->all();
        $documento = CargaDocumento::registrar($legajo, $tipo, $request->file('pdf'), $metadatos, $request->user()->getAuthIdentifier(), $request->ip(),
            $data['subsana_a_documento_id'] ?? null, $data['enlace_id'] ?? null);

        return response()->json(['data' => [
            ...$documento->only(['id', 'tipo_documento_id', 'seccion_legajo_id', 'estado', 'folio_inicio', 'folio_fin', 'num_resolucion', 'subsana_a_documento_id', 'vinculo_laboral_id', 'archivo_id']),
            // Si el tipo tiene formulario de datos y no se enlazó a un registro existente, el frontend abre ese formulario.
            'formulario_datos' => $tipo->formulario_datos,
            'enlazado' => ! empty($data['enlace_id']),
        ]], 201);
    }

    /**
     * Recepción del documento (Directiva, art. 4.5): quién la registra y la fecha. Una sola vez; el estado del
     * documento no cambia (sigue "cargado") y sin recepción no cuenta como aprobado.
     * TODO pendiente de confirmar con el cliente: el sello y la firma del art. 4.5 son físicos (en el documento
     * o el cargo); si quien sube puede recepcionar su propia carga (hoy se permite).
     */
    public function recepcionar(Request $request, string $usuario, string $documento): JsonResponse
    {
        $registro = $this->documentoDe($request, $usuario, $documento);
        $recepcionado = DB::transaction(function () use ($registro, $request): bool {
            // Actualización condicionada: de dos recepciones simultáneas solo una se registra.
            return DocumentoUsuario::query()->whereKey($registro->id)->whereNull('fecha_recepcion')
                ->update(['fecha_recepcion' => today(), 'recepcionado_por' => $request->user()->getAuthIdentifier(), 'updated_at' => now()]) === 1;
        });
        if (! $recepcionado) {
            return response()->json(['message' => 'El documento ya tiene la recepción registrada.'], 409);
        }
        $registro->refresh();

        return response()->json(['data' => [
            ...$registro->only(['id', 'estado', 'recepcionado_por']),
            'fecha_recepcion' => $registro->fecha_recepcion->format('Y-m-d'),
            'estado_visible' => EstadoDocumento::visible($registro),
            'aprobado' => EstadoDocumento::aprobado($registro, $registro->tipoDocumento),
        ]]);
    }

    /**
     * Entrega el PDF desde el disco privado (nunca una URL pública) y deja registro en la bitácora de accesos.
     * accion=ver lo muestra en línea (visor de la ficha); accion=descargar lo entrega como adjunto.
     */
    public function archivo(Request $request, string $usuario, string $documento): StreamedResponse
    {
        $accion = $request->validate(['accion' => 'sometimes|in:ver,descargar'])['accion'] ?? 'ver';
        $registro = $this->documentoDe($request, $usuario, $documento);
        $archivo = $registro->archivo;
        abort_if(in_array($registro->seccion_legajo_id, [3, 4], true) && $archivo?->estado_antivirus === 'infectado', 423,
            'El archivo está bloqueado por el antivirus. Registra un documento nuevo que lo subsane.');
        $disco = (string) config('legajo.disco_documentos');
        abort_unless($archivo && $archivo->almacenamiento === $disco && Storage::disk($disco)->exists($archivo->storage_path), 404, 'El documento no tiene un archivo disponible.');

        BitacoraAccesoLegajo::query()->create([
            'legajo_id' => $registro->legajo_id, 'documento_id' => $registro->id, 'usuario_id' => $request->user()->getAuthIdentifier(),
            'accion' => $accion, 'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300), 'fecha_acceso' => now(),
        ]);
        $cabeceras = ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        return Storage::disk($disco)->response($archivo->storage_path, $archivo->nombre_original, $cabeceras, $accion === 'ver' ? 'inline' : 'attachment');
    }

    /** Documento de la persona; si es de una sección o tipo sensible, exige datos_sensibles.ver. */
    private function documentoDe(Request $request, string $usuario, string $documento): DocumentoUsuario
    {
        Usuario::query()->findOrFail($usuario);
        $registro = DocumentoUsuario::query()->where('usuario_id', $usuario)->with(['tipoDocumento', 'archivo'])->findOrFail($documento);
        $this->exigirSensibles($request, $registro->seccion_legajo_id, $registro->tipoDocumento);

        return $registro;
    }

    private function legajoAbierto(string $usuario): Legajo
    {
        Usuario::query()->findOrFail($usuario);
        $legajo = Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->orderByDesc('fecha_apertura')->first();
        abort_unless($legajo, 409, 'La persona no tiene un legajo abierto. Primero se debe abrir su legajo.');

        return $legajo;
    }

    private function exigirSensibles(Request $request, int $seccion, ?TipoDocumento $tipo = null): void
    {
        $sensible = in_array($seccion, self::SECCIONES_SENSIBLES, true) || $tipo?->es_dato_sensible || $tipo?->es_dato_salud;
        abort_if($sensible && ! $request->user()->veDatosSensibles(), 403, 'Esta sección o tipo de documento contiene datos sensibles.');
    }
}
