<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\Concerns\AnulaConMotivo;
use App\Http\Controllers\Controller;
use App\Models\Desplazamiento;
use App\Models\DocumentoUsuario;
use App\Models\EvaluacionDesempeno;
use App\Models\Legajo;
use App\Models\PerfilDocente;
use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\Documentos\EstadoDocumento;
use App\Support\Documentos\FormulariosDatos;
use App\Support\PerfilDocumental;
use App\Support\ResumenSeccion02;
use App\Support\Seccion07;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sección 07 (Evaluación de desempeño, progresión en la carrera y desplazamiento) desde la ficha: lectura de las 3
 * subsecciones con sus datos y el estado de sus tipos de documento, resumen, y registro, corrección y anulación de
 * evaluaciones, progresiones (administrativos de carrera), ratificaciones (docentes) y desplazamientos.
 *
 * Nada se elimina: se anula con motivo y sale de listados y contadores. Un registro anulado no se corrige.
 * Sin seccion07.ver_calificacion la calificación y el puntaje no salen del backend.
 */
class Seccion07Controller extends Controller
{
    use AnulaConMotivo;

    /** Segmento de la ruta => [modelo, formulario_datos]. */
    private const TIPOS = [
        'evaluaciones-desempeno' => [EvaluacionDesempeno::class, 'evaluaciones_desempeno'],
        'progresiones-carrera' => [ProgresionCarrera::class, 'progresiones_carrera'],
        'ratificaciones-docentes' => [RatificacionDocente::class, 'ratificaciones_docentes'],
        'desplazamientos' => [Desplazamiento::class, 'desplazamientos'],
    ];

    private const CAMPOS = [
        'evaluaciones-desempeno' => ['ciclo_anio', 'fecha_evaluacion', 'calificacion', 'puntaje', 'plan_mejora', 'evaluador_id', 'documento_id', 'created_at'],
        'progresiones-carrera' => ['vinculo_laboral_id', 'nivel_anterior', 'nivel_nuevo', 'fecha_efectiva', 'numero_resolucion', 'documento_id', 'created_at'],
        'ratificaciones-docentes' => ['perfil_docente_id', 'tipo', 'categoria_anterior', 'categoria_nueva', 'numero_resolucion', 'fecha_resolucion', 'fecha_inicio_periodo', 'fecha_vencimiento_periodo', 'resultado', 'documento_id', 'created_at'],
        'desplazamientos' => ['tipo', 'unidad_origen_id', 'unidad_destino_id', 'puesto_destino', 'fecha_inicio', 'fecha_fin', 'numero_documento', 'documento_id', 'created_at'],
    ];

    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $veCalificacion = $request->user()->can('seccion07.ver_calificacion');
        $datos = $this->datos($persona);
        $hoy = today();
        $evaluaciones = $datos['registros']['evaluaciones-desempeno'];
        $ratificaciones = $datos['registros']['ratificaciones-docentes']->map(function (array $fila) use ($hoy): array {
            $vence = $fila['values']['fecha_vencimiento_periodo'];
            $dias = $vence ? (int) $hoy->diffInDays($vence, false) : null;
            $pendiente = in_array($fila['values']['resultado'], ['pendiente', null], true);

            return [...$fila, 'dias_para_vencer' => $dias,
                'alerta' => $dias === null || ! $pendiente ? null : ($dias < 0 ? 'vencida' : ($dias <= Seccion07::DIAS_AVISO_RATIFICACION ? 'por_vencer' : null))];
        });
        $actas = collect($datos['subsecciones'])->flatMap(fn (array $sub): array => $sub['tipos'])
            ->firstWhere('codigo', Seccion07::ACTA_RETROALIMENTACION);
        $ciclo = Seccion07::cicloEnCurso();

