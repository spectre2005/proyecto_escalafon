<?php

namespace App\Support;

use App\Rules\Ruc;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Datos tributarios consultados mediante Factiliza; el RUC identifica al contribuyente, no a su representante. */
final class ConsultaRuc
{
    /** @return array<string, mixed> */
    public static function consultar(string $ruc, bool $actualizar = false): array
    {
        Validator::make(['ruc' => $ruc], ['ruc' => ['required', 'string', new Ruc]])->validate();
        $clave = 'plataforma:consulta-ruc:v2:'.$ruc;
        if (! $actualizar && ($guardada = Cache::get($clave))) {
            return [...$guardada, 'origen' => 'cache'];
        }

        try {
            return Cache::lock($clave.':consulta', 15)->block(3, function () use ($clave, $ruc, $actualizar): array {
                if (! $actualizar && ($guardada = Cache::get($clave))) {
                    return [...$guardada, 'origen' => 'cache'];
                }
                $datos = self::desdeProveedor($ruc);
                Cache::put($clave, $datos, now()->addDay());

                return [...$datos, 'origen' => 'factiliza'];
            });
        } catch (LockTimeoutException) {
            abort(503, 'Ya hay una consulta de ese RUC en curso. Inténtalo de nuevo en unos segundos.');
        }
    }

    /** @return array<string, mixed> */
    private static function desdeProveedor(string $ruc): array
    {
        $token = trim((string) config('services.factiliza.token'));
        abort_if($token === '', 503, 'La consulta por RUC aún no está configurada. Puedes completar los datos manualmente.');
        try {
            $respuesta = Http::acceptJson()->withToken($token)->connectTimeout(3)->timeout(10)
                ->withoutRedirecting()->get('https://api.factiliza.com/v1/ruc/info/'.$ruc);
        } catch (ConnectionException) {
            abort(503, 'La consulta por RUC no respondió a tiempo. Puedes reintentar o completar los datos manualmente.');
        }
        if ($respuesta->status() === 404) {
            throw ValidationException::withMessages(['ruc' => 'No se encontraron datos para ese RUC. Revisa el número o completa los datos manualmente.']);
        }
        abort_if($respuesta->status() === 429, 503, 'El servicio de consulta alcanzó su límite de uso. Puedes completar los datos manualmente.');
        abort_unless($respuesta->successful() && $respuesta->json('success') === true, 503,
            'No se pudieron consultar los datos del RUC. Puedes reintentar o completar los datos manualmente.');
        $datos = $respuesta->json('data');
        abort_unless(is_array($datos), 503, 'El servicio devolvió datos incompletos. Puedes completarlos manualmente.');
        $reglas = [
            'numero' => ['required', 'string', 'in:'.$ruc],
            'nombre_o_razon_social' => ['required', 'string', 'min:3', 'max:200'],
            'tipo_contribuyente' => ['nullable', 'string', 'max:150'],
            'estado' => ['nullable', 'string', 'max:100'],
            'condicion' => ['nullable', 'string', 'max:100'],
            'direccion_completa' => ['nullable', 'string', 'max:500'],
            'direccion' => ['nullable', 'string', 'max:500'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'max:100'],
            'distrito' => ['nullable', 'string', 'max:100'],
            'ubigeo_sunat' => ['nullable', 'string', 'regex:/\A[0-9]{6}\z/'],
        ];
        abort_if(Validator::make($datos, $reglas)->fails() || mb_strlen(trim($datos['nombre_o_razon_social'] ?? '')) < 3,
            503, 'El servicio devolvió datos incompletos o de otro RUC. Puedes completarlos manualmente.');

        return [
            'ruc' => $ruc, 'razon_social' => trim($datos['nombre_o_razon_social']),
            ...collect(['tipo_contribuyente', 'estado', 'condicion', 'departamento', 'provincia', 'distrito', 'direccion', 'direccion_completa', 'ubigeo_sunat'])
                ->mapWithKeys(fn (string $campo): array => [$campo => trim((string) ($datos[$campo] ?? '')) ?: null])->all(),
            'consultado_en' => now()->toIso8601String(),
        ];
    }
}
