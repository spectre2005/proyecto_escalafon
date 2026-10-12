<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUsuariosRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\Usuario;
use App\Support\CambioDiscapacidad;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

class UsuarioController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UsuarioResource::collection(Usuario::query()->orderBy('id')->paginate(100));
    }

    public function show(string $record): UsuarioResource
    {
        return new UsuarioResource(Usuario::query()->findOrFail($record));
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
                $usuario->update($data);
                if ($discapacidad) {
                    CambioDiscapacidad::auditar($usuario->id, $discapacidad[0], $discapacidad[1], $request->user(), $request);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['usuario' => 'Un DNI o correo ya está registrado.']);
        }

        return new UsuarioResource($usuario->refresh());
    }

    public function destroy(string $record): JsonResponse
    {
        $usuario = Usuario::query()->findOrFail($record);
        try {
            $usuario->delete();
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
        $rows = $request->validated('usuarios');
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
        $records = array_map(fn (array $row): array => [
            ...$row,
            'id' => (string) Str::uuid(),
            'tipo_doc_identidad' => 'dni',
            'nacionalidad' => 'Peruano',
            'email_institucional' => strtolower($row['email_institucional']),
            'apellido_materno' => $row['apellido_materno'] ?? null,
            'telefono' => $row['telefono'] ?? null,
            'ubigeo_id' => $row['ubigeo_id'] ?? null,
            // Todas las filas con las mismas columnas (inserción por bloques); sin respuesta, Sin responder.
            'tiene_derechohabientes' => CambioDiscapacidad::valor($row['tiene_derechohabientes'] ?? null),
            'con_discapacidad' => CambioDiscapacidad::valor($row['con_discapacidad'] ?? null),
            'password' => $unissuedPassword,
            'activo' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        try {
            DB::transaction(function () use ($records, $request): void {
                foreach (array_chunk($records, 100) as $chunk) {
                    Usuario::query()->insert($chunk);
                }
                foreach ($records as $record) {
                    CambioDiscapacidad::auditar($record['id'], null, $record['con_discapacidad'], $request->user(), $request);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'usuarios' => 'Un DNI o correo ya está registrado. Actualiza la lista y revisa el archivo.',
            ]);
        }

        return UsuarioResource::collection(
            Usuario::query()->whereIn('id', array_column($records, 'id'))->orderBy('id')->get(),
        )->response()->setStatusCode(201);
    }
}
