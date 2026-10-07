<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\Concerns\AnulaConMotivo;
use App\Http\Controllers\Controller;
use App\Models\Desvinculacion;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\Documentos\EstadoDocumento;
use App\Support\Documentos\FormulariosDatos;
use App\Support\Seccion11;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sección 11 (Desvinculación) desde la ficha: un registro de desvinculación por cada vínculo que termina y los documentos
 * de la sección por tipo (literales a a g, aceptación de renuncia del Anexo 05 y otros de la universidad).
 *
 * La fecha de cese de referencia es la del vínculo (sección 02, que no se modifica aquí): la desvinculación debe coincidir
 * con ella, y si el vínculo aún no tiene cese, primero se registra en la 02. Nada se elimina: se anula con motivo.
 * El legajo no pasa a pasivo automáticamente (pendiente de confirmar con el cliente).
 */
class Seccion11Controller extends Controller
{
    use AnulaConMotivo;

    private const CAMPOS = ['vinculo_laboral_id', 'motivo_cese', 'fecha_cese', 'numero_resolucion', 'fecha_entrega_cargo',
        'informe_gestion_presentado', 'fecha_liquidacion', 'observaciones', 'documento_id', 'created_at'];

    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $sensibles = $request->user()->veDatosSensibles();
        $vinculos = VinculoLaboral::query()->where('usuario_id', $usuario)->where('anulado', false)->orderByDesc('fecha_inicio')->orderBy('id')->get();
        $desvinculaciones = Desvinculacion::query()->where('usuario_id', $usuario)->where('anulado', false)->with('registradoPor')
            ->orderByDesc('fecha_cese')->orderBy('id')->get();
        $documentos = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('seccion_legajo_id', Seccion11::SECCION)
            ->whereIn('legajo_id', Legajo::query()->where('usuario_id', $usuario)->select('id'))
            ->with(['archivo', 'recepcionadoPor', 'createdBy'])->orderByDesc('created_at')->orderByDesc('id')->get();
        $porTipo = $documentos->groupBy('tipo_documento_id');
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', Seccion11::SECCION)->where('activo', true)->orderBy('orden')->get()
            ->map(fn (TipoDocumento $tipo): array => $this->tipo($tipo, $porTipo->get($tipo->id, collect()), $sensibles))->values();

