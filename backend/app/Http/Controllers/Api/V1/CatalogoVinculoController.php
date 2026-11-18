<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EscalaNivelCarrera;
use App\Models\ModalidadVinculo;
use App\Models\Puesto;
use App\Models\RegimenNormativo;
use App\Models\TipoProcesoSeleccion;
use App\Models\TipoVinculo;
use App\Models\UnidadOrganica;
use App\Models\VinculoLaboral;
use App\Rules\NivelCarrera;
use App\Support\Auditoria;
use App\Support\CatalogosVinculo;
use App\Support\RegimenesLaborales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catálogos del vínculo (App\Support\CatalogosVinculo): unidades orgánicas, puestos, modalidades, procesos de selección y
 * la descripción de los tres tipos de vínculo. Se crean desde el registro de personal y desde Configuración, con código
 * correlativo y auditoría. No se eliminan: se desactivan, y lo ya registrado en los vínculos conserva su valor. Crear y
 * editar exige catalogos.gestionar (en la ruta).
 */
class CatalogoVinculoController extends Controller
{
    /** Clave de la URL => [modelo, entidad de auditoría]. */
    private const CATALOGOS = [
        'unidades' => [UnidadOrganica::class, 'unidades_organicas'],
        'puestos' => [Puesto::class, 'puestos'],
        'modalidades' => [ModalidadVinculo::class, 'modalidades_vinculo'],
        'procesos' => [TipoProcesoSeleccion::class, 'tipos_proceso_seleccion'],
        'tipos_vinculo' => [TipoVinculo::class, 'tipos_vinculo'],
        'niveles' => [EscalaNivelCarrera::class, 'escalas_nivel_carrera'],
    ];

    /** Claves válidas en la URL (routes/api.php). */
    public const CLAVES = ['unidades', 'puestos', 'modalidades', 'procesos', 'tipos_vinculo', 'niveles'];