        return response()->json(['data' => [
            'legajo' => $datos['legajo']?->only(['id', 'numero_legajo', 'estado_legajo']),
            'titular' => ['nombre' => trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 'documento' => $persona->numero_doc_identidad],
            'hoy' => $hoy->format('Y-m-d'),
            've_calificacion' => $veCalificacion,
            'perfil' => $datos['perfil'],
            'resumen' => ResumenSeccion02::total($datos['subsecciones']),
            'subsecciones' => $datos['subsecciones'],
            'avisos' => [
                'ciclo_en_curso' => $ciclo,
                'evaluacion_ciclo_en_curso' => $evaluaciones->contains(fn (array $fila): bool => $fila['values']['ciclo_anio'] === $ciclo),
                // TODO pendiente de confirmar con el cliente: el acta (7.1 b) no se enlaza a una evaluación; se avisa si hay
                // evaluaciones con plan de mejora y ningún acta cargada.
                'planes_mejora_sin_acta' => ($actas['cargado'] ?? false) ? 0 : $evaluaciones->where('values.plan_mejora', true)->count(),
                'dias_aviso_ratificacion' => Seccion07::DIAS_AVISO_RATIFICACION,
            ],
            'evaluaciones' => $evaluaciones->map(fn (array $fila): array => $this->ocultarCalificacion($fila, $veCalificacion))->values(),
            'progresiones' => $datos['registros']['progresiones-carrera']->values(),
            'ratificaciones' => $ratificaciones->values(),
            'desplazamientos' => $datos['registros']['desplazamientos']->values(),
            'opciones' => [
                'vinculos_carrera' => $datos['vinculosCarrera']->map(fn (VinculoLaboral $v): array => ['id' => $v->id, 'etiqueta' => $this->etiquetaVinculo($v)])->values(),
                'perfiles_docentes' => $datos['perfilesDocentes']->map(fn (PerfilDocente $p): array => ['id' => $p->id,
                    'etiqueta' => $this->etiquetaVinculo($p->vinculoLaboral).' · '.str_replace('_', ' ', $p->condicion_docente).($p->categoria ? ' '.$p->categoria : '')])->values(),
                'unidades' => UnidadOrganica::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            ],
        ]]);
    }

    /** Resumen de la sección: tipos exigidos, pendientes, cargados y recepcionados, y estado por subsección. */
    public function resumen(string $usuario): JsonResponse
    {
        $datos = $this->datos(Usuario::query()->findOrFail($usuario));

        return response()->json(['data' => [
            'resumen' => ResumenSeccion02::total($datos['subsecciones']),
            'subsecciones' => array_map(fn (array $sub): array => collect($sub)->except('tipos')->all(), $datos['subsecciones']),
        ]]);
    }

    public function store(Request $request, string $usuario, string $tipo): JsonResponse
    {
        [$modelo, $formulario] = self::TIPOS[$tipo] ?? abort(404);
        $persona = $this->exigirLegajo($usuario);
        $data = $this->validar($request, [...$this->reglas($tipo, $usuario), 'documento_id' => 'nullable|uuid']);
        $this->exigirPerfil($tipo, $persona, $data);
        $documentoId = $data['documento_id'] ?? null;
        $registro = new $modelo([...collect($data)->except('documento_id')->all(), 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);
        $registro = FormulariosDatos::crearEnlazado($formulario, $usuario, $documentoId, $registro);

        return (new JsonResource($this->presentar($tipo, $registro, $request)))->response()->setStatusCode(201);
    }

    /**
     * Corrección de un registro vigente (solo los campos enviados). Un registro anulado no se corrige.
     * TODO pendiente de confirmar con el cliente: si un registro con documento verificado debe dejar de corregirse.
     */
    public function update(Request $request, string $usuario, string $tipo, string $registro): JsonResource|JsonResponse
    {
        [$modelo] = self::TIPOS[$tipo] ?? abort(404);
        $persona = $this->exigirLegajo($usuario);
        $actual = $modelo::query()->where('usuario_id', $usuario)->findOrFail($registro);
        if ($actual->anulado) {
            return response()->json(['message' => 'El registro está anulado: no se corrige. Registra uno nuevo.'], 409);
        }
        $data = $this->validarCambios($request, $actual, $this->reglas($tipo, $usuario));
        $this->exigirPerfil($tipo, $persona, [...$actual->only(['vinculo_laboral_id', 'perfil_docente_id']), ...$data]);
        if ($actual instanceof Desplazamiento && isset($data['tipo']) && $actual->documento
            && ! Seccion07::admiteDesplazamiento($actual->documento->tipoDocumento?->codigo, $data['tipo'])) {
            throw ValidationException::withMessages(['tipo' => 'El documento enlazado no acredita ese tipo de desplazamiento.']);
        }
        $actual->update($data);

        return new JsonResource($this->presentar($tipo, $actual->refresh(), $request));
    }

    public function anularRegistro(Request $request, string $usuario, string $tipo, string $registro): JsonResource|JsonResponse
    {
        [$modelo] = self::TIPOS[$tipo] ?? abort(404);
        Usuario::query()->findOrFail($usuario);
        $resultado = $this->anular($request, $modelo::query()->where('usuario_id', $usuario)->findOrFail($registro));

        return $resultado instanceof JsonResponse ? $resultado : new JsonResource($this->presentar($tipo, $resultado->resource, $request));
    }

    /**
     * Datos de la persona para la ficha y el resumen (sin anulados).
     *
     * @return array{legajo: ?Legajo, perfil: array, subsecciones: list<array>, registros: array<string, Collection>, vinculosCarrera: Collection, perfilesDocentes: Collection}
     */
    private function datos(Usuario $persona): array
    {
        $usuario = $persona->id;
        $vinculos = VinculoLaboral::query()->where('usuario_id', $usuario)->where('anulado', false)->with('perfilDocente')->orderByDesc('fecha_inicio')->orderBy('id')->get();
        $perfil = PerfilDocumental::de($persona, $vinculos);
        $carrera = in_array('carrera', $perfil->regimenesClasificados(), true);
        $vinculosCarrera = $vinculos->where('tipo_personal', 'administrativo')->where('regimen_laboral', 'carrera')->values();
        $perfilesDocentes = $vinculos->where('tipo_personal', 'docente')->flatMap(fn (VinculoLaboral $v) => $v->perfilDocente->each(fn (PerfilDocente $p) => $p->setRelation('vinculoLaboral', $v)))->values();

        $registros = [];
        foreach (self::TIPOS as $tipo => [$modelo]) {
            $orden = ['evaluaciones-desempeno' => 'ciclo_anio', 'progresiones-carrera' => 'fecha_efectiva', 'ratificaciones-docentes' => 'fecha_resolucion', 'desplazamientos' => 'fecha_inicio'][$tipo];
            $registros[$tipo] = collect($this->filas($modelo::query()->where('usuario_id', $usuario)->where('anulado', false)->with('registradoPor')
                ->orderByDesc($orden)->orderBy('id')->get(), self::CAMPOS[$tipo]));
        }
        $evaluadores = Usuario::query()->whereIn('id', $registros['evaluaciones-desempeno']->pluck('values.evaluador_id')->filter()->unique())->get()->keyBy('id');
        $registros['evaluaciones-desempeno'] = $registros['evaluaciones-desempeno']->map(function (array $fila) use ($evaluadores): array {
            $fila['values']['evaluador'] = $this->nombre($evaluadores->get($fila['values']['evaluador_id']));

            return $fila;
        });

        $documentos = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('seccion_legajo_id', Seccion07::SECCION)
            ->whereIn('legajo_id', Legajo::query()->where('usuario_id', $usuario)->select('id'))
            ->with(['archivo', 'recepcionadoPor', 'createdBy'])->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('tipo_documento_id');
        $nivel = fn (Collection $filas): string => $filas->isNotEmpty() ? 'completo' : 'ninguno';
        $niveles = [
            '7.1' => $nivel($registros['evaluaciones-desempeno']),
            '7.2' => $nivel($registros['progresiones-carrera']->concat($registros['ratificaciones-docentes'])),
            '7.3' => $nivel($registros['desplazamientos']),
        ];
        $subsecciones = SubseccionLegajo::query()->where('seccion_legajo_id', Seccion07::SECCION)->orderBy('orden')
            ->with(['tiposDocumento' => fn ($query) => $query->where('activo', true)->orderBy('orden')])->get()
            ->map(function (SubseccionLegajo $subseccion) use ($perfil, $documentos, $niveles, $carrera): array {
                // 7.2 existe siempre; solo aplica a servidores de carrera (directiva: "servidor/a civil de carrera").
                $aplica = $subseccion->codigo !== '7.2' || $carrera;
                $tipos = $subseccion->tiposDocumento->map(fn (TipoDocumento $tipo): array => $this->tipo($tipo, $perfil, $documentos->get($tipo->id, new EloquentCollection)))->values()->all();
                $datos = $niveles[$subseccion->codigo] ?? 'ninguno';

                return ['codigo' => $subseccion->codigo, 'nombre' => $subseccion->nombre, 'aplica' => $aplica, 'datos' => $datos,
                    ...ResumenSeccion02::subseccion($aplica, $tipos, $datos), 'tipos' => $tipos];
            })->values()->all();

        return [
            'legajo' => Legajo::paraConsulta($usuario),
            'perfil' => ['regimenes' => $perfil->regimenes, 'tipos_personal' => $perfil->tiposPersonal, 'es_carrera' => $carrera,
                'administrativo_carrera' => $vinculosCarrera->isNotEmpty(), 'docente' => $perfilesDocentes->isNotEmpty()],
            'subsecciones' => $subsecciones,
            'registros' => $registros,
            'vinculosCarrera' => $vinculosCarrera,
            'perfilesDocentes' => $perfilesDocentes,
        ];
    }

    /** Reglas por tabla: campos NOT NULL del esquema como obligatorios y enums cerrados. */
    private function reglas(string $tipo, string $usuario): array
    {
        $fecha = 'date_format:Y-m-d';

        return match ($tipo) {
            'evaluaciones-desempeno' => [
                'ciclo_anio' => 'required|integer|between:1900,'.(today()->year + 1),
                'fecha_evaluacion' => "nullable|{$fecha}|before_or_equal:today",
                'calificacion' => 'required|string|max:40',
                'puntaje' => 'nullable|numeric|between:0,999.99',
                'plan_mejora' => 'sometimes|boolean',
                'evaluador_id' => 'nullable|uuid|exists:usuarios,id',
            ],
            'progresiones-carrera' => [
                'vinculo_laboral_id' => ['required', 'uuid', Rule::exists('vinculos_laborales', 'id')->where(fn ($q) => $q->where('usuario_id', $usuario)->where('anulado', false))],
                'nivel_anterior' => 'nullable|string|max:20',
                'nivel_nuevo' => 'required|string|max:20',
                'fecha_efectiva' => "required|{$fecha}",
                'numero_resolucion' => 'required|string|max:60',
            ],
            'ratificaciones-docentes' => [
                'perfil_docente_id' => ['required', 'uuid', Rule::exists('perfil_docente', 'id')],
                'tipo' => ['required', Rule::in(Seccion07::TIPOS_RATIFICACION)],
                'categoria_anterior' => ['nullable', Rule::in(Seccion07::CATEGORIAS_DOCENTE)],
                'categoria_nueva' => ['required', Rule::in(Seccion07::CATEGORIAS_DOCENTE)],
                'numero_resolucion' => 'required|string|max:60',
                'fecha_resolucion' => "required|{$fecha}|before_or_equal:today",
                'fecha_inicio_periodo' => "required|{$fecha}",
                'fecha_vencimiento_periodo' => "required|{$fecha}|after_or_equal:fecha_inicio_periodo",
                'resultado' => ['sometimes', Rule::in(Seccion07::RESULTADOS_RATIFICACION)],
            ],
            'desplazamientos' => [
                'tipo' => ['required', Rule::in(Seccion07::TIPOS_DESPLAZAMIENTO)],
                'unidad_origen_id' => 'nullable|uuid|exists:unidades_organicas,id',
                'unidad_destino_id' => 'nullable|uuid|exists:unidades_organicas,id',
                'puesto_destino' => 'nullable|string|max:150',
                'fecha_inicio' => "required|{$fecha}",
                'fecha_fin' => "nullable|{$fecha}|after_or_equal:fecha_inicio",
                'numero_documento' => 'nullable|string|max:60',
            ],
        };
    }

    /** Progresión: solo con vínculo administrativo de carrera. Ratificación: solo con perfil docente de un vínculo de la persona. */
    private function exigirPerfil(string $tipo, Usuario $persona, array $data): void
    {
        if ($tipo === 'progresiones-carrera' && array_key_exists('vinculo_laboral_id', $data)) {
            $valido = VinculoLaboral::query()->whereKey($data['vinculo_laboral_id'])->where('usuario_id', $persona->id)->where('anulado', false)
                ->where('tipo_personal', 'administrativo')->where('regimen_laboral', 'carrera')->exists();
            if (! $valido) {
                throw ValidationException::withMessages(['vinculo_laboral_id' => 'La progresión en la carrera es solo para personal administrativo de carrera: selecciona un vínculo administrativo de régimen carrera de esta persona (regístralo en 2.2 si falta).']);
            }
        }
        if ($tipo === 'ratificaciones-docentes' && array_key_exists('perfil_docente_id', $data)) {
            $valido = PerfilDocente::query()->whereKey($data['perfil_docente_id'])
                ->whereHas('vinculoLaboral', fn ($q) => $q->where('usuario_id', $persona->id)->where('anulado', false)->where('tipo_personal', 'docente'))->exists();
            if (! $valido) {
                throw ValidationException::withMessages(['perfil_docente_id' => 'La ratificación o promoción es solo para personal docente: selecciona el perfil docente de un vínculo de esta persona (datos del puesto en 2.2).']);
            }
        }
    }

    /** @param  Collection<int, DocumentoUsuario>  $documentos  del tipo, del más reciente al más antiguo */
    private function tipo(TipoDocumento $tipo, PerfilDocumental $perfil, Collection $documentos): array
    {
        $ultimo = $documentos->first();

        return [
            'id' => $tipo->id,
            'literal' => Seccion07::literal($tipo->codigo),
            ...$tipo->only(['codigo', 'nombre', 'descripcion', 'formulario_datos', 'obligatorio', 'permite_multiples', 'requiere_num_resolucion', 'es_hook_universidad']),
            'desplazamientos' => Seccion07::desplazamientos($tipo->codigo),
            ...$perfil->exigencia($tipo),
            'estado' => $ultimo ? EstadoDocumento::visible($ultimo) : 'pendiente',
            'cargado' => $documentos->contains(fn (DocumentoUsuario $documento): bool => in_array($documento->estado, ResumenSeccion02::ESTADOS_CARGADO, true)),
            'recepcionado' => $documentos->contains(fn (DocumentoUsuario $documento): bool => in_array($documento->estado, ResumenSeccion02::ESTADOS_CARGADO, true) && EstadoDocumento::recepcionado($documento)),
            'verificado' => $documentos->contains('estado', 'verificado'),
            'documentos' => $documentos->count(),
            'documento' => $ultimo ? $this->fila($ultimo, ['estado', 'fecha_incorporacion', 'folio_inicio', 'folio_fin', 'emisor', 'fecha_emision', 'titular_nombre', 'titular_documento', 'num_resolucion', 'fecha_recepcion'])['values'] : null,
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

    /** Respuesta de alta, corrección o anulación, con la calificación oculta sin su permiso. */
    private function presentar(string $tipo, Model $registro, Request $request): array
    {
        $fila = $this->fila($registro, [...self::CAMPOS[$tipo], 'anulado', 'motivo_anulacion']);

        return $tipo === 'evaluaciones-desempeno' ? $this->ocultarCalificacion($fila, $request->user()->can('seccion07.ver_calificacion')) : $fila;
    }

    /** TODO pendiente de decisión del cliente: sin seccion07.ver_calificacion la calificación y el puntaje no se envían. */
    private function ocultarCalificacion(array $fila, bool $veCalificacion): array
    {
        if (! $veCalificacion) {
            $fila['values']['calificacion'] = null;
            $fila['values']['puntaje'] = null;
        }
        $fila['calificacion_oculta'] = ! $veCalificacion;

        return $fila;
    }

    private function etiquetaVinculo(?VinculoLaboral $vinculo): string
    {
        if (! $vinculo) {
            return 'Vínculo no encontrado';
        }

        return $vinculo->nombre_puesto.' · '.($vinculo->numero_documento_vinculo ?: 'sin N.º de documento').' · desde '.$vinculo->fecha_inicio?->format('d/m/Y').($vinculo->fecha_cese ? ' (cesado)' : '');
    }

    private function legajoAbierto(string $usuario): ?Legajo
    {
        return Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->orderByDesc('fecha_apertura')->first();
    }

    private function exigirLegajo(string $usuario): Usuario
    {
        $persona = Usuario::query()->findOrFail($usuario);
        abort_unless($this->legajoAbierto($usuario), 409, 'La persona no tiene un legajo abierto. Primero se debe abrir su legajo.');

        return $persona;
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
        return [
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior a la fecha de inicio.',
            'fecha_vencimiento_periodo.after_or_equal' => 'El vencimiento del período no puede ser anterior a su inicio.',
            'vinculo_laboral_id.exists' => 'Selecciona un vínculo laboral vigente (no anulado) de esta persona.',
        ];
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
