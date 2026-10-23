<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\Concerns\AnulaConMotivo;
use App\Http\Controllers\Controller;
use App\Models\Afiliacion;
use App\Models\Controversia;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\EstadoDocumento;
use App\Support\Documentos\FormulariosDatos;
use App\Support\Seccion09;
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
 * Sección 09 (Relaciones laborales individuales y colectivas) desde la ficha: controversias individuales y colectivas,
 * afiliación al sindicato y los documentos de la sección por tipo (literales a a c y "otros" de la universidad).
 *
 * Toda la sección es sensible: las rutas exigen datos_sensibles.ver además del permiso de la acción.
 * Nada se elimina: se anula con motivo y sale de los listados. Un registro anulado no se corrige.
 * Solo se manejan afiliaciones de tipo sindicato; las demás de la tabla (eps, seguro_social, otro) no se exponen aquí.
 */
class Seccion09Controller extends Controller
{
    use AnulaConMotivo;

    private const CAMPOS_CONTROVERSIA = ['tipo', 'instancia', 'materia', 'fecha_inicio', 'estado', 'fecha_resolucion', 'resultado', 'documento_id', 'created_at'];

    private const CAMPOS_AFILIACION = ['entidad', 'numero_afiliacion', 'fecha_inicio', 'fecha_fin', 'documento_id', 'created_at'];

    public function show(string $usuario): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $controversias = $this->controversias($usuario)->where('anulado', false)->with('registradoPor')
            ->orderByDesc('fecha_inicio')->orderBy('id')->get();
        $afiliaciones = $this->afiliaciones($usuario)->where('anulado', false)->with('registradoPor')
            ->orderByDesc('fecha_inicio')->orderBy('id')->get();
        $documentos = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('seccion_legajo_id', Seccion09::SECCION)
            ->whereIn('legajo_id', Legajo::query()->where('usuario_id', $usuario)->select('id'))
            ->with(['archivo', 'recepcionadoPor', 'createdBy'])->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('tipo_documento_id');
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', Seccion09::SECCION)->where('activo', true)->orderBy('orden')->get()
            ->map(fn (TipoDocumento $tipo): array => $this->tipo($tipo, $documentos->get($tipo->id, collect())))->values();

