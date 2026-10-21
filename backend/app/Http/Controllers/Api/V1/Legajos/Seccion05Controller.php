<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\Concerns\AnulaConMotivo;
use App\Http\Controllers\Controller;
use App\Models\DocumentoUsuario;
use App\Models\GoceVacacional;
use App\Models\Legajo;
use App\Models\LicenciaPermiso;
use App\Models\PeriodoVacacional;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\Documentos\EstadoDocumento;
use App\Support\Documentos\FormulariosDatos;
use App\Support\Seccion05;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sección 05 (Movimientos del personal) desde la ficha: licencias, permisos y suspensiones; vacaciones (períodos y
 * goces, adelantos o postergaciones); y los documentos de la sección por tipo (literales a a e del Anexo 01).
 *
 * Los registros no se corrigen ni se eliminan: si hay un error, se anulan con motivo (administración) y se
 * registran de nuevo. Un trabajador tiene tantos registros y documentos como movimientos haya tenido.
 * El descanso médico es dato de salud (Directiva, num. 6.2): sin datos_sensibles.ver no se lee ni se registra.
 */
class Seccion05Controller extends Controller
{
    use AnulaConMotivo;

    private const ANULABLES = ['licencias-permisos' => LicenciaPermiso::class, 'periodos-vacacionales' => PeriodoVacacional::class,
        'goces-vacacionales' => GoceVacacional::class];

    private const CAMPOS_LICENCIA = ['tipo', 'forma_permiso', 'con_goce', 'fecha_inicio', 'fecha_fin', 'dias', 'numero_documento', 'documento_id', 'created_at'];

    private const CAMPOS_PERIODO = ['vinculo_laboral_id', 'periodo_anio', 'dias_derecho', 'created_at'];

    private const CAMPOS_GOCE = ['periodo_vacacional_id', 'tipo', 'fecha_inicio', 'fecha_fin', 'dias', 'numero_documento', 'documento_id', 'created_at'];

    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $salud = $request->user()->veDatosSensibles();
        $legajo = Legajo::paraConsulta($usuario);
        $licencias = $this->licencias($usuario, $salud)->where('anulado', false)->with('registradoPor')
            ->orderByDesc('fecha_inicio')->orderBy('id')->get();
        $periodos = PeriodoVacacional::query()->where('usuario_id', $usuario)->where('anulado', false)->with('registradoPor')
            ->orderByDesc('periodo_anio')->orderBy('id')->get();
        $goces = GoceVacacional::query()->where('usuario_id', $usuario)->where('anulado', false)->with('registradoPor')
            ->orderByDesc('fecha_inicio')->orderBy('id')->get();
        $vinculos = VinculoLaboral::query()->where('usuario_id', $usuario)->where('anulado', false)
            ->orderByDesc('fecha_inicio')->orderBy('id')->get(['id', 'nombre_puesto', 'numero_documento_vinculo', 'numero_resolucion', 'numero_contrato', 'fecha_inicio', 'fecha_cese']);

        $documentos = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('seccion_legajo_id', Seccion05::SECCION)
            ->whereIn('legajo_id', Legajo::query()->where('usuario_id', $usuario)->select('id'))
            ->with(['archivo', 'recepcionadoPor', 'createdBy'])->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('tipo_documento_id');
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', Seccion05::SECCION)->where('activo', true)->orderBy('orden')->get()
            ->map(fn (TipoDocumento $tipo): array => $this->tipo($tipo, $documentos->get($tipo->id, collect()), $salud))->values();

