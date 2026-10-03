<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\Concerns\AnulaConMotivo;
use App\Http\Controllers\Controller;
use App\Models\DocumentoUsuario;
use App\Models\Induccion;
use App\Models\Legajo;
use App\Models\PeriodoPrueba;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use App\Support\Parametros;
use App\Support\PerfilDocumental;
use App\Support\ResumenSeccion02;
use Database\Seeders\Seccion02CatalogoSeeder;
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
 * Sección 02 (Incorporación) desde la ficha: lectura de las 4 subsecciones con sus datos y el estado
 * de sus tipos de documento, y registro de vínculos, adendas, inducciones y períodos de prueba.
 *
 * Inducciones y períodos de prueba no se eliminan: se anulan con motivo. Un vínculo se corrige solo
 * mientras no tenga fecha de cese, y un período de prueba solo mientras su resultado sea pendiente.
 * Vínculos y adendas no tienen columnas de anulación en el esquema: no se anulan desde aquí.
 */
class Seccion02Controller extends Controller
{
    use AnulaConMotivo;

    private const ANULABLES = ['inducciones' => Induccion::class, 'periodos-prueba' => PeriodoPrueba::class];

    private const REGLAS_VINCULO = [
        'tipo_personal' => 'required|in:docente,administrativo',
        'regimen_laboral' => 'required|in:carrera,plazo_determinado,designado',
        'regimen_normativo_id' => 'required|uuid|exists:regimenes_normativos,id',
        'unidad_organica_id' => 'nullable|uuid|exists:unidades_organicas,id',
        'nombre_puesto' => 'required|string|max:150',
        'obligado_djbr' => 'sometimes|boolean',
        'exige_colegiatura' => 'sometimes|boolean',
        'tiene_periodo_prueba' => 'sometimes|boolean',
        'proceso_seleccion' => 'nullable|string|max:100',
        'fecha_resultado_seleccion' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        'numero_documento_vinculo' => 'nullable|string|max:60',
        'fecha_inicio' => 'required|date_format:Y-m-d',
        'fecha_fin_prevista' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio',
        'fecha_cese' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio|before_or_equal:today',
    ];

    private const REGLAS_PERIODO = [
        'fecha_inicio' => 'required|date_format:Y-m-d',
        'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
        'resultado' => 'sometimes|in:pendiente,aprobado,no_aprobado',
        'fecha_resultado' => 'nullable|required_unless:resultado,pendiente|prohibited_if:resultado,pendiente|date_format:Y-m-d|after_or_equal:fecha_inicio|before_or_equal:today',
    ];

    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $legajo = $this->legajoAbierto($usuario);
        $vinculos = VinculoLaboral::query()->where('usuario_id', $usuario)
            ->with(['regimenNormativo:id,nombre', 'unidadOrganica:id,nombre', 'perfilDocente', 'perfilAdministrativo',
                'vinculoAdendas' => fn ($query) => $query->with('registradoPor')->orderBy('fecha_inicio')->orderBy('created_at')->orderBy('id')])
            ->orderByDesc('fecha_inicio')->orderBy('id')->get();
        $perfil = PerfilDocumental::de($persona, $vinculos);
        $inducciones = Induccion::query()->where('usuario_id', $usuario)->where('anulado', false)->with('registradoPor')->orderByDesc('fecha')->orderBy('id')->get();
        $periodos = PeriodoPrueba::query()->where('usuario_id', $usuario)->where('anulado', false)->with('registradoPor')->orderByDesc('fecha_inicio')->orderBy('id')->get();
        $aviso = Parametros::entero('dias_aviso_vencimiento_vinculo');
        // Vigentes primero; dentro de cada grupo, del más reciente al más antiguo.
        $filasVinculos = $vinculos->map(fn (VinculoLaboral $vinculo): array => $this->vinculo($vinculo, $aviso, $inducciones))
            ->sortBy(fn (array $fila): int => $fila['vigente'] ? 0 : 1)->values();
        $vigentes = $filasVinculos->where('vigente', true);

