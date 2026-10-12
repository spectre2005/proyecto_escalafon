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
use App\Support\AplicacionDocumental;
use App\Support\Documentos\EstadoDocumento;
use App\Support\Documentos\FormulariosDatos;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sección 02 (Incorporación) desde la ficha: lectura de las 4 subsecciones con sus datos y el estado
 * de sus tipos de documento, y registro de vínculos, adendas, inducciones y períodos de prueba.
 *
 * Vínculos, adendas, inducciones y períodos de prueba no se eliminan: se anulan con motivo y salen de los
 * listados y contadores. Un vínculo (y sus datos del puesto) se corrige solo mientras no tenga fecha de
 * cese, y un período de prueba solo mientras su resultado sea pendiente. Las adendas no se corrigen.
 */
class Seccion02Controller extends Controller
{
    use AnulaConMotivo;

    private const ANULABLES = ['vinculos-laborales' => VinculoLaboral::class, 'vinculo-adendas' => VinculoAdenda::class,
        'inducciones' => Induccion::class, 'periodos-prueba' => PeriodoPrueba::class];

    private const REGLAS_VINCULO = [
        'tipo_personal' => 'required|in:docente,administrativo',
        'regimen_laboral' => 'required|in:carrera,plazo_determinado,designado',
        'regimen_normativo_id' => 'required|uuid|exists:regimenes_normativos,id',
        'unidad_organica_id' => 'nullable|uuid|exists:unidades_organicas,id',
        'nombre_puesto' => 'required|string|max:150',
        'obligado_djbr' => 'sometimes|nullable|boolean',
        'exige_colegiatura' => 'sometimes|nullable|boolean',
        'exige_estudios_basicos' => 'sometimes|nullable|boolean',
        'tiene_periodo_prueba' => 'sometimes|nullable|boolean',
        'proceso_seleccion' => 'nullable|string|max:100',
        'fecha_resultado_seleccion' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        'numero_documento_vinculo' => 'nullable|string|max:60',
        'fecha_inicio' => 'required|date_format:Y-m-d',
        'fecha_fin_prevista' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio',
        'fecha_cese' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio|before_or_equal:today',
    ];