        return response()->json(['data' => [
            'legajo' => Legajo::paraConsulta($usuario)?->only(['id', 'numero_legajo', 'estado_legajo']),
            'titular' => ['nombre' => trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 'documento' => $persona->numero_doc_identidad],
            'motivos_cese' => Seccion11::MOTIVOS_CESE,
            'vinculos' => $vinculos->map(fn (VinculoLaboral $v): array => [
                ...$this->fila($v, ['tipo_personal', 'regimen_laboral', 'nombre_puesto', 'numero_documento_vinculo', 'fecha_inicio', 'fecha_fin_prevista', 'fecha_cese']),
                'desvinculacion_id' => $desvinculaciones->firstWhere('vinculo_laboral_id', $v->id)?->id,
                'documentos' => $documentos->where('vinculo_laboral_id', $v->id)->count(),
            ])->values(),
            'desvinculaciones' => $desvinculaciones->map(function (Desvinculacion $d) use ($vinculos): array {
                $vinculo = $vinculos->firstWhere('id', $d->vinculo_laboral_id);

                return [...$this->filas(collect([$d]))[0],
                    // La fecha de referencia es la del vínculo; si se corrigió en la 02, se avisa.
                    'coincide_fecha_vinculo' => $vinculo?->fecha_cese?->format('Y-m-d') === $d->fecha_cese?->format('Y-m-d')];
            })->values(),
            'tipos' => $tipos,
        ]]);
    }

    /** Desvinculaciones anuladas: vista aparte, solo con sistema.administrar (ruta). */
    public function anulados(string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $anuladas = Desvinculacion::query()->where('usuario_id', $usuario)->where('anulado', true)->with('registradoPor')->orderByDesc('updated_at')->orderBy('id')->get();

        return response()->json(['data' => ['desvinculaciones' => $this->filas($anuladas, [...self::CAMPOS, 'motivo_anulacion', 'updated_at'])]]);
    }

    public function store(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $data = $this->validar($request, [...$this->reglas($usuario), 'documento_id' => 'nullable|uuid']);

        $registro = DB::transaction(function () use ($data, $usuario, $request): Desvinculacion {
            $this->exigirCoherencia($data);
            $registro = new Desvinculacion([...collect($data)->except('documento_id')->all(), 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);

            return FormulariosDatos::crearEnlazado('desvinculaciones', $usuario, $data['documento_id'] ?? null, $registro);
        });

        return (new JsonResource($this->fila($registro, self::CAMPOS)))->response()->setStatusCode(201);
    }

    /** Corrección de los campos enviados de un registro vigente; vuelve a exigir la coincidencia con el vínculo. */
    public function update(Request $request, string $usuario, string $registro): JsonResource|JsonResponse
    {
        $this->exigirLegajo($usuario);
        $actual = Desvinculacion::query()->where('usuario_id', $usuario)->findOrFail($registro);
        if ($actual->anulado) {
            return response()->json(['message' => 'La desvinculación está anulada: no se corrige. Registra una nueva.'], 409);
        }
        $data = $this->validarCambios($request, $actual, $this->reglas($usuario));
        DB::transaction(function () use ($actual, $data): void {
            $this->exigirCoherencia([...$this->fila($actual, ['vinculo_laboral_id', 'motivo_cese', 'fecha_cese'])['values'], ...$data], $actual->id);
            // Con documento de término enlazado, el motivo debe seguir correspondiendo a ese documento.
            if ($actual->documento && (isset($data['motivo_cese']) || isset($data['vinculo_laboral_id']))) {
                $regimen = VinculoLaboral::query()->whereKey($data['vinculo_laboral_id'] ?? $actual->vinculo_laboral_id)->value('regimen_laboral');
                if (! Seccion11::admite($actual->documento->tipoDocumento?->codigo, $data['motivo_cese'] ?? $actual->motivo_cese, $regimen)) {
                    throw ValidationException::withMessages(['motivo_cese' => 'El documento de término enlazado no corresponde a ese motivo de cese o régimen.']);
                }
            }
            $actual->update($data);
        });

        return new JsonResource($this->fila($actual->refresh(), self::CAMPOS));
    }

    public function anularRegistro(Request $request, string $usuario, string $registro): JsonResource|JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $resultado = $this->anular($request, Desvinculacion::query()->where('usuario_id', $usuario)->findOrFail($registro));

        return $resultado instanceof JsonResponse ? $resultado : new JsonResource($this->fila($resultado->resource, [...self::CAMPOS, 'anulado', 'motivo_anulacion']));
    }

    /** Campos del esquema: vinculo_laboral_id, motivo_cese y fecha_cese son NOT NULL. */
    private function reglas(string $usuario): array
    {
        return [
            'vinculo_laboral_id' => ['required', 'uuid', Rule::exists('vinculos_laborales', 'id')->where(fn ($q) => $q->where('usuario_id', $usuario)->where('anulado', false))],
            'motivo_cese' => ['required', Rule::in(Seccion11::MOTIVOS_CESE)],
            'fecha_cese' => 'required|date_format:Y-m-d',
            'numero_resolucion' => 'nullable|string|max:60',
            'fecha_entrega_cargo' => 'nullable|date_format:Y-m-d',
            'informe_gestion_presentado' => 'sometimes|boolean',
            'fecha_liquidacion' => 'nullable|date_format:Y-m-d',
            'observaciones' => 'nullable|string|max:2000',
        ];
    }

    /**
     * Decisión B: la fecha de cese del vínculo (sección 02) manda. El vínculo debe tener cese registrado, la fecha debe
     * coincidir y cada vínculo tiene una sola desvinculación vigente.
     */
    private function exigirCoherencia(array $data, ?string $excepto = null): void
    {
        $vinculo = VinculoLaboral::query()->whereKey($data['vinculo_laboral_id'])->lockForUpdate()->firstOrFail();
        if ($vinculo->fecha_cese === null) {
            throw ValidationException::withMessages(['vinculo_laboral_id' => 'El vínculo aún no tiene fecha de cese. Primero registra el cese en la sección 02 (Formalización del vínculo).']);
        }
        if ($vinculo->fecha_cese->format('Y-m-d') !== $data['fecha_cese']) {
            throw ValidationException::withMessages(['fecha_cese' => 'La fecha de cese debe coincidir con la del vínculo ('.$vinculo->fecha_cese->format('d/m/Y').'). Si es incorrecta, corrígela en la sección 02.']);
        }
        $duplicada = Desvinculacion::query()->where('vinculo_laboral_id', $vinculo->id)->where('anulado', false)
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))->exists();
        if ($duplicada) {
            throw ValidationException::withMessages(['vinculo_laboral_id' => 'Ese vínculo ya tiene una desvinculación registrada. Corrígela o anúlala.']);
        }
    }

    /** @param  Collection<int, DocumentoUsuario>  $documentos  del tipo, del más reciente al más antiguo */
    private function tipo(TipoDocumento $tipo, Collection $documentos, bool $sensibles): array
    {
        // Tipo sensible (liquidación) sin datos_sensibles.ver: se muestra que existe, sin sus documentos.
        $restringido = ($tipo->es_dato_sensible || $tipo->es_dato_salud) && ! $sensibles;
        $documentos = $restringido ? collect() : $documentos;
        $ultimo = $documentos->first();

        return [
            'id' => $tipo->id,
            'literal' => Seccion11::literal($tipo->codigo),
            ...$tipo->only(['codigo', 'nombre', 'descripcion', 'formulario_datos', 'obligatorio', 'permite_multiples', 'requiere_num_resolucion', 'es_hook_universidad', 'es_dato_sensible']),
            'bloque' => Seccion11::bloque($tipo->codigo),
            'principal' => Seccion11::principal($tipo->codigo),
            'motivos' => Seccion11::motivos($tipo->codigo),
            'solo_plazo_determinado' => $tipo->codigo === Seccion11::NO_RENOVACION_CAS,
            'restringido' => $restringido,
            'estado' => $ultimo ? EstadoDocumento::visible($ultimo) : 'pendiente',
            'documentos' => $documentos->count(),
            'lista' => $documentos->map(fn (DocumentoUsuario $documento): array => [
                ...$this->fila($documento, ['estado', 'folio_inicio', 'folio_fin', 'fecha_incorporacion', 'emisor', 'fecha_emision', 'titular_nombre', 'titular_documento', 'num_resolucion', 'modalidad_presentacion', 'relacion_titular', 'subsana_a_documento_id', 'vinculo_laboral_id', 'fecha_recepcion', 'created_at']),
                'subsanado' => $documentos->contains('subsana_a_documento_id', $documento->id),
                'estado_visible' => EstadoDocumento::visible($documento),
                'aprobado' => EstadoDocumento::aprobado($documento, $tipo),
                'subido_por' => $this->nombre($documento->createdBy),
                'recepcionado_por' => $this->nombre($documento->recepcionadoPor),
                'archivo' => $documento->archivo?->only(['nombre_original', 'num_paginas', 'tamano_bytes']),
            ])->values()->all(),
        ];
    }

    private function legajoAbierto(string $usuario): ?Legajo
    {
        return Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->orderByDesc('fecha_apertura')->first();
    }

    /** TODO pendiente de confirmar con el cliente: si se registra la desvinculación con el legajo ya pasivo (hoy no). */
    private function exigirLegajo(string $usuario): void
    {
        Usuario::query()->findOrFail($usuario);
        abort_unless($this->legajoAbierto($usuario), 409, 'La persona no tiene un legajo abierto (activo). Primero se debe abrir su legajo.');
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
        return ['vinculo_laboral_id.exists' => 'Selecciona un vínculo laboral de esta persona que no esté anulado.'];
    }

    /** @return array{id: string, values: array<string, mixed>} */
    private function fila(Model $registro, array $campos): array
    {
        $values = [];
        foreach ($campos as $campo) {
            $valor = $registro->getAttribute($campo);
            $values[$campo] = $valor instanceof \DateTimeInterface ? $valor->format(in_array($campo, ['created_at', 'updated_at'], true) ? 'Y-m-d H:i' : 'Y-m-d') : $valor;
        }

        return ['id' => $registro->getKey(), 'values' => $values];
    }

    private function nombre(?Usuario $usuario): ?string
    {
        return $usuario ? trim($usuario->nombres.' '.$usuario->apellido_paterno.' '.$usuario->apellido_materno) : null;
    }

    /** @param Collection<int, Model> $registros */
    private function filas(Collection $registros, array $campos = self::CAMPOS): array
    {
        return $registros->map(function (Model $registro) use ($campos): array {
            $fila = $this->fila($registro, $campos);
            $fila['values']['registrado_por'] = $this->nombre($registro->registradoPor);

            return $fila;
        })->values()->all();
    }
}
