<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\Concerns\AnulaConMotivo;
use App\Http\Controllers\Controller;
use App\Models\Afiliacion;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\LicenciaPermiso;
use App\Models\RegistroSstBienestar;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\EstadoDocumento;
use App\Support\Documentos\FormulariosDatos;
use App\Support\Seccion10;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sección 10 (SST y Bienestar Social) desde la ficha: 10.1 (reglamento, simulacros, capacitaciones, accidentes y
 * enfermedades) y 10.2 (seguros, subsidios, consulta de descansos médicos y afiliación a EPS o seguro social), con los
 * documentos de la sección por tipo.
 *
 * Toda la sección es sensible: las rutas exigen datos_sensibles.ver. Nada se elimina: se anula con motivo y sale de los
 * listados; un registro anulado no se corrige. Los descansos médicos son de la sección 05 (licencias_permisos): aquí solo
 * se consultan, sin registro ni PDF propios. Las afiliaciones a sindicato son de la sección 09 y no se exponen aquí.
 */
class Seccion10Controller extends Controller
{
    use AnulaConMotivo;

    private const CAMPOS_REGISTRO = ['tipo', 'fecha', 'descripcion', 'estado_tramite', 'documento_id', 'created_at'];

    private const CAMPOS_AFILIACION = ['tipo', 'entidad', 'numero_afiliacion', 'fecha_inicio', 'fecha_fin', 'documento_id', 'created_at'];

    public function show(string $usuario): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $registros = $this->registros($usuario)->where('anulado', false)->with('registradoPor')->orderByDesc('fecha')->orderBy('id')->get();
        $afiliaciones = $this->afiliaciones($usuario)->where('anulado', false)->with('registradoPor')->orderByDesc('fecha_inicio')->orderBy('id')->get();
        $documentos = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('seccion_legajo_id', Seccion10::SECCION)
            ->whereIn('legajo_id', Legajo::query()->where('usuario_id', $usuario)->select('id'))
            ->with(['archivo', 'recepcionadoPor', 'createdBy'])->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('tipo_documento_id');
        $subsecciones = SubseccionLegajo::query()->where('seccion_legajo_id', Seccion10::SECCION)->orderBy('orden')->get(['id', 'codigo', 'nombre']);
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', Seccion10::SECCION)->where('activo', true)->orderBy('orden')->get()
            ->map(fn (TipoDocumento $tipo): array => $this->tipo($tipo, $subsecciones->firstWhere('id', $tipo->subseccion_legajo_id)?->codigo, $documentos->get($tipo->id, collect())))->values();