    // Datos del puesto según el tipo de personal del vínculo: solo los campos del esquema (perfil_docente y
    // perfil_administrativo). La categoría es solo de ordinarios (comentario del esquema) y el subtipo, de extraordinarios.
    private const REGLAS_PERFIL = [
        'docente' => [
            'condicion_docente' => 'required|in:ordinario,extraordinario,contratado',
            'categoria' => 'nullable|in:principal,asociado,auxiliar|prohibited_unless:condicion_docente,ordinario',
            'dedicacion' => 'nullable|in:exclusiva,tiempo_completo,tiempo_parcial',
            'subtipo_extraordinario' => 'nullable|in:emerito,honorario,investigador,visitante,experto|prohibited_unless:condicion_docente,extraordinario',
            'funcion_previa' => 'nullable|in:jefe_practica,ayudante_catedra,ayudante_laboratorio',
            'codigo_renacyt' => 'nullable|string|max:30',
        ],
        'administrativo' => [
            'grupo_ocupacional' => 'nullable|in:profesional,tecnico,auxiliar',
            'nivel_carrera' => 'nullable|string|max:20',
            'cargo_estructural' => 'nullable|string|max:150',
            'es_cargo_confianza' => 'sometimes|boolean',
        ],
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
        $legajo = Legajo::paraConsulta($usuario);
        $vinculos = VinculoLaboral::query()->where('usuario_id', $usuario)->where('anulado', false)
            ->with(['regimenNormativo:id,nombre', 'unidadOrganica:id,nombre', 'perfilDocente', 'perfilAdministrativo', 'registradoPor',
                'vinculoAdendas' => fn ($query) => $query->where('anulado', false)->with('registradoPor')->orderBy('fecha_inicio')->orderBy('created_at')->orderBy('id')])
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
            ->with(['archivo', 'recepcionadoPor', 'createdBy'])->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('tipo_documento_id');
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
                // 2.4 existe siempre; no aplica solo con «Tiene período de prueba» en No (AplicacionDocumental: se
                // ofrece si algún vínculo no anulado dice Sí). Sin responder aplica y sus tipos quedan por determinar.
                $aplica = $subseccion->codigo !== '2.4' || $perfil->aplicacion->bandera('tiene_periodo_prueba', paraOfrecer: true) !== false;
                $tipos = $subseccion->tiposDocumento->map(fn (TipoDocumento $tipo): array => $this->tipo($tipo, $perfil, $documentos->get($tipo->id, new EloquentCollection)))->values()->all();

                return ['codigo' => $subseccion->codigo, 'nombre' => $subseccion->nombre, 'aplica' => $aplica, 'datos' => $datos[$subseccion->codigo] ?? 'ninguno',
                    ...ResumenSeccion02::subseccion($aplica, $tipos, $datos[$subseccion->codigo] ?? 'ninguno'), 'tipos' => $tipos];
            })->values();

        return response()->json(['data' => [
            'legajo' => $legajo?->only(['id', 'numero_legajo', 'estado_legajo']),
            // Titular por defecto de los documentos que se suban (se puede cambiar en el formulario).
            'titular' => ['nombre' => trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 'documento' => $persona->numero_doc_identidad],
            'hoy' => today()->format('Y-m-d'),
            'dias_aviso_vencimiento' => $aviso,
            // Sí, No o Sin responder (null), como la 2.4: se ofrece si algún vínculo no anulado dice Sí.
            'tiene_periodo_prueba' => $perfil->aplicacion->bandera('tiene_periodo_prueba', paraOfrecer: true),
            // Con la bandera en No y datos registrados, la 2.4 se muestra con aviso (no se exige ni se cuenta).
            'prueba_con_datos' => $perfil->aplicacion->hayDatosPeriodoPrueba(),
            'perfil' => ['regimenes' => $perfil->regimenes, 'regimenes_clasificados' => $perfil->regimenesClasificados(), 'tipos_personal' => $perfil->tiposPersonal, 'banderas' => $perfil->banderas],
            'avisos_perfil' => $perfil->aplicacion->avisos(),
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

        // Las adendas no tienen usuario_id ni updated_at: se buscan por el vínculo de la persona.
        $adendas = VinculoAdenda::query()->where('anulado', true)->whereHas('vinculoLaboral', fn ($query) => $query->where('usuario_id', $usuario))
            ->with('registradoPor')->orderByDesc('created_at')->orderBy('id')->get();

        return response()->json(['data' => [
            'vinculos_laborales' => $anulados(VinculoLaboral::class, ['tipo_personal', 'regimen_laboral', 'nombre_puesto', 'numero_documento_vinculo', 'fecha_inicio', 'fecha_fin_prevista', 'fecha_cese']),
            'vinculo_adendas' => $this->filas($adendas, ['vinculo_laboral_id', 'numero', 'motivo', 'fecha_inicio', 'fecha_fin', 'motivo_anulacion', 'created_at']),
            'inducciones' => $anulados(Induccion::class, ['vinculo_laboral_id', 'tipo', 'fecha', 'responsable']),
            'periodos_prueba' => $anulados(PeriodoPrueba::class, ['vinculo_laboral_id', 'fecha_inicio', 'fecha_fin', 'resultado', 'fecha_resultado']),
        ]]);
    }

    public function storeVinculo(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $data = $this->validar($request, [...self::REGLAS_VINCULO, 'documento_id' => 'nullable|uuid']);
        $documento = $this->documento($usuario, $data, 'vinculos_laborales');

        // vinculos_laborales no tiene documento_id: el documento (c a f) apunta al vínculo.
        return $this->creado(DB::transaction(function () use ($data, $usuario, $documento, $request): VinculoLaboral {
            $vinculo = VinculoLaboral::query()->create([...collect($data)->except('documento_id')->all(), 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);
            // Enlace de una sola vez: si otra carga ya lo enlazó entre tanto, no se pisa.
            if ($documento && DocumentoUsuario::query()->whereKey($documento->id)->whereNull('vinculo_laboral_id')->update(['vinculo_laboral_id' => $vinculo->id]) !== 1) {
                throw ValidationException::withMessages(['documento_id' => 'El documento ya está enlazado a otro vínculo.']);
            }

            return $vinculo;
        }));
    }

    public function updateVinculo(Request $request, string $usuario, string $vinculo): JsonResource|JsonResponse
    {
        $this->exigirLegajo($usuario);
        $registro = VinculoLaboral::query()->where('usuario_id', $usuario)->findOrFail($vinculo);
        if ($registro->anulado || $registro->fecha_cese !== null) {
            return response()->json(['message' => $registro->anulado ? 'El vínculo está anulado: no se corrige.' : 'El vínculo tiene fecha de cese: es un registro cerrado y no se corrige.'], 409);
        }
        $registro->update($this->validarCambios($request, $registro, self::REGLAS_VINCULO));

        return new JsonResource($registro->refresh());
    }

    /**
     * Registrar o corregir los datos del puesto (perfil_docente o perfil_administrativo) de un vínculo. Igual que el
     * vínculo: solo mientras no tenga fecha de cese ni esté anulado. Las tablas de perfil no tienen anulación.
     * TODO pendiente de confirmar con el cliente: si el perfil debe conservar historial (hoy se corrige en el mismo registro).
     */
    public function guardarDatosPuesto(Request $request, string $usuario, string $vinculo): JsonResource|JsonResponse
    {
        $this->exigirLegajo($usuario);
        $registro = VinculoLaboral::query()->where('usuario_id', $usuario)->findOrFail($vinculo);
        if ($registro->anulado || $registro->fecha_cese !== null) {
            return response()->json(['message' => 'El vínculo está cerrado (con fecha de cese) o anulado: sus datos del puesto no se corrigen.'], 409);
        }
        $docente = $registro->tipo_personal === 'docente';
        $data = $this->validar($request, self::REGLAS_PERFIL[$docente ? 'docente' : 'administrativo']);
        $perfiles = $docente ? $registro->perfilDocente() : $registro->perfilAdministrativo();
        $perfil = $perfiles->orderBy('created_at')->orderBy('id')->first();
        if ($perfil) {
            $perfil->update($data);

            return new JsonResource($perfil->refresh());
        }

        return $this->creado($perfiles->create($data));
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
            'documento_id' => 'nullable|uuid',
        ]);
        $this->documento($usuario, $data, 'vinculo_adendas');

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
            'documento_id' => 'nullable|uuid',
        ]);
        $this->documento($usuario, $data, 'inducciones');

        return $this->creado(Induccion::query()->create([...$data, 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]));
    }

    public function storePeriodo(Request $request, string $usuario): JsonResponse
    {
        $this->exigirLegajo($usuario);
        $request->mergeIfMissing(['resultado' => 'pendiente']);
        $data = $this->validar($request, ['vinculo_laboral_id' => $this->reglaVinculo($usuario), ...self::REGLAS_PERIODO, 'documento_id' => 'nullable|uuid']);
        $this->exigirPeriodoPrueba($usuario);
        $this->documento($usuario, $data, 'periodos_prueba');

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
        $query = $modelo === VinculoAdenda::class
            ? VinculoAdenda::query()->whereHas('vinculoLaboral', fn ($q) => $q->where('usuario_id', $usuario))
            : $modelo::query()->where('usuario_id', $usuario);

        // TODO pendiente de confirmar con el cliente: anular un vínculo no anula sus adendas, inducciones ni
        // períodos de prueba (quedan registrados; el vínculo ya no recibe registros nuevos).
        return $this->anular($request, $query->findOrFail($registro));
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
            ...$this->fila($vinculo, ['tipo_personal', 'regimen_laboral', 'regimen_normativo_id', 'unidad_organica_id', 'nombre_puesto', 'obligado_djbr', 'exige_colegiatura', 'exige_estudios_basicos', 'tiene_periodo_prueba', 'proceso_seleccion', 'fecha_resultado_seleccion', 'numero_documento_vinculo', 'fecha_inicio', 'fecha_fin_prevista', 'fecha_cese']),
            'regimen_normativo' => $vinculo->regimenNormativo?->nombre,
            'registrado_por' => $this->nombre($vinculo->registradoPor),
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
            // Estado visible: pendiente, cargado o recepcionado (los de fiscalización quedan preparados).
            'estado' => $ultimo ? EstadoDocumento::visible($ultimo) : 'pendiente',
            'cargado' => $documentos->contains(fn (DocumentoUsuario $documento): bool => in_array($documento->estado, ResumenSeccion02::ESTADOS_CARGADO, true)),
            'recepcionado' => $documentos->contains(fn (DocumentoUsuario $documento): bool => in_array($documento->estado, ResumenSeccion02::ESTADOS_CARGADO, true) && EstadoDocumento::recepcionado($documento)),
            'verificado' => $documentos->contains('estado', 'verificado'),
            'documentos' => $documentos->count(),
            // Metadatos obligatorios del último documento (sin datos de almacenamiento).
            'documento' => $ultimo ? $this->fila($ultimo, ['estado', 'fecha_incorporacion', 'folio_inicio', 'folio_fin', 'emisor', 'fecha_emision', 'titular_nombre', 'titular_documento', 'num_resolucion', 'fecha_recepcion'])['values'] : null,
            // Todos los documentos del tipo; "subsanado" = otro documento lo corrige (no se elimina ni se reemplaza).
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

    /** Documento recién subido al que se enlaza el registro (el formulario se abre al subirlo). */
    private function documento(string $usuario, array $data, string $formulario): ?DocumentoUsuario
    {
        return empty($data['documento_id']) ? null : FormulariosDatos::documentoPara($usuario, $data['documento_id'], $formulario);
    }

    private function exigirPeriodoPrueba(string $usuario): void
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $vinculos = VinculoLaboral::query()->where('usuario_id', $usuario)->where('anulado', false)->get();
        // Solo se rechaza con la bandera en No; sin responder se permite (AplicacionDocumental).
        if (AplicacionDocumental::de($persona, $vinculos)->bandera('tiene_periodo_prueba', paraOfrecer: true) === false) {
            throw ValidationException::withMessages(['vinculo_laboral_id' => 'No se puede registrar: «Tiene período de prueba» está en No en el vínculo laboral. '
                .'Si corresponde, cámbialo a Sí en el vínculo (sección 02) y vuelve a intentarlo.']);
        }
    }

    /** Solo un vínculo laboral abierto (sin fecha de cese) de esta misma persona: uno cerrado no recibe registros. */
    private function reglaVinculo(string $usuario): array
    {
        return ['required', 'uuid', Rule::exists('vinculos_laborales', 'id')->where(fn ($query) => $query->where('usuario_id', $usuario)->whereNull('fecha_cese')->where('anulado', false))];
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
