<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Auditoria;
use App\Support\Identidad\ConsultaDni;
use App\Support\Identidad\ProveedorIdentidad;
use App\Support\Identidad\UbicacionCatalogo;
use App\Support\ModulosSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Consulta de identidad por DNI para completar nombres, apellidos y, para quien ve datos sensibles, la ubicación del
 * catálogo (UbicacionCatalogo) en el registro manual. No crea ni guarda personas:
 * solo devuelve datos para el formulario. Exige los mismos permisos que el registro manual (usuarios.gestionar en la
 * ruta, legajo.abrir y legajo.editar aquí) y el módulo «consulta_dni» activo en la institución de la sesión (la del
 * subdominio; el cliente no puede elegir otra). Cada consulta que llega al proveedor queda en auditoria_eventos sin el
 * DNI completo. Si no hay proveedor o falla, el registro sigue manual.
 */
class ConsultaIdentidadController extends Controller
{
    public const MODULO = 'consulta_dni';

    /** Si la institución tiene la consulta activa y si hay un proveedor que pueda atenderla (para mostrar el botón). */
    public function estado(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $habilitada = ModulosSistema::activa(self::MODULO);
        $proveedor = $habilitada ? ProveedorIdentidad::actual() : null;

        return response()->json(['data' => ['habilitada' => $habilitada, 'disponible' => $proveedor !== null, 'simulada' => $proveedor !== null && $proveedor->esSimulado()]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function consultar(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $desconocidos = array_diff(array_keys($request->all()), ['dni']);
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
        $dni = $request->validate(['dni' => ['required', 'string', 'digits:8']], ['dni.digits' => 'El DNI debe contener 8 dígitos.'])['dni'];

        $proveedor = ProveedorIdentidad::actual();
        if (! $proveedor) {
            return $this->rechazo(503, 'proveedor_no_configurado', 'La consulta de identidad no está disponible en este momento. Completa los datos manualmente.');
        }
        if ($espera = $this->limiteAlcanzado($request)) {
            return $this->rechazo(429, 'limite_consultas', 'Se alcanzó el límite de consultas de identidad. Espera un momento o completa los datos manualmente.')
                ->header('Retry-After', (string) $espera);
        }

        try {
            $resultado = $proveedor->consultar($dni);
        } catch (Throwable $error) {
            // ProveedorNoDisponible u otro fallo del servicio (conexión, respuesta inesperada): el registro sigue manual.
            // Sin el mensaje: el de un cliente HTTP puede llevar la URL consultada y, con ella, el DNI.
            Log::warning('Consulta de identidad: el proveedor no respondió.', ['proveedor' => $proveedor->nombre(), 'error' => $error::class]);
            $this->auditar($request, $proveedor, $dni, 'proveedor_no_disponible');

            return $this->rechazo(503, 'proveedor_no_disponible', 'El servicio de identidad no respondió. Inténtalo más tarde o completa los datos manualmente.');
        }
        $this->auditar($request, $proveedor, $dni, $resultado->encontrado ? 'coincidencia' : 'sin_coincidencia');
        $simulada = $proveedor->esSimulado();
        $datos = ['encontrado' => true, 'simulada' => $simulada, 'nombres' => $resultado->nombres, 'apellido_paterno' => $resultado->apellidoPaterno, 'apellido_materno' => $resultado->apellidoMaterno];
        // La ubicación es un dato sensible (región, provincia y distrito): sin datos_sensibles.ver no sale ni se busca.
        if ($resultado->encontrado && $request->user()->veDatosSensibles()) {
            ['estado' => $estado, 'ubicacion' => $ubicacion] = UbicacionCatalogo::emparejar($resultado->ubicacion);
            $datos += ['ubicacion' => $ubicacion, 'ubicacion_estado' => $estado];
        }

        return response()->json(['data' => $resultado->encontrado ? $datos : ['encontrado' => false, 'simulada' => $simulada]])
            ->header('Cache-Control', 'private, no-store');
    }

    /** Mismos permisos que el registro manual (UsuarioController::storeConVinculo). */
    private function autorizar(Request $request): void
    {
        $actor = $request->user();
        abort_unless($actor->can('legajo.abrir') && $actor->can('legajo.editar'), 403,
            'La consulta de identidad es parte del registro manual: requiere abrir legajos (legajo.abrir) y registrar vínculos (legajo.editar).');
    }

    /** Segundos de espera si el usuario o la institución agotaron sus consultas del minuto; si no, cuenta esta y devuelve 0. */
    private function limiteAlcanzado(Request $request): int
    {
        $institucion = ModulosSistema::institucionActual()?->slug ?? 'unica';
        $claves = ['consulta-dni:usuario:'.$institucion.':'.$request->user()->getAuthIdentifier() => (int) config('identidad.limite_por_usuario'),
            'consulta-dni:institucion:'.$institucion => (int) config('identidad.limite_por_institucion')];
        foreach ($claves as $clave => $maximo) {
            if (RateLimiter::tooManyAttempts($clave, $maximo)) {
                return max(1, RateLimiter::availableIn($clave));
            }
        }
        foreach (array_keys($claves) as $clave) {
            RateLimiter::hit($clave, 60);
        }

        return 0;
    }

    /**
     * Evento por consulta, encadenado por usuario (entidad consultas_identidad, entidad_id = quien consulta). Del DNI solo
     * guarda los tres últimos dígitos y una huella HMAC con la APP_KEY, que permite comprobar si se consultó un DNI
     * concreto sin guardarlo legible.
     */
    private function auditar(Request $request, ConsultaDni $proveedor, string $dni, string $resultado): void
    {
        Auditoria::registrar($request, 'identidad.consultar_dni', 'consultas_identidad', $request->user()->getAuthIdentifier(), null, null, [
            'resultado' => $resultado, 'proveedor' => $proveedor->nombre(), 'oficial' => $proveedor->esOficial(), 'simulada' => $proveedor->esSimulado(),
            'institucion' => ModulosSistema::institucionActual()?->slug, 'dni_parcial' => '*****'.substr($dni, -3), 'dni_huella' => self::huella($dni),
        ]);
    }

    public static function huella(string $dni): string
    {
        return hash_hmac('sha256', 'consulta-dni:'.$dni, (string) config('app.key'));
    }

    private function rechazo(int $estado, string $codigo, string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'codigo' => $codigo], $estado)->header('Cache-Control', 'private, no-store');
    }
}