        return response()->json(['data' => [
            'legajo' => $this->legajoAbierto($usuario)?->only(['id', 'numero_legajo', 'estado_legajo']),
            'titular' => ['nombre' => trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 'documento' => $persona->numero_doc_identidad],
            'subsecciones' => $subsecciones->map(fn (SubseccionLegajo $s): array => $s->only(['codigo', 'nombre']))->values(),
            'tipos' => $tipos,
            'registros' => collect($this->filas($registros, self::CAMPOS_REGISTRO))
                ->map(fn (array $fila): array => [...$fila, 'salud' => in_array($fila['values']['tipo'], Seccion10::TIPOS_SALUD, true)])->values(),
            'afiliaciones' => $this->filas($afiliaciones, self::CAMPOS_AFILIACION),
            'descansos_medicos' => $this->descansosMedicos($usuario),
        ]]);
    }

    public function storeRegistro(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $data = $this->validar($request, [...self::reglasRegistro(), 'documento_id' => 'nullable|uuid']);
        $this->exigirEstadoSoloEnTramites($data);
        $registro = new RegistroSstBienestar([...collect($data)->except('documento_id')->all(), 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);

        return $this->creado(FormulariosDatos::crearEnlazado('registros_sst_bienestar', $usuario, $data['documento_id'] ?? null, $registro), self::CAMPOS_REGISTRO);
    }

    public function storeAfiliacion(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $data = $this->validar($request, [...self::reglasAfiliacion(), 'documento_id' => 'nullable|uuid']);
        $registro = new Afiliacion([...collect($data)->except('documento_id')->all(), 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);

        return $this->creado(FormulariosDatos::crearEnlazado('afiliaciones', $usuario, $data['documento_id'] ?? null, $registro), self::CAMPOS_AFILIACION);
    }

    /** Corrección de los campos enviados de un registro vigente. El documento enlazado no se cambia. */
    public function updateRegistro(Request $request, string $usuario, string $registro): JsonResource|JsonResponse
    {
        $this->exigirLegajo($usuario);
        $actual = $this->registros($usuario)->findOrFail($registro);
        if ($actual->anulado) {
            return $this->anuladoNoSeCorrige();
        }
        $data = $this->validarCambios($request, $actual, self::reglasRegistro());
        $this->exigirEstadoSoloEnTramites([...$actual->only(['tipo', 'estado_tramite']), ...$data]);
        $this->exigirTipoCompatible($actual, $data);
        $actual->update($data);

        return new JsonResource($this->fila($actual->refresh(), self::CAMPOS_REGISTRO));
    }

    public function updateAfiliacion(Request $request, string $usuario, string $registro): JsonResource|JsonResponse
    {
        $this->exigirLegajo($usuario);
        $actual = $this->afiliaciones($usuario)->findOrFail($registro);
        if ($actual->anulado) {
            return $this->anuladoNoSeCorrige();
        }
        $data = $this->validarCambios($request, $actual, self::reglasAfiliacion());
        $this->exigirTipoCompatible($actual, $data);
        $actual->update($data);

        return new JsonResource($this->fila($actual->refresh(), self::CAMPOS_AFILIACION));
    }

    public function anularRegistro(Request $request, string $usuario, string $tipo, string $registro): JsonResource|JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $query = $tipo === 'registros' ? $this->registros($usuario) : $this->afiliaciones($usuario);
        $resultado = $this->anular($request, $query->findOrFail($registro));

        return $resultado instanceof JsonResponse ? $resultado
            : new JsonResource($this->fila($resultado->resource, [...($tipo === 'registros' ? self::CAMPOS_REGISTRO : self::CAMPOS_AFILIACION), 'anulado', 'motivo_anulacion']));
    }

    /** Campos del esquema: tipo y fecha son NOT NULL; estado_tramite tiene valor por defecto (no_aplica). */
    private static function reglasRegistro(): array
    {
        return [
            'tipo' => ['required', Rule::in(Seccion10::TIPOS_REGISTRO)],
            'fecha' => 'required|date_format:Y-m-d',
            'descripcion' => 'nullable|string|max:300',
            'estado_tramite' => ['sometimes', Rule::in(Seccion10::ESTADOS_TRAMITE)],
        ];
    }

    private static function reglasAfiliacion(): array
    {
        return [
            'tipo' => ['required', Rule::in(Seccion10::AFILIACIONES)],
            'entidad' => 'required|string|max:150',
            'numero_afiliacion' => 'nullable|string|max:40',
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio',
        ];
    }

    /** El estado del trámite solo tiene sentido en seguros y subsidios; los demás registros quedan en no_aplica. */
    private function exigirEstadoSoloEnTramites(array $data): void
    {
        if (! in_array($data['tipo'] ?? null, Seccion10::TRAMITES, true) && ($data['estado_tramite'] ?? 'no_aplica') !== 'no_aplica') {
            throw ValidationException::withMessages(['estado_tramite' => 'El estado del trámite solo se registra en trámites de seguro o de subsidio.']);
        }
    }

    /** Con documento enlazado, el tipo del registro debe seguir siendo uno de los que acredita ese documento. */
    private function exigirTipoCompatible(Model $actual, array $data): void
    {
        if (isset($data['tipo']) && $actual->documento && ! Seccion10::admite($actual->documento->tipoDocumento?->codigo, $data['tipo'])) {
            throw ValidationException::withMessages(['tipo' => 'El documento enlazado no acredita ese tipo.']);
        }
    }

    private function registros(string $usuario): Builder
    {
        return RegistroSstBienestar::query()->where('usuario_id', $usuario)->whereIn('tipo', Seccion10::TIPOS_REGISTRO);
    }

    /** Solo EPS y seguro social: sindicato es de la sección 09 y no existe para esta sección (responde 404). */
    private function afiliaciones(string $usuario): Builder
    {
        return Afiliacion::query()->where('usuario_id', $usuario)->whereIn('tipo', Seccion10::AFILIACIONES);
    }

    /**
     * 10.2 c): consulta de los descansos médicos registrados en la sección 05 (licencias_permisos), con su documento si
     * lo tiene. Solo lectura; se registran, documentan y anulan en la sección 05.
     */
    private function descansosMedicos(string $usuario): array
    {
        return LicenciaPermiso::query()->where('usuario_id', $usuario)->where('tipo', 'descanso_medico')->where('anulado', false)
            ->with(['documento.archivo', 'documento.tipoDocumento:id,nombre'])->orderByDesc('fecha_inicio')->orderBy('id')->get()
            ->map(fn (LicenciaPermiso $licencia): array => [
                ...$this->fila($licencia, ['fecha_inicio', 'fecha_fin', 'dias', 'con_goce', 'numero_documento']),
                'documento' => $licencia->documento ? [
                    'id' => $licencia->documento->id,
                    'tipo' => $licencia->documento->tipoDocumento?->nombre,
                    'folio_inicio' => $licencia->documento->folio_inicio,
                    'folio_fin' => $licencia->documento->folio_fin,
                    'estado_visible' => EstadoDocumento::visible($licencia->documento),
                    'tiene_archivo' => $licencia->documento->archivo !== null,
                ] : null,
            ])->values()->all();
    }

    /** @param  Collection<int, DocumentoUsuario>  $documentos  del tipo, del más reciente al más antiguo */
    private function tipo(TipoDocumento $tipo, ?string $subseccion, Collection $documentos): array
    {
        $ultimo = $documentos->first();

        return [
            'id' => $tipo->id,
            'subseccion' => $subseccion,
            'literal' => Seccion10::literal($tipo->codigo),
            ...$tipo->only(['codigo', 'nombre', 'descripcion', 'formulario_datos', 'obligatorio', 'permite_multiples', 'requiere_num_resolucion', 'es_hook_universidad', 'es_dato_salud']),
            'registros' => Seccion10::registros($tipo->codigo),
            'estado' => $ultimo ? EstadoDocumento::visible($ultimo) : 'pendiente',
            'documentos' => $documentos->count(),
            'lista' => $documentos->map(fn (DocumentoUsuario $documento): array => [
                ...$this->fila($documento, ['estado', 'folio_inicio', 'folio_fin', 'fecha_incorporacion', 'emisor', 'fecha_emision', 'titular_nombre', 'titular_documento', 'num_resolucion', 'modalidad_presentacion', 'relacion_titular', 'subsana_a_documento_id', 'fecha_recepcion', 'created_at']),
                'subsanado' => $documentos->contains('subsana_a_documento_id', $documento->id),
                'estado_visible' => EstadoDocumento::visible($documento),
                'aprobado' => EstadoDocumento::aprobado($documento, $tipo),
                'subido_por' => $this->nombre($documento->createdBy),
                'recepcionado_por' => $this->nombre($documento->recepcionadoPor),
                'archivo' => $documento->archivo?->only(['nombre_original', 'num_paginas', 'tamano_bytes']),
            ])->values()->all(),
        ];
    }

    private function anuladoNoSeCorrige(): JsonResponse
    {
        return response()->json(['message' => 'El registro está anulado: no se corrige. Registra uno nuevo.'], 409);
    }

    private function legajoAbierto(string $usuario): ?Legajo
    {
        return Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->orderByDesc('fecha_apertura')->first();
    }

    private function exigirLegajo(string $usuario): void
    {
        Usuario::query()->findOrFail($usuario);
        abort_unless($this->legajoAbierto($usuario), 409, 'La persona no tiene un legajo abierto. Primero se debe abrir su legajo.');
    }

    /** Valida y rechaza campos que no son del formulario (autor, anulación, persona, etc.). */
    private function validar(Request $request, array $reglas): array
    {
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }

        return $request->validate($reglas, $this->mensajes());
    }

    /** Corrección parcial: valida solo los campos enviados, comparando con los valores guardados. */
    private function validarCambios(Request $request, Model $registro, array $reglas): array
    {
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
        $actual = $this->fila($registro, array_keys($reglas))['values'];

        return Validator::make([...$actual, ...$request->all()], array_intersect_key($reglas, $request->all()), $this->mensajes())->validate();
    }

    private function mensajes(): array
    {
        return ['fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior a la fecha de inicio.'];
    }

    private function creado(Model $registro, array $campos): JsonResponse
    {
        return (new JsonResource($this->fila($registro, $campos)))->response()->setStatusCode(201);
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

    /** @param Collection<int, Model> $registros */
    private function filas(Collection $registros, array $campos): array
    {
        return $registros->map(function (Model $registro) use ($campos): array {
            $fila = $this->fila($registro, $campos);
            $fila['values']['registrado_por'] = $this->nombre($registro->registradoPor);

            return $fila;
        })->values()->all();
    }
}
