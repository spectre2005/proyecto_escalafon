<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\Concerns\AnulaConMotivo;
use App\Http\Controllers\Controller;
use App\Models\BitacoraAccesoLegajo;
use App\Models\CertificadoDiscapacidad;
use App\Models\DeclaracionVerificacion;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\Catalogos;
use App\Support\CondicionesRegistro;
use App\Support\Documentos\EstadoDocumento;
use App\Support\Documentos\FormulariosDatos;
use App\Support\ExigenciasSeccion01;
use App\Support\PerfilDocumental;
use App\Support\ResumenSeccion02;
use App\Support\Seccion01;
use Carbon\CarbonInterface;
use Database\Seeders\Seccion01CatalogoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sección 01 (Información personal y familiar) desde la ficha: lectura de sus tipos de documento con sus
 * exigencias (por tipo y por familiar), familiares, declaraciones juradas y verificaciones, y certificados de
 * discapacidad; registro y corrección de declaraciones y certificados; anulación con motivo.
 *
 * Los PDF se suben como documentos del legajo (DocumentoLegajoController: folios, metadatos, recepción y
 * bitácora). Un registro de datos se enlaza al documento que lo acredita (documento_id) al crearlo o al subir
 * el documento, nunca después. Los PDF guardados antes en pdf_path (sin folios ni metadatos) se conservan y
 * solo se pueden descargar.
 */
class Seccion01Controller extends Controller
{
    use AnulaConMotivo;

    private const MODELOS = [
        'familiares' => Familiar::class,
        'declaraciones-verificaciones' => DeclaracionVerificacion::class,
        'certificados-discapacidad' => CertificadoDiscapacidad::class,
    ];

    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $hoy = today();
        $perfil = PerfilDocumental::de($persona, VinculoLaboral::query()->where('usuario_id', $usuario)->where('anulado', false)->get());
        $legajo = Legajo::paraConsulta($usuario);
        $documentos = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('seccion_legajo_id', Seccion01CatalogoSeeder::SECCION)
            ->where('legajo_id', $legajo?->id)
            ->with(['archivo', 'recepcionadoPor', 'createdBy', 'tipoDocumento:id,codigo'])->orderByDesc('created_at')->orderByDesc('id')->get();
        $familiares = Familiar::query()->where('usuario_id', $usuario)->with('registradoPor')->orderBy('apellidos_nombres')->orderBy('id')->get();
        $declaraciones = DeclaracionVerificacion::query()->where('usuario_id', $usuario)->delLegajo($legajo?->id)->with('registradoPor')->orderByDesc('fecha')->orderBy('id')->get();
        $certificados = CertificadoDiscapacidad::query()->where('usuario_id', $usuario)->with(['registradoPor', 'familiar'])->orderByDesc('created_at')->orderBy('id')->get();
        $vigentes = $familiares->where('anulado', false)->values();

        // Exigencias por familiar (i, j), con el estado del documento enlazado a cada uno.
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', Seccion01CatalogoSeeder::SECCION)->where('activo', true)->orderBy('orden')->get();
        $porFamiliar = [];
        foreach ($vigentes as $familiar) {
            $exigencia = ExigenciasSeccion01::documentoDeFamiliar($familiar, $hoy);
            $tipo = $exigencia ? $tipos->firstWhere('codigo', $exigencia['codigo']) : null;
            if ($tipo) {
                $porFamiliar[] = [...$exigencia, 'familiar_id' => $familiar->id, 'literal' => Seccion01::literal($tipo->codigo), 'nombre' => $tipo->nombre.' · '.$familiar->apellidos_nombres,
                    ...ExigenciasSeccion01::estadoEnlazado($familiar->documento_id, $documentos)];
            }
        }
        $filasTipos = $tipos->map(function (TipoDocumento $tipo) use ($perfil, $vigentes, $porFamiliar, $documentos): array {
            $propios = $documentos->where('tipo_documento_id', $tipo->id)->values();

            return $this->tipo($tipo, ExigenciasSeccion01::tipo($tipo, $perfil, $vigentes, $porFamiliar), $propios);
        })->values();
        // Resumen: una exigencia por tipo, salvo i y j, que cuentan una por familiar.
        $items = [...$filasTipos->reject(fn (array $tipo): bool => isset(Seccion01::PARENTESCOS[$tipo['codigo']]))
            ->map(fn (array $tipo): array => ['literal' => $tipo['literal'], 'nombre' => $tipo['nombre'], 'exigido' => $tipo['aplica'] ? $tipo['exigido'] : false, 'cargado' => $tipo['cargado'], 'recepcionado' => $tipo['recepcionado']])->all(),
            ...array_map(fn (array $item): array => array_intersect_key($item, array_flip(['literal', 'nombre', 'exigido', 'cargado', 'recepcionado'])), $porFamiliar)];

