<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Plataforma\Institucion;
use App\Models\Usuario;
use App\Support\Plataforma\AdministradoresInstitucion;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use PDOException;

/**
 * Administradores de una institución aprovisionada, desde el panel de plataforma: listar, agregar uno, darle una
 * contraseña temporal nueva y desactivarlo o reactivarlo (AdministradoresInstitucion). Solo cuentas de administración;
 * ninguna ruta a personas, legajos ni documentos. Cada operación queda auditada, sin la contraseña temporal, que solo
 * viaja en la respuesta (Cache-Control: no-store).
 */
class AdministradorInstitucionController extends Controller
{
    public function index(string $institucion): JsonResponse
    {
        return response()->json(['data' => $this->enBase($this->institucion($institucion), fn (): array => AdministradoresInstitucion::listar())]);
    }

    public function store(Request $request, string $institucion): JsonResponse
    {
        $datos = $this->validar($request, [
            'nombres' => 'required|string|min:2|max:100', 'apellido_paterno' => 'required|string|min:2|max:100', 'email' => 'required|email|max:255',
        ]);
        $registro = $this->institucion($institucion);
        [$admin, $clave] = $this->enBase($registro, fn (): array => AdministradoresInstitucion::crear($datos));
        $this->auditar('administrador_institucion.creado', $request, $registro, $admin);

        return $this->conClave($admin, $clave, 201);
    }

    public function candidato(Request $request, string $institucion): JsonResponse
    {
        $datos = $this->validar($request, ['email' => 'required|email|max:255']);
        $registro = $this->institucion($institucion);

        return response()->json(['data' => $this->enBase($registro, fn (): array => AdministradoresInstitucion::candidato($datos['email']))])->header('Cache-Control', 'no-store');
    }

    public function asignarExistente(Request $request, string $institucion): JsonResponse
    {
        $datos = $this->validar($request, ['usuario_id' => 'required|uuid', 'email_confirmado' => 'required|email|max:255', 'identidad_confirmada' => 'required|accepted', 'motivo' => 'required|string|min:5|max:500']);
        $registro = $this->institucion($institucion);
        abort_if($registro->estado === Institucion::ARCHIVADA, 409, 'La institución está archivada.');
        $admin = $this->enBase($registro, fn (): Usuario => AdministradoresInstitucion::asignarExistente($datos['usuario_id'], $datos['email_confirmado']));
        AuditoriaOperaciones::registrar('administrador_institucion.asignado_existente', $request, Auth::guard('plataforma')->id(), $registro->id, null,
            ['usuario_id' => $admin->id, 'email' => $admin->email_institucional, 'identidad_confirmada' => true], $datos['motivo']);

        return response()->json(['data' => AdministradoresInstitucion::datos($admin)])->header('Cache-Control', 'no-store');
    }

    public function restablecer(Request $request, string $institucion, string $administrador): JsonResponse
    {
        $this->validar($request, []);
        $registro = $this->institucion($institucion);
        [$admin, $clave] = $this->enBase($registro, fn (): array => AdministradoresInstitucion::restablecer($administrador));
        $this->auditar('administrador_institucion.clave_restablecida', $request, $registro, $admin);

        return $this->conClave($admin, $clave);
    }

    public function desactivar(Request $request, string $institucion, string $administrador): JsonResponse
    {
        return $this->cambiarActivo($request, $institucion, $administrador, false);
    }

    public function reactivar(Request $request, string $institucion, string $administrador): JsonResponse
    {
        return $this->cambiarActivo($request, $institucion, $administrador, true);
    }

    private function cambiarActivo(Request $request, string $institucion, string $administrador, bool $activo): JsonResponse
    {
        $this->validar($request, []);
        $registro = $this->institucion($institucion);
        $admin = $this->enBase($registro, fn (): Usuario => AdministradoresInstitucion::cambiarActivo($administrador, $activo));
        $this->auditar($activo ? 'administrador_institucion.reactivado' : 'administrador_institucion.desactivado', $request, $registro, $admin);

        return response()->json(['data' => AdministradoresInstitucion::datos($admin)]);
    }

    /** Solo instituciones aprovisionadas tienen base (y administradores). */
    private function institucion(string $id): Institucion
    {
        $institucion = Institucion::query()->findOrFail($id);
        abort_unless($institucion->aprovisionada(), 409, 'La institución aún no está aprovisionada: no tiene base ni administradores.');

        return $institucion;
    }

    private function enBase(Institucion $institucion, \Closure $accion): mixed
    {
        try {
            return AdministradoresInstitucion::enBase($institucion, $accion);
        } catch (QueryException|PDOException $error) {
            report($error);
            abort(503, 'No se pudo conectar con la base de la institución.');
        }
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, array $reglas): array
    {
        $ajenos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($ajenos !== []) {
            throw ValidationException::withMessages(array_fill_keys($ajenos, 'Campo no permitido.'));
        }

        return $request->validate($reglas);
    }

    private function auditar(string $accion, Request $request, Institucion $institucion, Usuario $admin): void
    {
        AuditoriaOperaciones::registrar($accion, $request, Auth::guard('plataforma')->id(), $institucion->id, null,
            ['usuario_id' => $admin->id, 'email' => $admin->email_institucional]);
    }

    private function conClave(Usuario $admin, string $clave, int $estado = 200): JsonResponse
    {
        return response()->json(['data' => [...AdministradoresInstitucion::datos($admin), 'clave_temporal' => $clave]], $estado)
            ->header('Cache-Control', 'no-store');
    }
}