        return response()->json(['data' => [
            'legajo' => Legajo::paraConsulta($usuario)?->only(['id', 'numero_legajo', 'estado_legajo']),
            'titular' => ['nombre' => trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 'documento' => $persona->numero_doc_identidad],
            'tipos' => $tipos,
            'controversias' => $this->filas($controversias, self::CAMPOS_CONTROVERSIA),
            'afiliaciones' => $this->filas($afiliaciones, self::CAMPOS_AFILIACION),
        ]]);
    }

    public function storeControversia(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $data = $this->validar($request, [...self::reglasControversia(), 'documento_id' => 'nullable|uuid']);
        $registro = new Controversia([...collect($data)->except('documento_id')->all(), 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);

        return $this->creado(FormulariosDatos::crearEnlazado('controversias', $usuario, $data['documento_id'] ?? null, $registro), self::CAMPOS_CONTROVERSIA);
    }

    public function storeAfiliacion(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        // El tipo no lo envía el cliente: en esta sección siempre es sindicato.
        $data = $this->validar($request, [...self::reglasAfiliacion(), 'documento_id' => 'nullable|uuid']);
        $registro = new Afiliacion([...collect($data)->except('documento_id')->all(), 'tipo' => Seccion09::AFILIACION, 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);

        return $this->creado(FormulariosDatos::crearEnlazado('afiliaciones', $usuario, $data['documento_id'] ?? null, $registro), self::CAMPOS_AFILIACION);
    }

    /** Corrección de los campos enviados de un registro vigente. El documento enlazado no se cambia. */
    public function updateControversia(Request $request, string $usuario, string $registro): JsonResource|JsonResponse
    {
        $this->exigirLegajo($usuario);
        $actual = $this->controversias($usuario)->findOrFail($registro);
        if ($actual->anulado) {
            return $this->anuladoNoSeCorrige();
        }
        $data = $this->validarCambios($request, $actual, self::reglasControversia());
        // Con documento enlazado, el tipo debe seguir siendo el que acredita ese documento (a individual, b colectiva).
        if (isset($data['tipo']) && $actual->documento && ! Seccion09::admite($actual->documento->tipoDocumento?->codigo, $data['tipo'])) {
            throw ValidationException::withMessages(['tipo' => 'El documento enlazado no acredita ese tipo de controversia.']);
        }
        $actual->update($data);

        return new JsonResource($this->fila($actual->refresh(), self::CAMPOS_CONTROVERSIA));
    }

    public function updateAfiliacion(Request $request, string $usuario, string $registro): JsonResource|JsonResponse
    {
        $this->exigirLegajo($usuario);
        $actual = $this->afiliaciones($usuario)->findOrFail($registro);
        if ($actual->anulado) {
            return $this->anuladoNoSeCorrige();
        }
        $actual->update($this->validarCambios($request, $actual, self::reglasAfiliacion()));

        return new JsonResource($this->fila($actual->refresh(), self::CAMPOS_AFILIACION));
    }

    public function anularRegistro(Request $request, string $usuario, string $tipo, string $registro): JsonResource|JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $query = $tipo === 'controversias' ? $this->controversias($usuario) : $this->afiliaciones($usuario);
        $resultado = $this->anular($request, $query->findOrFail($registro));

        return $resultado instanceof JsonResponse ? $resultado
            : new JsonResource($this->fila($resultado->resource, [...($tipo === 'controversias' ? self::CAMPOS_CONTROVERSIA : self::CAMPOS_AFILIACION), 'anulado', 'motivo_anulacion']));
    }

    /**
     * Campos del esquema: tipo, instancia, materia y fecha_inicio son NOT NULL; estado tiene valor por defecto (en_tramite).
     * TODO pendiente de confirmar con el cliente: si se gestiona el estado (hoy es un dato opcional, sin reglas de transición).
     */
    private static function reglasControversia(): array
    {
        return [
            'tipo' => ['required', Rule::in(Seccion09::TIPOS_CONTROVERSIA)],
            'instancia' => ['required', Rule::in(Seccion09::INSTANCIAS)],
            'materia' => 'required|string|max:300',
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'estado' => ['sometimes', Rule::in(Seccion09::ESTADOS_CONTROVERSIA)],
            'fecha_resolucion' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'resultado' => 'nullable|string|max:300',
        ];
    }

    private static function reglasAfiliacion(): array
    {
        return [
            'entidad' => 'required|string|max:150',
            'numero_afiliacion' => 'nullable|string|max:40',
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio',
        ];
    }

    private function controversias(string $usuario): Builder
    {
        return Controversia::query()->where('usuario_id', $usuario);
    }

    /** Solo afiliaciones a sindicato: las de otro tipo no existen para esta sección (responden 404). */
    private function afiliaciones(string $usuario): Builder
    {
        return Afiliacion::query()->where('usuario_id', $usuario)->where('tipo', Seccion09::AFILIACION);
    }

    /** @param  Collection<int, DocumentoUsuario>  $documentos  del tipo, del más reciente al más antiguo */
    private function tipo(TipoDocumento $tipo, Collection $documentos): array
    {
        $ultimo = $documentos->first();

        return [
            'id' => $tipo->id,
            'literal' => Seccion09::literal($tipo->codigo),
            ...$tipo->only(['codigo', 'nombre', 'descripcion', 'formulario_datos', 'obligatorio', 'permite_multiples', 'requiere_num_resolucion', 'es_hook_universidad']),
            'bloque' => Seccion09::bloque($tipo->codigo),
            'registros' => Seccion09::registros($tipo->codigo),
            'estado' => $ultimo ? EstadoDocumento::visible($ultimo) : 'pendiente',
            'documentos' => $documentos->count(),
            'lista' => $documentos->map(fn (DocumentoUsuario $documento): array => [
                ...$this->fila($documento, ['estado', 'folio_inicio', 'folio_fin', 'fecha_incorporacion', 'emisor', 'fecha_emision', 'titular_nombre', 'titular_documento', 'num_resolucion', 'modalidad_presentacion', 'relacion_titular', 'subsana_a_documento_id', 'fecha_recepcion', 'created_at']),
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

    /** Valida y rechaza campos que no son del formulario (autor, anulación, tipo de afiliación, persona, etc.). */
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
        return [
            'fecha_resolucion.after_or_equal' => 'La fecha de resolución no puede ser anterior a la fecha de inicio.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior a la fecha de inicio.',
        ];
    }

    private function creado(Model $registro, array $campos): JsonResponse
    {
        return (new JsonResource($this->fila($registro, $campos)))->response()->setStatusCode(201);
    }

    /** @return array{id: string, values: array<string, mixed>} */
    private function fila(Model $registro, array $campos): array
    {
        if ($registro instanceof DocumentoUsuario) {
            $campos = array_values(array_unique([...$campos, 'fiscalizado', 'resultado_fiscalizacion', 'proceso_fiscalizacion_id']));
        }

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