        $documentos = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('seccion_legajo_id', Seccion02CatalogoSeeder::SECCION)
            ->whereIn('legajo_id', Legajo::query()->where('usuario_id', $usuario)->select('id'))
            ->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('tipo_documento_id');
        $datos = [
            '2.1' => $this->nivel($vigentes->contains(fn (array $v): bool => $v['values']['proceso_seleccion'] && $v['values']['fecha_resultado_seleccion']),
                $filasVinculos->contains(fn (array $v): bool => $v['values']['proceso_seleccion'] || $v['values']['fecha_resultado_seleccion'])),
            '2.2' => $this->nivel($vigentes->isNotEmpty(), $filasVinculos->isNotEmpty()),
            '2.3' => $this->nivel($vigentes->isNotEmpty() && $vigentes->every(fn (array $v): bool => $v['inducciones_faltantes'] === []), $inducciones->isNotEmpty()),
            '2.4' => $this->nivel($periodos->contains(fn (PeriodoPrueba $p): bool => $p->resultado !== 'pendiente'), $periodos->isNotEmpty()),
        ];
        $subsecciones = SubseccionLegajo::query()->where('seccion_legajo_id', Seccion02CatalogoSeeder::SECCION)->orderBy('orden')
            ->with(['tiposDocumento' => fn ($query) => $query->where('activo', true)->orderBy('orden')])->get()
            ->map(function (SubseccionLegajo $subseccion) use ($perfil, $documentos, $datos): array {
                // 2.4 existe siempre; solo aplica (y exige) si la persona tiene período de prueba.
                $aplica = $subseccion->codigo !== '2.4' || $perfil->tienePeriodoPrueba();
                $tipos = $subseccion->tiposDocumento->map(fn (TipoDocumento $tipo): array => $this->tipo($tipo, $perfil, $documentos->get($tipo->id, new EloquentCollection)))->values()->all();

                return ['codigo' => $subseccion->codigo, 'nombre' => $subseccion->nombre, 'aplica' => $aplica, 'datos' => $datos[$subseccion->codigo] ?? 'ninguno',
                    ...ResumenSeccion02::subseccion($aplica, $tipos, $datos[$subseccion->codigo] ?? 'ninguno'), 'tipos' => $tipos];
            })->values();