        return response()->json(['data' => [
            'legajo' => $legajo?->only(['id', 'numero_legajo', 'estado_legajo']),
            'titular' => ['nombre' => trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 'documento' => $persona->numero_doc_identidad],
            've_datos_salud' => $salud,
            'tipos' => $tipos,
            'licencias' => $this->filas($licencias, self::CAMPOS_LICENCIA),
            'periodos' => $periodos->map(fn (PeriodoVacacional $periodo): array => [
                ...$this->filas(collect([$periodo]), self::CAMPOS_PERIODO)[0],
                // Informativo: días de goce y adelanto registrados en el período (la directiva no define un saldo).
                'dias_registrados' => $goces->where('periodo_vacacional_id', $periodo->id)->whereIn('tipo', ['goce', 'adelanto'])->sum('dias'),
            ])->values(),
            'goces' => $this->filas($goces, self::CAMPOS_GOCE),
            'vinculos' => $vinculos->map(fn (VinculoLaboral $vinculo): array => [
                'id' => $vinculo->id, 'nombre_puesto' => $vinculo->nombre_puesto, 'numero_documento_vinculo' => $vinculo->numero_documento_vinculo,
                'numero_resolucion' => $vinculo->numero_resolucion, 'numero_contrato' => $vinculo->numero_contrato,
                'fecha_inicio' => $vinculo->fecha_inicio?->format('Y-m-d'), 'fecha_cese' => $vinculo->fecha_cese?->format('Y-m-d'),
            ])->values(),
        ]]);
    }

    /** Registros anulados: vista aparte, solo con sistema.administrar (ruta). */
    public function anulados(Request $request, string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $anulados = fn (Builder $query, array $campos): array => $this->filas($query->where('usuario_id', $usuario)->where('anulado', true)
            ->with('registradoPor')->orderByDesc('updated_at')->orderBy('id')->get(), [...$campos, 'motivo_anulacion', 'updated_at']);

        return response()->json(['data' => [
            'licencias_permisos' => $anulados($this->licencias($usuario, $request->user()->veDatosSensibles()), self::CAMPOS_LICENCIA),
            'periodos_vacacionales' => $anulados(PeriodoVacacional::query(), self::CAMPOS_PERIODO),
            'goces_vacacionales' => $anulados(GoceVacacional::query(), self::CAMPOS_GOCE),
        ]]);
    }

    public function storeLicencia(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $request->mergeIfMissing(['con_goce' => true]);
        $data = $this->validar($request, [
            'tipo' => ['required', Rule::in(Seccion05::TIPOS_LICENCIA)],
            // Obligatoria en los permisos nuevos; los demás movimientos no la llevan.
            'forma_permiso' => ['required_if:tipo,permiso', 'prohibited_unless:tipo,permiso', 'nullable', Rule::in(Seccion05::FORMAS_PERMISO)],
            'con_goce' => 'boolean',
            ...$this->reglasPeriodoDias(0),
            'numero_documento' => 'nullable|string|max:60',
            'documento_id' => 'nullable|uuid',
        ]);
        abort_if(in_array($data['tipo'], Seccion05::DATOS_SALUD, true) && ! $request->user()->veDatosSensibles(), 403, 'El descanso médico es un dato de salud: requiere acceso a datos sensibles.');
        $this->exigirDias($data);
        $this->documento($usuario, $data, 'licencias_permisos');

        return $this->creado(LicenciaPermiso::query()->create([...$data, 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]));
    }

    public function storePeriodo(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $request->mergeIfMissing(['dias_derecho' => 30]);
        $data = $this->validar($request, [
            // Vínculo de esta persona, no anulado. Se admite uno ya cesado para registrar vacaciones de vínculos anteriores.
            'vinculo_laboral_id' => ['required', 'uuid', Rule::exists('vinculos_laborales', 'id')->where(fn ($query) => $query->where('usuario_id', $usuario)->where('anulado', false))],
            'periodo_anio' => 'required|integer|between:1950,'.(today()->year + 1),
            // 30 es el valor por defecto del esquema; se puede indicar otro.
            'dias_derecho' => 'integer|between:1,366',
        ], ['vinculo_laboral_id.exists' => 'Selecciona un vínculo laboral de esta persona que no esté anulado.']);

        return $this->creado(DB::transaction(function () use ($data, $usuario, $request): PeriodoVacacional {
            // Un solo período por vínculo y año (los anulados no cuentan).
            $duplicado = PeriodoVacacional::query()->where('vinculo_laboral_id', $data['vinculo_laboral_id'])
                ->where('periodo_anio', $data['periodo_anio'])->where('anulado', false)->lockForUpdate()->exists();
            if ($duplicado) {
                throw ValidationException::withMessages(['periodo_anio' => 'Ese vínculo ya tiene registrado el período vacacional de ese año.']);
            }

            return PeriodoVacacional::query()->create([...$data, 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);
        }));
    }

    public function storeGoce(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $data = $this->validar($request, [
            'periodo_vacacional_id' => ['required', 'uuid', Rule::exists('periodos_vacacionales', 'id')->where(fn ($query) => $query->where('usuario_id', $usuario)->where('anulado', false))],
            'tipo' => ['required', Rule::in(Seccion05::TIPOS_GOCE)],
            ...$this->reglasPeriodoDias(1),
            'numero_documento' => 'nullable|string|max:60',
            'documento_id' => 'nullable|uuid',
        ], ['periodo_vacacional_id.exists' => 'Selecciona un período vacacional de esta persona que no esté anulado.']);
        $this->exigirDias($data);
        $this->documento($usuario, $data, 'goces_vacacionales');

        return $this->creado(GoceVacacional::query()->create([...$data, 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]));
    }

    public function anularRegistro(Request $request, string $usuario, string $tipo, string $registro): JsonResource|JsonResponse
    {
        $modelo = self::ANULABLES[$tipo] ?? abort(404);
        $query = $modelo === LicenciaPermiso::class ? $this->licencias($usuario, $request->user()->veDatosSensibles()) : $modelo::query()->where('usuario_id', $usuario);
        $encontrado = $query->findOrFail($registro);
        // Un período con goces vigentes no se anula: primero se anulan sus goces.
        if ($encontrado instanceof PeriodoVacacional && ! $encontrado->anulado && $encontrado->gocesVacacionales()->where('anulado', false)->exists()) {
            return response()->json(['message' => 'El período tiene goces, adelantos o postergaciones vigentes: anúlalos primero.'], 409);
        }

        return $this->anular($request, $encontrado);
    }

    /** Licencias de la persona; sin datos_sensibles.ver se excluyen las que son datos de salud. */
    private function licencias(string $usuario, bool $salud): Builder
    {
        $query = LicenciaPermiso::query()->where('usuario_id', $usuario);

        return $salud ? $query : $query->whereNotIn('tipo', Seccion05::DATOS_SALUD);
    }

    /** @param  Collection<int, DocumentoUsuario>  $documentos  del tipo, del más reciente al más antiguo */
    private function tipo(TipoDocumento $tipo, Collection $documentos, bool $salud): array
    {
        // Tipo de salud sin datos_sensibles.ver: se muestra que existe, pero sin sus documentos.
        $restringido = ($tipo->es_dato_salud || $tipo->es_dato_sensible) && ! $salud;
        $documentos = $restringido ? collect() : $documentos;
        $ultimo = $documentos->first();

        return [
            'id' => $tipo->id,
            'literal' => preg_match('/^S05_([A-Z])_/', $tipo->codigo, $match) ? strtolower($match[1]) : null,
            ...$tipo->only(['codigo', 'nombre', 'descripcion', 'formulario_datos', 'obligatorio', 'permite_multiples', 'requiere_num_resolucion', 'es_hook_universidad', 'es_dato_salud']),
            'bloque' => Seccion05::bloque($tipo->codigo) ?? 'otros',
            'registros' => Seccion05::registros($tipo->codigo),
            'restringido' => $restringido,
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

    /** Fechas del movimiento y días (calendario o hábiles, según el documento: no se calculan aquí). */
    private function reglasPeriodoDias(int $minimoDias): array
    {
        return [
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'dias' => 'required|integer|min:'.$minimoDias,
        ];
    }

    /** Los días no pueden superar los días calendario del rango (fecha_fin - fecha_inicio + 1). */
    private function exigirDias(array $data): void
    {
        $rango = (int) Carbon::parse($data['fecha_inicio'])->diffInDays(Carbon::parse($data['fecha_fin'])) + 1;
        if ($data['dias'] > $rango) {
            throw ValidationException::withMessages(['dias' => "Los días no pueden superar los {$rango} día(s) calendario entre la fecha de inicio y la de fin."]);
        }
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

    /** Documento recién subido que el registro acredita: de esta persona, con este formulario y compatible con el tipo de movimiento. */
    private function documento(string $usuario, array $data, string $formulario): void
    {
        if (empty($data['documento_id'])) {
            return;
        }
        $documento = FormulariosDatos::documentoPara($usuario, $data['documento_id'], $formulario);
        if (! Seccion05::admite($documento->tipoDocumento->codigo, $data['tipo'])) {
            throw ValidationException::withMessages(['documento_id' => 'Ese documento no acredita este tipo de movimiento.']);
        }
    }

    /** Valida y rechaza campos que no son del formulario (autor, anulación, etc.). */
    private function validar(Request $request, array $reglas, array $mensajes = []): array
    {
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }

        return $request->validate($reglas, $mensajes);
    }

    private function creado(Model $registro): JsonResponse
    {
        return (new JsonResource($registro->refresh()))->response()->setStatusCode(201);
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

    /** @param  Collection<int, Model>  $registros */
    private function filas(Collection $registros, array $campos): array
    {
        return $registros->map(function (Model $registro) use ($campos): array {
            $fila = $this->fila($registro, $campos);
            $fila['values']['registrado_por'] = $this->nombre($registro->registradoPor);

            return $fila;
        })->values()->all();
    }
}
