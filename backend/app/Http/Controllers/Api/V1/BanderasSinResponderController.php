<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Support\AplicacionDocumental;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;

/**
 * Personas con banderas sin responder (null), para administración (sistema.administrar): las de la persona en
 * usuarios; las del puesto, combinadas por sus vínculos vigentes como en AplicacionDocumental (Sin responder si
 * ninguno dice Sí y alguno está sin responder). Sin vínculo vigente las del puesto no cuentan: se registran en el vínculo.
 *
 * personas: quiénes son (id, nombre y qué condiciones les faltan), para abrir su ficha desde el Inicio. Se agrega a la
 * respuesta anterior (total y por_bandera no cambian). Hasta LIMITE personas, por apellido; personas_mostradas lo dice.
 */
class BanderasSinResponderController extends Controller
{
    private const LIMITE = 100;

    public function __invoke(): JsonResponse
    {
        $porBandera = [];
        $idsPorBandera = [];
        foreach ([...AplicacionDocumental::PERSONA, ...AplicacionDocumental::PUESTO] as $bandera) {
            $idsPorBandera[$bandera] = $this->sinResponder(Usuario::query(), $bandera)->pluck('id')->all();
            $porBandera[$bandera] = count($idsPorBandera[$bandera]);
        }
        $total = Usuario::query()->where(function (Builder $query): void {
            foreach ([...AplicacionDocumental::PERSONA, ...AplicacionDocumental::PUESTO] as $bandera) {
                $query->orWhere(fn (Builder $una): Builder => $this->sinResponder($una, $bandera));
            }
        })->count();

        $ids = array_values(array_unique(array_merge(...array_values($idsPorBandera))));
        $personas = Usuario::query()->whereIn('id', $ids)->orderBy('apellido_paterno')->orderBy('apellido_materno')->orderBy('nombres')->orderBy('id')
            ->limit(self::LIMITE)->get(['id', 'nombres', 'apellido_paterno', 'apellido_materno'])
            ->map(fn (Usuario $persona): array => [
                'id' => $persona->id,
                'nombre' => trim($persona->apellido_paterno.' '.$persona->apellido_materno.', '.$persona->nombres, ' ,'),
                'pendientes' => array_keys(array_filter($idsPorBandera, fn (array $conBandera): bool => in_array($persona->id, $conBandera, true))),
            ])->values();

        return response()->json(['data' => ['total' => $total, 'por_bandera' => $porBandera, 'personas' => $personas, 'personas_mostradas' => $personas->count()]])
            ->header('Cache-Control', 'private, no-store');
    }

    private function sinResponder(Builder $query, string $bandera): Builder
    {
        if (in_array($bandera, AplicacionDocumental::PERSONA, true)) {
            return $query->whereNull($bandera);
        }
        $vigentes = fn (QueryBuilder $vinculo): QueryBuilder => $vinculo->from('vinculos_laborales')->whereColumn('vinculos_laborales.usuario_id', 'usuarios.id')
            ->where('anulado', false)->whereNull('fecha_cese');

        return $query->whereExists(fn (QueryBuilder $vinculo): QueryBuilder => $vigentes($vinculo)->whereNull($bandera))
            ->whereNotExists(fn (QueryBuilder $vinculo): QueryBuilder => $vigentes($vinculo)->where($bandera, true));
    }
}