        return response()->json(['data' => [
            'legajo' => $legajo?->only(['id', 'numero_legajo', 'estado_legajo']),
            'hoy' => today()->format('Y-m-d'),
            'dias_aviso_vencimiento' => $aviso,
            'tiene_periodo_prueba' => $perfil->tienePeriodoPrueba(),
            'perfil' => ['regimenes' => $perfil->regimenes, 'regimenes_clasificados' => $perfil->regimenesClasificados(), 'tipos_personal' => $perfil->tiposPersonal, 'banderas' => $perfil->banderas],
            'resumen' => ResumenSeccion02::total($subsecciones->all()),
            'subsecciones' => $subsecciones,
            'vinculos' => $filasVinculos,
            'inducciones' => $this->filas($inducciones, ['vinculo_laboral_id', 'tipo', 'fecha', 'responsable', 'documento_id', 'created_at']),
            'periodos_prueba' => $periodos->map(fn (PeriodoPrueba $periodo): array => $this->periodo($periodo))->values(),
        ]]);
    }

    /** Inducciones y períodos de prueba anulados: vista aparte, solo con sistema.administrar (ruta). */
    public function anulados(string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $anulados = fn (string $modelo, array $campos): array => $this->filas($modelo::query()->where('usuario_id', $usuario)->where('anulado', true)
            ->with('registradoPor')->orderByDesc('updated_at')->orderBy('id')->get(), [...$campos, 'motivo_anulacion', 'created_at', 'updated_at']);

        return response()->json(['data' => [
            'inducciones' => $anulados(Induccion::class, ['vinculo_laboral_id', 'tipo', 'fecha', 'responsable']),
            'periodos_prueba' => $anulados(PeriodoPrueba::class, ['vinculo_laboral_id', 'fecha_inicio', 'fecha_fin', 'resultado', 'fecha_resultado']),
        ]]);
    }

    public function storeVinculo(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $data = $this->validar($request, self::REGLAS_VINCULO);

        return $this->creado(VinculoLaboral::query()->create([...$data, 'usuario_id' => $usuario]));
    }

    public function updateVinculo(Request $request, string $usuario, string $vinculo): JsonResource|JsonResponse
    {
        $this->exigirLegajo($usuario);
        $registro = VinculoLaboral::query()->where('usuario_id', $usuario)->findOrFail($vinculo);
        if ($registro->fecha_cese !== null) {
            return response()->json(['message' => 'El vínculo tiene fecha de cese: es un registro cerrado y no se corrige.'], 409);
        }
        $registro->update($this->validarCambios($request, $registro, self::REGLAS_VINCULO));

        return new JsonResource($registro->refresh());
    }

    public function storeAdenda(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $data = $this->validar($request, [
            'vinculo_laboral_id' => $this->reglaVinculo($usuario),
            'numero' => 'nullable|string|max:40',
            'motivo' => 'nullable|string|max:200',
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
        ]);

        return $this->creado(VinculoAdenda::query()->create([...$data, 'registrado_por' => $request->user()->getAuthIdentifier()]));
    }

    public function storeInduccion(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $data = $this->validar($request, [
            'vinculo_laboral_id' => $this->reglaVinculo($usuario),
            'tipo' => 'required|in:general,especifica',
            'fecha' => 'required|date_format:Y-m-d|before_or_equal:today',
            'responsable' => 'nullable|string|max:150',
        ]);

        return $this->creado(Induccion::query()->create([...$data, 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]));
    }

    public function storePeriodo(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $request->mergeIfMissing(['resultado' => 'pendiente']);
        $data = $this->validar($request, ['vinculo_laboral_id' => $this->reglaVinculo($usuario), ...self::REGLAS_PERIODO]);
        $this->exigirPeriodoPrueba($usuario);

        return $this->creado(PeriodoPrueba::query()->create([...$data, 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]));
    }

    /** Registrar el resultado de un período pendiente. Corregir fechas = anular y registrar de nuevo. */
    public function updatePeriodo(Request $request, string $usuario, string $periodo): JsonResource|JsonResponse
    {
        $this->exigirLegajo($usuario);
        $registro = PeriodoPrueba::query()->where('usuario_id', $usuario)->findOrFail($periodo);
        if ($registro->anulado || $registro->resultado !== 'pendiente') {
            return response()->json(['message' => 'El período de prueba ya tiene resultado o está anulado: es un registro cerrado. Para corregirlo, anúlalo y regístralo de nuevo.'], 409);
        }
        $reglas = [
            'resultado' => 'required|in:aprobado,no_aprobado',
            'fecha_resultado' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio|before_or_equal:today',
        ];
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Solo se registra el resultado. Para corregir las fechas, anula el período y regístralo de nuevo.'));
        }
        $data = Validator::make(['fecha_inicio' => $registro->fecha_inicio->format('Y-m-d'), ...$request->all()], $reglas,
            ['fecha_resultado.after_or_equal' => 'La fecha del resultado no puede ser anterior al inicio del período.'])->validate();
        $registro->update($data);

        return new JsonResource($registro->refresh());
    }

    public function anularRegistro(Request $request, string $usuario, string $tipo, string $registro): JsonResource|JsonResponse
    {
        $modelo = self::ANULABLES[$tipo] ?? abort(404);

        return $this->anular($request, $modelo::query()->where('usuario_id', $usuario)->findOrFail($registro));
    }

    private function nivel(bool $completo, bool $alguno): string
    {
        return $completo ? 'completo' : ($alguno ? 'parcial' : 'ninguno');
    }

    /**
     * Vínculo con su vigencia, sus adendas en orden cronológico y su fin efectivo.
     * TODO pendiente de confirmar con el cliente: se toma como fin efectivo la fecha_fin de la última adenda
     * (la de inicio más reciente) y, si no hay adendas, la fecha_fin_prevista del vínculo.
     *
     * @param  Collection<int, Induccion>  $inducciones
     */
    private function vinculo(VinculoLaboral $vinculo, int $aviso, Collection $inducciones): array
    {
        $hoy = today();
        $adendas = $vinculo->vinculoAdendas;
        $fin = $adendas->last()?->fecha_fin ?? $vinculo->fecha_fin_prevista;
        $dias = $fin ? (int) $hoy->diffInDays($fin, false) : null;
        $estado = match (true) {
            $vinculo->fecha_cese !== null => 'cesado',
            $vinculo->fecha_inicio->gt($hoy) => 'por_iniciar',
            $fin === null => 'sin_fin',
            $dias < 0 => 'vencido',
            $dias <= $aviso => 'por_vencer',
            default => 'vigente',
        };
        $tiposInduccion = $inducciones->where('vinculo_laboral_id', $vinculo->id)->pluck('tipo')->all();

        return [
            ...$this->fila($vinculo, ['tipo_personal', 'regimen_laboral', 'regimen_normativo_id', 'unidad_organica_id', 'nombre_puesto', 'obligado_djbr', 'exige_colegiatura', 'tiene_periodo_prueba', 'proceso_seleccion', 'fecha_resultado_seleccion', 'numero_documento_vinculo', 'fecha_inicio', 'fecha_fin_prevista', 'fecha_cese']),
            'regimen_normativo' => $vinculo->regimenNormativo?->nombre,
            'unidad_organica' => $vinculo->unidadOrganica?->nombre,
            'cerrado' => $vinculo->fecha_cese !== null,
            'vigente' => in_array($estado, ['sin_fin', 'vigente', 'por_vencer'], true),
            'vencimiento' => ['estado' => $estado, 'fin_efectivo' => $fin?->format('Y-m-d'), 'por_adenda' => $adendas->isNotEmpty(), 'dias' => $dias],
            'adendas' => $this->filas($adendas, ['numero', 'motivo', 'fecha_inicio', 'fecha_fin', 'documento_id', 'created_at']),
            'inducciones_faltantes' => array_values(array_diff(['general', 'especifica'], $tiposInduccion)),
            // Datos del puesto según el tipo de personal del vínculo.
            'datos_puesto' => $vinculo->tipo_personal === 'docente'
                ? $vinculo->perfilDocente->map(fn (Model $perfil): array => $this->fila($perfil, ['condicion_docente', 'categoria', 'dedicacion', 'subtipo_extraordinario', 'funcion_previa', 'codigo_renacyt']))->values()
                : $vinculo->perfilAdministrativo->map(fn (Model $perfil): array => $this->fila($perfil, ['grupo_ocupacional', 'nivel_carrera', 'cargo_estructural', 'es_cargo_confianza']))->values(),
        ];
    }

    /** @param  Collection<int, DocumentoUsuario>  $documentos  del tipo, del más reciente al más antiguo */
    private function tipo(TipoDocumento $tipo, PerfilDocumental $perfil, Collection $documentos): array
    {
        $ultimo = $documentos->first();

        return [
            'id' => $tipo->id,
            'literal' => preg_match('/^S02_([A-Z])_/', $tipo->codigo, $match) ? strtolower($match[1]) : null,
            ...$tipo->only(['codigo', 'nombre', 'descripcion', 'formulario_datos', 'obligatorio', 'permite_multiples', 'requiere_num_resolucion', 'es_hook_universidad']),
            ...$perfil->exigencia($tipo),
            // Sin documentos registrados el tipo está pendiente; si hay varios, manda el más reciente.
            'estado' => $ultimo?->estado ?? 'pendiente',
            'cargado' => $documentos->contains(fn (DocumentoUsuario $documento): bool => in_array($documento->estado, ResumenSeccion02::ESTADOS_CARGADO, true)),
            'verificado' => $documentos->contains('estado', 'verificado'),
            'documentos' => $documentos->count(),
            // Metadatos obligatorios del último documento (sin datos de almacenamiento).
            'documento' => $ultimo ? $this->fila($ultimo, ['estado', 'fecha_incorporacion', 'folio_inicio', 'folio_fin', 'emisor', 'fecha_emision', 'titular_nombre', 'titular_documento', 'num_resolucion', 'fecha_recepcion'])['values'] : null,
        ];
    }

    private function periodo(PeriodoPrueba $periodo): array
    {
        $fila = $this->filas(new EloquentCollection([$periodo]), ['vinculo_laboral_id', 'fecha_inicio', 'fecha_fin', 'resultado', 'fecha_resultado', 'documento_id', 'created_at'])[0];
        $pendiente = $periodo->resultado === 'pendiente';
        $dias = $pendiente ? (int) today()->diffInDays($periodo->fecha_fin, false) : null;

        return [...$fila, 'dias_restantes' => $dias, 'alerta' => $pendiente && $dias < 0 ? 'pendiente_vencido' : null];
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

    private function exigirPeriodoPrueba(string $usuario): void
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $vinculos = VinculoLaboral::query()->where('usuario_id', $usuario)->get();
        if (! PerfilDocumental::de($persona, $vinculos)->tienePeriodoPrueba()) {
            throw ValidationException::withMessages(['vinculo_laboral_id' => 'La persona no tiene período de prueba (tiene_periodo_prueba = No).']);
        }
    }

    /** Solo un vínculo laboral abierto (sin fecha de cese) de esta misma persona: uno cerrado no recibe registros. */
    private function reglaVinculo(string $usuario): array
    {
        return ['required', 'uuid', Rule::exists('vinculos_laborales', 'id')->where(fn ($query) => $query->where('usuario_id', $usuario)->whereNull('fecha_cese'))];
    }

    /** Valida y rechaza campos que no son del formulario (autor, anulación, documento, etc.). */
    private function validar(Request $request, array $reglas): array
    {
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }

        return $request->validate($reglas, ['vinculo_laboral_id.exists' => 'Selecciona un vínculo laboral abierto (sin fecha de cese) de esta persona.']);
    }

    /** Corrección parcial: valida solo los campos enviados, comparando con los valores guardados. */
    private function validarCambios(Request $request, Model $registro, array $reglas): array
    {
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
        $actual = $this->fila($registro, array_keys($reglas))['values'];

        return Validator::make([...$actual, ...$request->all()], array_intersect_key($reglas, $request->all()))->validate();
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
            $values[$campo] = $valor instanceof \DateTimeInterface ? $valor->format($campo === 'created_at' ? 'Y-m-d H:i' : 'Y-m-d') : $valor;
        }

        return ['id' => $registro->getKey(), 'values' => $values];
    }

    /** @param Collection<int, Model> $registros */
    private function filas(Collection $registros, array $campos): array
    {
        return $registros->map(function (Model $registro) use ($campos): array {
            $fila = $this->fila($registro, $campos);
            $autor = $registro->registradoPor;
            $fila['values']['registrado_por'] = $autor ? trim($autor->nombres.' '.$autor->apellido_paterno.' '.$autor->apellido_materno) : null;

            return $fila;
        })->values()->all();
    }
}
