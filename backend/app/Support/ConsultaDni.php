<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Directorio de identidad. La ubicación reportada no acredita el lugar de nacimiento. */
final class ConsultaDni
{
    /** @return array<string, mixed> */
    public static function consultar(string $dni, bool $conUbicacion = false, bool $actualizar = false): array
    {
        $tabla = DB::connection('plataforma')->table('identidades_dni');
        $guardada = $tabla->where('dni', $dni)->first();
        if (! $actualizar && $guardada && (! $conUbicacion || $guardada->ubicacion_consultada_en !== null)) {
            return self::datos($guardada, 'base_datos', $conUbicacion);
        }

        try {
            return self::datos(self::desdeProveedor($dni), 'factiliza', $conUbicacion);
        } catch (HttpException|ValidationException $error) {
            if ($guardada && ! $actualizar) {
                return self::datos($guardada, 'base_datos', $conUbicacion);
            }
            throw $error;
        }
    }

    private static function desdeProveedor(string $dni): object
    {
        $token = trim((string) config('services.factiliza.token'));
        abort_if($token === '', 503, 'La consulta por DNI aún no está configurada. Puedes completar los nombres manualmente.');

        try {
            $respuesta = Http::acceptJson()->withToken($token)->connectTimeout(3)->timeout(10)
                ->withoutRedirecting()->get('https://api.factiliza.com/v1/dni/info/'.$dni);
        } catch (ConnectionException) {
            abort(503, 'La consulta por DNI no respondió a tiempo. Inténtalo nuevamente o completa los nombres manualmente.');
        }

        if ($respuesta->status() === 404) {
            throw ValidationException::withMessages(['dni' => 'No se encontraron datos para ese DNI. Revisa el número o completa los nombres manualmente.']);
        }
        abort_if($respuesta->status() === 429, 503, 'El servicio de consulta alcanzó su límite de uso. Puedes completar los nombres manualmente.');
        abort_unless($respuesta->successful() && $respuesta->json('success') === true, 503,
            'No se pudieron consultar los datos del DNI. Inténtalo nuevamente o completa los nombres manualmente.');

        $datos = $respuesta->json('data');
        abort_unless(is_array($datos), 503, 'El servicio devolvió una respuesta incompleta. Puedes completar los nombres manualmente.');
        $validador = Validator::make($datos, [
            'numero' => ['required', 'string', 'in:'.$dni],
            'nombres' => ['required', 'string', 'max:120'],
            'apellido_paterno' => ['required', 'string', 'max:80'],
            'apellido_materno' => ['nullable', 'string', 'max:80'],
        ]);
        abort_if($validador->fails(), 503, 'El servicio devolvió datos incompletos o de otro DNI. Puedes completar los nombres manualmente.');
        $nombre = trim($datos['nombres']);
        $paterno = trim($datos['apellido_paterno']);
        abort_if($nombre === '' || $paterno === '', 503, 'El servicio devolvió nombres incompletos. Puedes completarlos manualmente.');

        $ubicacion = self::ubicacionReportada($datos);
        DB::connection('plataforma')->table('identidades_dni')->upsert([[
            'dni' => $dni, 'nombres' => $nombre, 'apellido_paterno' => $paterno,
            'apellido_materno' => trim($datos['apellido_materno'] ?? '') ?: null,
            'ubicacion' => $ubicacion ? json_encode($ubicacion, JSON_THROW_ON_ERROR) : null,
            'ubicacion_consultada_en' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]], ['dni'], ['nombres', 'apellido_paterno', 'apellido_materno', 'ubicacion', 'ubicacion_consultada_en', 'updated_at']);

        return DB::connection('plataforma')->table('identidades_dni')->where('dni', $dni)->first();
    }

    /** @return array{region: string, provincia: string, distrito: string}|null */
    private static function ubicacionReportada(array $datos): ?array
    {
        $reglas = array_fill_keys(['departamento', 'provincia', 'distrito'], ['required', 'string', 'max:100']);
        if (Validator::make($datos, $reglas)->fails()) {
            return null;
        }
        $ubicacion = ['region' => trim($datos['departamento']), 'provincia' => trim($datos['provincia']), 'distrito' => trim($datos['distrito'])];

        return in_array('', $ubicacion, true) ? null : $ubicacion;
    }

    /** @return array<string, mixed> */
    private static function datos(object $persona, string $origen, bool $conUbicacion): array
    {
        return ['dni' => $persona->dni, 'nombres' => $persona->nombres, 'apellido_paterno' => $persona->apellido_paterno,
            'apellido_materno' => $persona->apellido_materno, 'origen' => $origen,
            ...($conUbicacion ? ['ubicacion' => $persona->ubicacion ? json_decode($persona->ubicacion, true, flags: JSON_THROW_ON_ERROR) : null] : [])];
    }
}