    /** Listado para Configuración: los catálogos con cuántas personas tienen un vínculo que usa cada elemento. */
    public function index(): JsonResponse
    {
        $uso = fn (string $campo) => VinculoLaboral::query()->where('anulado', false)->whereNotNull($campo)
            ->selectRaw("$campo as clave, count(distinct usuario_id) as total")->groupBy($campo)->pluck('total', 'clave');
        $conUso = fn (iterable $filas, $usos, string $clave = 'id') => collect($filas)
            ->map(fn (Model $fila): array => [...$fila->toArray(), 'personas' => (int) ($usos[$fila->{$clave}] ?? 0)])->values();
        $opciones = CatalogosVinculo::opciones();

        return response()->json(['data' => [
            ...$opciones,
            'unidades' => $conUso($opciones['unidades'], $uso('unidad_organica_id')),
            'puestos' => $conUso($opciones['puestos'], $uso('puesto_id')),
            'modalidades' => $conUso($opciones['modalidades'], $uso('modalidad_vinculo_id')),
            'procesos' => $conUso($opciones['procesos'], $uso('tipo_proceso_seleccion_id')),
            'tipos_vinculo' => $conUso($opciones['tipos_vinculo'], $uso('regimen_laboral'), 'codigo'),
            'niveles' => $this->nivelesConUso($opciones['niveles']),
            'regimenes' => RegimenNormativo::query()->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'aplica_a', 'activo']),
        ]]);
    }

    public function store(Request $request, string $catalogo): JsonResponse
    {
        [$modelo, $entidad] = self::CATALOGOS[$catalogo] ?? abort(404);
        abort_if($catalogo === 'tipos_vinculo', 422, 'Los tres tipos de vínculo vienen de la directiva y de ellos depende qué documentos se exigen. Para otro tipo, crea una modalidad dentro de uno de ellos.');
        $data = $request->validate($this->reglas($catalogo), [], $this->atributos());
        if ($catalogo === 'niveles') {
            $data = $this->nivelNuevo($data);
        }
        $registro = DB::transaction(function () use ($request, $catalogo, $modelo, $entidad, $data): Model {
            $this->exigirUnico($catalogo, $data);
            // Los niveles conservan la sigla que escribió la ORH (la de su PAP); los demás reciben un código correlativo.
            $codigo = $catalogo === 'niveles' ? $data['codigo'] : CatalogosVinculo::siguienteCodigo($modelo);
            $registro = $modelo::query()->create([...$data, 'codigo' => $codigo, 'activo' => true]);
            Auditoria::registrar($request, 'catalogo.crear', $entidad, $registro->id, null, null, $registro->getAttributes());

            return $registro;
        });

        return response()->json(['data' => $registro->refresh()], 201);
    }

    public function update(Request $request, string $catalogo, string $registro): JsonResponse
    {
        [$modelo, $entidad] = self::CATALOGOS[$catalogo] ?? abort(404);
        $actual = $modelo::query()->findOrFail($registro);
        // De un nivel cambian su nombre y su orden: la sigla, la ley y el grupo quedan guardados en los legajos que lo usan.
        $reglas = $catalogo === 'niveles' ? ['orden' => ['sometimes', 'integer', 'min:1', 'max:99'], 'nombre' => ['sometimes', 'nullable', 'string', 'max:80']]
            : array_map(fn (array $regla): array => ['sometimes', ...$regla], $this->reglas($catalogo));
        // Los tipos de vínculo no se desactivan: siempre existen los tres.
        $data = $request->validate([...$reglas, ...($catalogo === 'tipos_vinculo' ? [] : ['activo' => 'sometimes|boolean'])], [], $this->atributos());
        abort_if($data === [], 422, 'No hay cambios que guardar.');
        DB::transaction(function () use ($request, $catalogo, $actual, $entidad, $data): void {
            if ($catalogo !== 'niveles') {
                $this->exigirUnico($catalogo, [...$actual->only(array_keys($this->reglas($catalogo))), ...$data], $actual->id);
            }
            if ($catalogo === 'unidades' && array_key_exists('unidad_padre_id', $data)) {
                $this->exigirSinCiclo($actual->id, $data['unidad_padre_id']);
            }
            $antes = $actual->getAttributes();
            $actual->update($data);
            Auditoria::registrar($request, ($data['activo'] ?? null) === false ? 'catalogo.desactivar' : 'catalogo.corregir', $entidad, $actual->id, null, $antes, $actual->getAttributes());
        });

        return response()->json(['data' => $actual->refresh()]);
    }

    /** @return array<string, list<mixed>> */
    private function reglas(string $catalogo): array
    {
        $bandera = ['nullable', 'boolean'];

        return match ($catalogo) {
            'unidades' => [
                'nombre' => ['required', 'string', 'max:150'],
                'tipo' => ['required', 'string', 'max:30'],
                'unidad_padre_id' => ['nullable', 'uuid', 'exists:unidades_organicas,id'],
            ],
            'puestos' => [
                'nombre' => ['required', 'string', 'max:150'],
                'tipo_personal' => ['required', Rule::in(['docente', 'administrativo'])],
                'unidad_organica_id' => ['nullable', 'uuid', 'exists:unidades_organicas,id'],
                'grupo_ocupacional' => ['nullable', Rule::in(['profesional', 'tecnico', 'auxiliar'])],
                'es_cargo_confianza' => ['boolean'],
                'obligado_djbr' => $bandera, 'exige_colegiatura' => $bandera, 'tiene_periodo_prueba' => $bandera,
            ],
            'modalidades' => [
                'nombre' => ['required', 'string', 'max:120'],
                'regimen_laboral' => ['required', Rule::in(array_keys(RegimenesLaborales::TIPOS_VINCULO))],
                'tipo_personal' => ['nullable', Rule::in(['docente', 'administrativo'])],
                'regimen_normativo_id' => ['nullable', 'uuid', 'exists:regimenes_normativos,id'],
                'tiene_periodo_prueba' => $bandera,
                'exige_fin_previsto' => ['boolean'],
            ],
            'procesos' => ['nombre' => ['required', 'string', 'max:120']],
            // Solo la descripción: el código y el nombre son los de la directiva.
            'tipos_vinculo' => ['descripcion' => ['nullable', 'string', 'max:300']],
            'niveles' => [
                'regimen_normativo_id' => ['required', 'uuid', 'exists:regimenes_normativos,id'],
                'grupo_ocupacional' => ['required', Rule::in(['profesional', 'tecnico', 'auxiliar'])],
                // La sigla de la resolución y del PAP: letras, números, guion o punto (SPA, T-3…).
                'codigo' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9.\- ]*$/'],
                // Cómo se lee la sigla («Servidor Técnico F»): se muestra en la lista junto a ella.
                'nombre' => ['nullable', 'string', 'max:80'],
                'orden' => ['nullable', 'integer', 'min:1', 'max:99'],
            ],
        };
    }

    /** @return array<string, string> */
    private function atributos(): array
    {
        return ['nombre' => 'nombre', 'tipo' => 'tipo', 'descripcion' => 'descripción', 'unidad_padre_id' => 'unidad de la que depende',
            'tipo_personal' => 'tipo de personal', 'unidad_organica_id' => 'unidad orgánica', 'regimen_laboral' => 'tipo de vínculo', 'regimen_normativo_id' => 'ley',
            'grupo_ocupacional' => 'grupo ocupacional', 'codigo' => 'sigla', 'orden' => 'orden', 'nombre' => 'nombre'];
    }

    /** Evita duplicados con otro nombre de mayúsculas: el mismo elemento debe reutilizarse, no crearse dos veces. */
    private function exigirUnico(string $catalogo, array $data, ?string $excepto = null): void
    {
        if ($catalogo === 'tipos_vinculo') {
            return;
        }
        if ($catalogo === 'niveles') {
            $existe = EscalaNivelCarrera::query()->where('regimen_normativo_id', $data['regimen_normativo_id'])->where('grupo_ocupacional', $data['grupo_ocupacional'])
                ->whereRaw('UPPER(codigo) = ?', [$data['codigo']])->when($excepto, fn (Builder $q) => $q->whereKeyNot($excepto))->exists();
            if ($existe) {
                throw ValidationException::withMessages(['codigo' => 'Ese nivel ya existe en la escala de esta ley y grupo.']);
            }

            return;
        }
        [$modelo] = self::CATALOGOS[$catalogo];
        $texto = mb_strtolower(trim((string) ($data['nombre'] ?? '')));
        $igual = fn (Builder $query, string $columna) => array_key_exists($columna, $data) && $data[$columna] !== null
            ? $query->where($columna, $data[$columna]) : $query->whereNull($columna);
        $query = $modelo::query()->whereRaw('LOWER(TRIM(nombre)) = ?', [$texto])->when($excepto, fn (Builder $q) => $q->whereKeyNot($excepto));
        [$mensaje, $existe] = match ($catalogo) {
            'unidades' => ['Ya existe una unidad con ese nombre que depende de la misma unidad.', $igual($query, 'unidad_padre_id')->exists()],
            'puestos' => ['Ya existe ese puesto en la misma unidad orgánica. Elígelo de la lista.', $igual($query->where('tipo_personal', $data['tipo_personal'] ?? null), 'unidad_organica_id')->exists()],
            'modalidades' => ['Ya existe una modalidad con ese nombre en el mismo tipo de vínculo.', $query->where('regimen_laboral', $data['regimen_laboral'] ?? null)->exists()],
            'procesos' => ['Ya existe un proceso de selección con ese nombre.', $query->exists()],
        };
        if ($existe) {
            throw ValidationException::withMessages(['nombre' => $mensaje]);
        }
    }

    /**
     * Nivel nuevo: sigla en mayúsculas y, sin orden, al final de su escala (el último es el máximo). CAS no tiene nivel de
     * carrera y la escala es del personal administrativo: una ley solo de docentes no la lleva.
     */
    private function nivelNuevo(array $data): array
    {
        $ley = RegimenNormativo::query()->findOrFail($data['regimen_normativo_id']);
        if (isset(NivelCarrera::SIN_NIVEL[$ley->codigo])) {
            throw ValidationException::withMessages(['regimen_normativo_id' => NivelCarrera::SIN_NIVEL[$ley->codigo]]);
        }
        if ($ley->aplica_a === 'docente') {
            throw ValidationException::withMessages(['regimen_normativo_id' => 'El nivel de carrera es del personal administrativo: elige una ley que le aplique.']);
        }
        $data['codigo'] = mb_strtoupper(trim($data['codigo']));
        $data['orden'] ??= (int) EscalaNivelCarrera::query()->where('regimen_normativo_id', $ley->id)->where('grupo_ocupacional', $data['grupo_ocupacional'])->max('orden') + 1;

        return $data;
    }

    /** Niveles con cuántas personas tienen un vínculo vigente (no anulado) de esa ley con ese nivel. */
    private function nivelesConUso(iterable $niveles): Collection
    {
        $uso = DB::table('perfil_administrativo')->join('vinculos_laborales', 'vinculos_laborales.id', '=', 'perfil_administrativo.vinculo_laboral_id')
            ->where('vinculos_laborales.anulado', false)->whereNotNull('perfil_administrativo.nivel_carrera')
            ->selectRaw('vinculos_laborales.regimen_normativo_id as ley, UPPER(perfil_administrativo.nivel_carrera) as nivel, count(distinct vinculos_laborales.usuario_id) as total')
            ->groupBy('vinculos_laborales.regimen_normativo_id', DB::raw('UPPER(perfil_administrativo.nivel_carrera)'))->get()
            ->mapWithKeys(fn (object $fila): array => [$fila->ley.'|'.$fila->nivel => (int) $fila->total]);

        return collect($niveles)->map(fn (array $nivel): array => [...$nivel, 'personas' => $uso[$nivel['regimen_normativo_id'].'|'.$nivel['codigo']] ?? 0])->values();
    }

    /** Una unidad no puede depender de sí misma ni de una unidad que depende de ella. */
    private function exigirSinCiclo(string $unidad, ?string $padre): void
    {
        $vistos = [];
        while ($padre !== null && ! in_array($padre, $vistos, true)) {
            if ($padre === $unidad) {
                throw ValidationException::withMessages(['unidad_padre_id' => 'La unidad no puede depender de sí misma ni de una unidad que depende de ella.']);
            }
            $vistos[] = $padre;
            $padre = UnidadOrganica::query()->whereKey($padre)->value('unidad_padre_id');
        }
    }
}
