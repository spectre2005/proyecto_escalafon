<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUsuarioConVinculoRequest;
use App\Http\Requests\StoreUsuariosRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\DocumentoUsuario;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\AperturaLegajo;
use App\Support\Auditoria;
use App\Support\CambioDiscapacidad;
use App\Support\ConfiguracionFuncionalidades;
use App\Support\Documentos\AuditoriaDocumento;
use App\Support\Documentos\CargaDocumento;
use App\Support\RegimenesLaborales;
use App\Support\SituacionLaboral;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Throwable;

class UsuarioController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UsuarioResource::collection(SituacionLaboral::cargar(Usuario::query()->with('regimenNormativo'))->orderBy('id')->paginate(100));
    }

    public function show(string $record): UsuarioResource
    {
        return new UsuarioResource(SituacionLaboral::cargar(Usuario::query()->with('regimenNormativo'))->findOrFail($record));
    }

    public function update(Request $request, string $record): UsuarioResource
    {
        $usuario = Usuario::query()->findOrFail($record);
        $rules = [];
        foreach ((new StoreUsuariosRequest)->rules() as $field => $fieldRules) {
            if (! str_starts_with($field, 'usuarios.*.')) {
                continue;
            }
            $field = substr($field, strlen('usuarios.*.'));
            // Fecha de nacimiento y domicilio se editan solo desde la ficha (ExpedienteController, con datos_sensibles.ver).
            if (array_key_exists($field, StoreUsuariosRequest::DATOS_SENSIBLES)) {
                continue;
            }
            $rules[$field] = ['sometimes', ...array_filter($fieldRules, fn (mixed $rule): bool => ! $rule instanceof Unique
                && ! (is_string($rule) && str_starts_with($rule, 'distinct:'))
            )];
        }
        $rules['numero_doc_identidad'][] = Rule::unique('usuarios', 'numero_doc_identidad')->ignore($usuario);
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Campo no permitido.'));
        }
        $data = $request->validate($rules);
        // Ley: por id o por código; debe corresponder al tipo de personal (el nuevo, si también cambia).
        if (array_key_exists('regimen_normativo_id', $data) || array_key_exists('regimen_laboral', $data)) {
            $ley = RegimenesLaborales::ley($data['regimen_normativo_id'] ?? null, $data['regimen_laboral'] ?? null);
            if ($error = RegimenesLaborales::error($ley, $data['tipo_personal'] ?? $usuario->tipo_personal)) {
                throw ValidationException::withMessages(['regimen_normativo_id' => $error]);
            }
            $data = [...$data, 'regimen_normativo_id' => $ley->id, 'regimen_laboral' => RegimenesLaborales::codigoRespaldo($ley)];
        }
        $discapacidad = array_key_exists(CambioDiscapacidad::CAMPO, $data) ? [$usuario->con_discapacidad, CambioDiscapacidad::valor($data[CambioDiscapacidad::CAMPO])] : null;
        if ($discapacidad) {
            CambioDiscapacidad::autorizar($request->user(), ...$discapacidad);
        }
        if (isset($data['email_institucional'])) {
            $data['email_institucional'] = strtolower($data['email_institucional']);
            if (Usuario::query()->whereKeyNot($usuario->id)->whereRaw('LOWER(email_institucional) = ?', [$data['email_institucional']])->exists()) {
                throw ValidationException::withMessages(['email_institucional' => 'Este correo ya está registrado.']);
            }
        }
        try {
            DB::transaction(function () use ($usuario, $data, $discapacidad, $request): void {
                $antes = $usuario->getAttributes();
                $usuario->update($data);
                // La discapacidad tiene su propio evento (CambioDiscapacidad); aquí, el resto de lo que cambió.
                [$previo, $nuevo] = Auditoria::diferencia(Arr::except($antes, [CambioDiscapacidad::CAMPO, 'updated_at']), Arr::except($usuario->getAttributes(), [CambioDiscapacidad::CAMPO, 'updated_at']));
                if ($nuevo !== []) {
                    Auditoria::registrar($request, 'persona.editar', 'usuarios', $usuario->id, $usuario->id, $previo, $nuevo);
                }
                if ($discapacidad) {
                    CambioDiscapacidad::auditar($usuario->id, $discapacidad[0], $discapacidad[1], $request->user(), $request);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['usuario' => 'Un DNI o correo ya está registrado.']);
        }

        return new UsuarioResource(SituacionLaboral::cargar(Usuario::query()->with('regimenNormativo'))->findOrFail($usuario->getKey()));
    }

    /**
     * Ley de la fila (ya validada por StoreUsuariosRequest): su id y su código como respaldo de lectura.
     *
     * @return array{regimen_normativo_id: string, regimen_laboral: string}
     */
    private function ley(array $row): array
    {
        $ley = RegimenesLaborales::ley($row['regimen_normativo_id'] ?? null, $row['regimen_laboral'] ?? null);

        return ['regimen_normativo_id' => $ley->id, 'regimen_laboral' => RegimenesLaborales::codigoRespaldo($ley)];
    }

    public function destroy(Request $request, string $record): JsonResponse
    {
        $usuario = Usuario::query()->findOrFail($record);
        try {
            // Solo se borra quien no tiene registros relacionados ni historia en la auditoría (sus eventos la referencian):
            // quien ya tiene altas o cambios auditados se conserva. El borrado queda en la auditoría con los datos que
            // tenía; sin usuario_titular_id, que ya no existiría (entidad_id guarda su id).
            DB::transaction(function () use ($usuario, $request): void {
                Auditoria::registrar($request, 'persona.eliminar', 'usuarios', $usuario->id, null, $usuario->getAttributes());
                $usuario->delete();
            });
        } catch (QueryException $exception) {
            if (! str_starts_with((string) $exception->getCode(), '23')) {
                throw $exception;
            }

            return response()->json(['message' => 'El usuario tiene registros relacionados.'], 409);
        }

        return response()->json(null, 204);
    }

    public function store(StoreUsuariosRequest $request): JsonResponse
    {
        $records = $this->registros($request->validated('usuarios'), $request);
        $this->insertar($records, $request);

        return UsuarioResource::collection(
            SituacionLaboral::cargar(Usuario::query()->with('regimenNormativo'))->whereIn('id', array_column($records, 'id'))->orderBy('id')->get(),
        )->response()->setStatusCode(201);
    }

    /**
     * Registro manual: la persona con su primer vínculo laboral (obligatorio), en una sola transacción: persona, apertura
     * del legajo, vínculo y datos del puesto. Si falla un paso no queda nada. Exige los mismos permisos que esos pasos por
     * separado (usuarios.gestionar en la ruta, legajo.abrir y legajo.editar aquí) y que 2.2 esté activa. La importación
     * por lotes (store) no cambia: da de alta personas sin vínculo.
     */
    public function storeConVinculo(StoreUsuarioConVinculoRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('legajo.abrir') && $actor->can('legajo.editar'), 403,
            'Registrar a la persona con su vínculo requiere abrir legajos (legajo.abrir) y registrar vínculos (legajo.editar).');
        abort_unless(ConfiguracionFuncionalidades::activa('s02.vinculos'), 409,
            '«'.ConfiguracionFuncionalidades::definicion('s02.vinculos')['nombre'].'» está desactivada en la configuración de la institución.');
        $ingreso = $request->validated('documento_ingreso');
        $tipoIngreso = $ingreso ? TipoDocumento::query()->findOrFail($ingreso['tipo_documento_id']) : null;
        if ($tipoIngreso) {
            abort_unless($actor->can('documento.subir'), 403, 'No tienes permiso para adjuntar documentos al legajo.');
            abort_if(ConfiguracionFuncionalidades::inactivaPorTipo($tipoIngreso->id), 409, 'Este tipo de documento está desactivado.');
            if ($tipoIngreso->requiere_num_resolucion && ! filled($request->vinculo()['numero_documento_vinculo'] ?? null)) {
                throw ValidationException::withMessages(['vinculo.numero_documento_vinculo' => 'Indica el número real de la resolución adjunta.']);
            }
        }
        [$registro] = $this->registros($request->validated('usuarios'), $request);

        $archivoGuardado = null;
        $documentoIngreso = null;
        try {
            [$legajo, $vinculo] = DB::transaction(function () use ($registro, $request, $actor, $tipoIngreso, $ingreso, &$archivoGuardado, &$documentoIngreso): array {
                $this->insertar([$registro], $request);
                $legajo = AperturaLegajo::abrir($registro['id']);
                // El tipo de personal y la ley del vínculo son los de la persona; el tipo de vínculo, el que se indicó.
                $vinculo = VinculoLaboral::query()->create([...$request->vinculo(), 'usuario_id' => $registro['id'], 'tipo_personal' => $registro['tipo_personal'],
                    'regimen_normativo_id' => $registro['regimen_normativo_id'], 'registrado_por' => $actor->getAuthIdentifier()]);
                // Sin datos del puesto (todos opcionales en el administrativo) no se crea un perfil vacío: se registra después en 2.2.
                $perfil = $request->datosPuesto() === [] ? null
                    : ($registro['tipo_personal'] === 'docente' ? $vinculo->perfilDocente() : $vinculo->perfilAdministrativo())->create($request->datosPuesto());
                Auditoria::registrar($request, 'legajo.abrir', 'legajos', $legajo->id, $registro['id'], null, $legajo->getAttributes());
                Auditoria::registrar($request, 'vinculo.registrar', 'vinculos_laborales', $vinculo->id, $registro['id'], null, [...$vinculo->getAttributes(), 'datos_puesto' => $perfil?->getAttributes()]);

                if ($tipoIngreso) {
                    try {
                        $documentoIngreso = CargaDocumento::registrar($legajo, $tipoIngreso, $request->file('documento_ingreso_pdf'), [
                            'emisor' => $ingreso['emisor'], 'fecha_emision' => $ingreso['fecha_emision'],
                            'fecha_incorporacion' => now('America/Lima')->toDateString(),
                            'modalidad_presentacion' => $ingreso['modalidad_presentacion'],
                            'titular_nombre' => mb_substr(trim($registro['nombres'].' '.$registro['apellido_paterno'].' '.($registro['apellido_materno'] ?? '')), 0, 200),
                            'titular_documento' => $registro['numero_doc_identidad'], 'relacion_titular' => 'servidor',
                            'num_resolucion' => $request->vinculo()['numero_documento_vinculo'] ?? null,
                        ], $actor->getAuthIdentifier(), $request->ip(), enlaceId: $vinculo->id, foliosAutomaticos: true,
                            completar: fn (DocumentoUsuario $doc) => AuditoriaDocumento::registrar($doc, $request, 'documento.creado'));
                        $archivoGuardado = $documentoIngreso->archivo->only(['almacenamiento', 'storage_path']);
                    } catch (ValidationException $error) {
                        throw ValidationException::withMessages(['documento_ingreso_pdf' => collect($error->errors())->flatten()->all()]);
                    }
                }

                return [$legajo, $vinculo];
            });

        } catch (Throwable $error) {
            if ($archivoGuardado) {
                Storage::disk($archivoGuardado['almacenamiento'])->delete($archivoGuardado['storage_path']);
            }
            throw $error;
        }

        return response()->json(['data' => [
            'usuario' => new UsuarioResource(SituacionLaboral::cargar(Usuario::query()->with('regimenNormativo'))->findOrFail($registro['id'])),
            'legajo' => $legajo->only(['id', 'numero_legajo', 'estado_legajo']),
            'vinculo' => $vinculo->only(['id', 'regimen_laboral', 'nombre_puesto']),
            'documento_ingreso' => $documentoIngreso?->only(['id', 'folio_inicio', 'folio_fin', 'vinculo_laboral_id', 'tipo_documento_id']),
        ]], 201);
    }

    /**
     * Filas validadas del alta listas para insertar: permiso de discapacidad, correos ya registrados y valores por defecto.
     *
     * @return list<array<string, mixed>>
     */
    private function registros(array $rows, Request $request): array
    {
        foreach ($rows as $row) {
            CambioDiscapacidad::autorizar($request->user(), null, CambioDiscapacidad::valor($row[CambioDiscapacidad::CAMPO] ?? null));
        }
        $emails = array_map(fn (array $row): string => strtolower($row['email_institucional']), $rows);
        $existingEmails = Usuario::query()
            ->whereIn(DB::raw('LOWER(email_institucional)'), $emails)
            ->pluck('email_institucional')
            ->map(fn (string $email): string => strtolower($email));

        $errors = [];
        foreach ($emails as $index => $email) {
            if ($existingEmails->contains($email)) {
                $errors["usuarios.$index.email_institucional"] = 'Este correo ya está registrado.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** These records cannot sign in until a separate account activation flow sets their credentials. */
        $unissuedPassword = Hash::make(Str::random(64));
        $now = now();

        return array_map(fn (array $row): array => [
            ...$row,
            ...$this->ley($row),
            'id' => (string) Str::uuid(),
            'tipo_doc_identidad' => 'dni',
            'nacionalidad' => 'Peruano',
            'email_institucional' => strtolower($row['email_institucional']),
            'apellido_materno' => $row['apellido_materno'] ?? null,
            'telefono' => $row['telefono'] ?? null,
            'ubigeo_id' => $row['ubigeo_id'] ?? null,
            'fecha_nacimiento' => $row['fecha_nacimiento'] ?? null,
            'domicilio' => filled($row['domicilio'] ?? null) ? trim($row['domicilio']) : null,
            // Todas las filas con las mismas columnas (inserción por bloques); sin respuesta, Sin responder.
            'tiene_derechohabientes' => CambioDiscapacidad::valor($row['tiene_derechohabientes'] ?? null),
            'con_discapacidad' => CambioDiscapacidad::valor($row['con_discapacidad'] ?? null),
            'password' => $unissuedPassword,
            'activo' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);
    }

    /** Inserta las filas y audita la discapacidad, en una transacción; un DNI o correo repetido es un error de validación. */
    private function insertar(array $records, Request $request): void
    {
        try {
            DB::transaction(function () use ($records, $request): void {
                foreach (array_chunk($records, 100) as $chunk) {
                    Usuario::query()->insert($chunk);
                }
                foreach ($records as $record) {
                    Auditoria::registrar($request, 'persona.alta', 'usuarios', $record['id'], $record['id'], null, Arr::except($record, ['created_at', 'updated_at']));
                    CambioDiscapacidad::auditar($record['id'], null, $record['con_discapacidad'], $request->user(), $request);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'usuarios' => 'Un DNI o correo ya está registrado. Actualiza la lista y revisa el archivo.',
            ]);
        }
    }
}