        $administra = $request->user()->can('sistema.administrar');

        return response()->json(['data' => [
            'legajo' => $legajo?->only(['id', 'numero_legajo', 'estado_legajo']),
            'titular' => ['nombre' => trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 'documento' => $persona->numero_doc_identidad],
            'hoy' => $hoy->format('Y-m-d'),
            'banderas' => $perfil->banderas,
            'avisos' => $this->avisos($perfil, $vigentes, $certificados->where('anulado', false)),
            'resumen' => ExigenciasSeccion01::resumen(array_values($items)),
            'tipos' => $filasTipos,
            'familiares' => $vigentes->map(fn (Familiar $familiar): array => $this->familiar($familiar, $hoy, $porFamiliar))->values(),
            'declaraciones' => $declaraciones->where('anulado', false)->map(fn (DeclaracionVerificacion $registro): array => $this->declaracion($registro))->values(),
            'certificados' => $certificados->where('anulado', false)->map(fn (CertificadoDiscapacidad $registro): array => $this->certificado($registro))->values(),
            // Anulados: no cuentan ni salen en los listados; solo administración los ve aparte.
            'anulados' => $administra ? [
                'familiares' => $familiares->where('anulado', true)->map(fn (Familiar $familiar): array => $this->familiar($familiar, $hoy, []))->values(),
                'declaraciones' => $declaraciones->where('anulado', true)->map(fn (DeclaracionVerificacion $registro): array => $this->declaracion($registro))->values(),
                'certificados' => $certificados->where('anulado', true)->map(fn (CertificadoDiscapacidad $registro): array => $this->certificado($registro))->values(),
            ] : null,
        ]]);
    }

    public function storeDeclaracion(Request $request, string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $data = $this->validarDeclaracion($request, true);
        CondicionesRegistro::declaracion($usuario, $data['tipo'] ?? null);
        $registro = new DeclaracionVerificacion([...collect($data)->except('documento_id')->all(), 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);

        return $this->creado(FormulariosDatos::crearEnlazado('declaraciones_verificaciones', $usuario, $data['documento_id'] ?? null, $registro));
    }

    public function updateDeclaracion(Request $request, string $usuario, string $declaracion): JsonResponse
    {
        $registro = DeclaracionVerificacion::query()->where('usuario_id', $usuario)->delLegajo(Legajo::paraConsulta($usuario)?->id)->findOrFail($declaracion);
        $data = $this->validarDeclaracion($request, false);
        // Corregir una DJBR existente se permite; convertir otra declaración en DJBR con la condición en No, no.
        if (isset($data['tipo']) && $data['tipo'] !== $registro->tipo) {
            CondicionesRegistro::declaracion($usuario, $data['tipo']);
        }

        return $this->corregir($registro, $data);
    }

    public function storeCertificado(Request $request, string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $data = $this->validarCertificado($request, $usuario, true);
        CondicionesRegistro::certificado($usuario);
        $registro = new CertificadoDiscapacidad([...collect($data)->except('documento_id')->all(), 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);

        return $this->creado(FormulariosDatos::crearEnlazado('certificados_discapacidad', $usuario, $data['documento_id'] ?? null, $registro));
    }

    public function updateCertificado(Request $request, string $usuario, string $certificado): JsonResponse
    {
        $registro = CertificadoDiscapacidad::query()->where('usuario_id', $usuario)->findOrFail($certificado);

        return $this->corregir($registro, $this->validarCertificado($request, $usuario, false));
    }

    /** PDF guardado antes del sistema de folios (pdf_path): solo descarga, con registro en la bitácora. */
    public function descargarDeclaracion(Request $request, string $usuario, string $declaracion): StreamedResponse
    {
        return $this->descargarPdf($request, $usuario, DeclaracionVerificacion::query()->where('usuario_id', $usuario)->findOrFail($declaracion));
    }

    public function descargarCertificado(Request $request, string $usuario, string $certificado): StreamedResponse
    {
        return $this->descargarPdf($request, $usuario, CertificadoDiscapacidad::query()->where('usuario_id', $usuario)->findOrFail($certificado));
    }

    public function anularRegistro(Request $request, string $usuario, string $tipo, string $registro): JsonResource|JsonResponse
    {
        $modelo = self::MODELOS[$tipo] ?? abort(404);

        return $this->anular($request, $modelo::query()->where('usuario_id', $usuario)->findOrFail($registro));
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

    /** Corrige los datos; un registro anulado no se toca y el enlace al documento no cambia. */
    private function corregir(Model $registro, array $data): JsonResponse
    {
        abort_if($registro->anulado, 409, 'Un registro anulado no se puede editar.');
        $registro->fill($data);
        if ($registro->documento_id && ! Seccion01::compatible(DocumentoUsuario::query()->with('tipoDocumento')->findOrFail($registro->documento_id)->tipoDocumento, $registro)) {
            throw ValidationException::withMessages(['tipo' => 'El registro está enlazado a un documento de otro tipo: no se puede cambiar a este.']);
        }
        $registro->save();

        return (new JsonResource($registro->refresh()))->response();
    }

    private function creado(Model $registro): JsonResponse
    {
        return (new JsonResource($registro))->response()->setStatusCode(201);
    }

    /** El PDF ya no se adjunta aquí: se sube como documento del legajo, con folios y metadatos. */
    private function sinPdf(Request $request): void
    {
        if ($request->hasFile('pdf') || $request->has('pdf')) {
            throw ValidationException::withMessages(['pdf' => 'El PDF se sube como documento del legajo (con folios y metadatos), no en este formulario.']);
        }
    }

    /** @return array<string, mixed> */
    private function validarDeclaracion(Request $request, bool $creando): array
    {
        $this->sinPdf($request);

        return $request->validate([
            'tipo' => ['required', Rule::in(Catalogos::TIPOS_DECLARACION)],
            'anio' => 'nullable|integer|between:1950,'.(now()->year + 1),
            'fecha' => 'required|date_format:Y-m-d|before_or_equal:today',
            'resultado' => ['required', Rule::in(Catalogos::RESULTADOS_DECLARACION)],
            'vigente_hasta' => 'nullable|date_format:Y-m-d|after_or_equal:fecha',
            'observacion' => 'nullable|string|max:300',
            'documento_id' => $creando ? 'nullable|uuid' : 'prohibited',
        ], ['documento_id.prohibited' => 'El documento se enlaza al registrar los datos o al subirlo; no se cambia después.']);
    }

    /** @return array<string, mixed> */
    private function validarCertificado(Request $request, string $usuario, bool $creando): array
    {
        $this->sinPdf($request);

        return $request->validate([
            'persona' => ['required', Rule::in(Catalogos::PERSONAS_CERTIFICADO)],
            // Solo un familiar vigente de esta misma persona.
            'familiar_id' => ['nullable', 'required_if:persona,familiar', 'prohibited_if:persona,servidor', 'uuid',
                // Condiciones en closure: en ->where('anulado', false) el false se pierde al pasar la regla a texto.
                Rule::exists('familiares', 'id')->where(fn ($query) => $query->where('usuario_id', $usuario)->where('anulado', false))],
            'numero_conadis' => 'required|string|max:30',
            'fecha_emision' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'vigente_hasta' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_emision',
            'documento_id' => $creando ? 'nullable|uuid' : 'prohibited',
        ], [
            'familiar_id.exists' => 'Selecciona un familiar vigente registrado en esta ficha.',
            'documento_id.prohibited' => 'El documento se enlaza al registrar los datos o al subirlo; no se cambia después.',
        ]);
    }

    /**
     * Avisos sin bloqueo: banderas de la persona que no coinciden con lo registrado.
     *
     * @param  Collection<int, Familiar>  $familiares
     * @param  Collection<int, CertificadoDiscapacidad>  $certificados
     * @return list<string>
     */
    private function avisos(PerfilDocumental $perfil, Collection $familiares, Collection $certificados): array
    {
        // Banderas en tres estados: Sí (true), No (false) y Sin responder (null).
        $avisos = [];
        $derechohabientes = $familiares->where('es_derechohabiente', true)->count();
        $tieneDerechohabientes = $perfil->banderas['tiene_derechohabientes'];
        if ($tieneDerechohabientes === true && $familiares->isEmpty()) {
            $avisos[] = 'La persona figura con derechohabientes: falta registrar familiares en Familia y derechohabientes.';
        } elseif ($tieneDerechohabientes === true && $derechohabientes === 0) {
            $avisos[] = 'La persona figura con derechohabientes, pero no hay familiares vigentes marcados como derechohabientes.';
        }
        if ($tieneDerechohabientes === false && $derechohabientes > 0) {
            $avisos[] = "La persona figura sin derechohabientes, pero hay {$derechohabientes} familiar(es) vigente(s) marcado(s) como derechohabiente(s).";
        }
        if ($tieneDerechohabientes === null) {
            $avisos[] = 'Falta definir si la persona tiene derechohabientes (ficha de datos personales).';
        }
        // Con discapacidad: el servidor o alguno de sus hijos (Directiva, punto 1 k), así que vale cualquier certificado.
        $conDiscapacidad = $perfil->banderas['con_discapacidad'];
        if ($conDiscapacidad === true && $certificados->isEmpty()) {
            $avisos[] = 'La persona figura con discapacidad (el servidor o alguno de sus hijos), pero no hay un certificado CONADIS registrado.';
        }
        if ($conDiscapacidad === false && $certificados->isNotEmpty()) {
            $avisos[] = 'Hay un certificado CONADIS registrado, pero la persona figura sin discapacidad (el servidor ni sus hijos).';
        }
        if ($conDiscapacidad === null) {
            $avisos[] = 'Falta definir si hay discapacidad del servidor o de alguno de sus hijos (ficha de datos personales).';
        }

        return [...$avisos, ...$perfil->aplicacion->avisos()];
    }

    /**
     * @param  array{exigido: ?bool, motivo: string, aplica: bool}  $exigencia
     * @param  Collection<int, DocumentoUsuario>  $documentos  del tipo, del más reciente al más antiguo
     */
    private function tipo(TipoDocumento $tipo, array $exigencia, Collection $documentos): array
    {
        $ultimo = $documentos->first();
        $cargados = $documentos->filter(fn (DocumentoUsuario $documento): bool => in_array($documento->estado, ResumenSeccion02::ESTADOS_CARGADO, true));

        return [
            'id' => $tipo->id,
            'literal' => Seccion01::literal($tipo->codigo),
            'solo_anexo_05' => Seccion01::soloAnexo05($tipo->codigo),
            ...$tipo->only(['codigo', 'nombre', 'descripcion', 'formulario_datos', 'obligatorio', 'permite_multiples', 'requiere_num_resolucion', 'es_hook_universidad']),
            // Registros de datos que puede acreditar (el backend lo vuelve a validar al enlazar).
            'declaraciones' => Seccion01::DECLARACIONES[$tipo->codigo] ?? null,
            'parentescos' => Seccion01::PARENTESCOS[$tipo->codigo] ?? null,
            ...$exigencia,
            'estado' => $ultimo ? EstadoDocumento::visible($ultimo) : 'pendiente',
            'cargado' => $cargados->isNotEmpty(),
            'recepcionado' => $cargados->contains(fn (DocumentoUsuario $documento): bool => EstadoDocumento::recepcionado($documento)),
            'documentos' => $documentos->count(),
            'documento' => $ultimo ? $this->fila($ultimo, ['estado', 'folio_inicio', 'folio_fin', 'fecha_recepcion'])['values'] : null,
            'lista' => $documentos->map(fn (DocumentoUsuario $documento): array => [
                ...$this->fila($documento, ['estado', 'folio_inicio', 'folio_fin', 'fecha_incorporacion', 'emisor', 'fecha_emision', 'fecha_caducidad', 'titular_nombre', 'titular_documento', 'num_resolucion', 'modalidad_presentacion', 'relacion_titular', 'subsana_a_documento_id', 'fecha_recepcion', 'created_at']),
                'subsanado' => $documentos->contains('subsana_a_documento_id', $documento->id),
                // Cargado sin registro que lo acredite (se puede enlazar después: DocumentoLegajoController::enlazar).
                'sin_registro' => FormulariosDatos::sinEnlace($documento),
                'estado_visible' => EstadoDocumento::visible($documento),
                'aprobado' => EstadoDocumento::aprobado($documento, $tipo),
                'subido_por' => $this->nombre($documento->createdBy),
                'recepcionado_por' => $this->nombre($documento->recepcionadoPor),
                'archivo' => $documento->archivo?->only(['nombre_original', 'num_paginas', 'tamano_bytes']),
            ])->values()->all(),
        ];
    }

    /** @param  list<array<string, mixed>>  $porFamiliar */
    private function familiar(Familiar $familiar, CarbonInterface $hoy, array $porFamiliar): array
    {
        $fila = $this->fila($familiar, ['parentesco', 'tipo_doc_identidad', 'numero_doc_identidad', 'apellidos_nombres', 'fecha_nacimiento', 'es_derechohabiente', 'documento_id', 'motivo_anulacion']);
        $fila['values']['registrado_por'] = $this->nombre($familiar->registradoPor);
        $exigencias = array_values(array_filter($porFamiliar, fn (array $item): bool => $item['familiar_id'] === $familiar->id));

        return [...$fila, ...ExigenciasSeccion01::edad($familiar, $hoy), 'exigencias' => array_map(fn (array $item): array => collect($item)->except('familiar_id')->all(), $exigencias)];
    }

    private function declaracion(DeclaracionVerificacion $registro): array
    {
        $fila = $this->fila($registro, ['tipo', 'anio', 'fecha', 'resultado', 'vigente_hasta', 'observacion', 'documento_id', 'motivo_anulacion']);
        $fila['values']['registrado_por'] = $this->nombre($registro->registradoPor);

        return [...$fila, 'pdf_anterior' => $registro->pdf_path ? $registro->pdf_nombre : null];
    }

    private function certificado(CertificadoDiscapacidad $registro): array
    {
        $fila = $this->fila($registro, ['persona', 'familiar_id', 'numero_conadis', 'fecha_emision', 'vigente_hasta', 'documento_id', 'motivo_anulacion']);
        $fila['values']['registrado_por'] = $this->nombre($registro->registradoPor);

        return [...$fila, 'familiar' => $registro->familiar?->apellidos_nombres, 'pdf_anterior' => $registro->pdf_path ? $registro->pdf_nombre : null];
    }

    /** @return array{id: string, values: array<string, mixed>} */
    private function fila(Model $registro, array $campos): array
    {
        $values = [];
        foreach ($campos as $campo) {
            $valor = $registro->getAttribute($campo);
            $values[$campo] = $valor instanceof \DateTimeInterface ? $valor->format($campo === 'created_at' ? 'Y-m-d H:i' : 'Y-m-d') : $valor;
        }

        return ['id' => $registro->getKey(), 'values' => $values];
    }

    private function nombre(?Usuario $usuario): ?string
    {
        return $usuario ? trim($usuario->nombres.' '.$usuario->apellido_paterno.' '.$usuario->apellido_materno) : null;
    }
}
