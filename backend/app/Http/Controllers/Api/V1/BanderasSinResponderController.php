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
 */
class BanderasSinResponderController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $porBandera = [];
        foreach ([...AplicacionDocumental::PERSONA, ...AplicacionDocumental::PUESTO] as $bandera) {
            $porBandera[$bandera] = $this->sinResponder(Usuario::query(), $bandera)->count();
        }
        $total = Usuario::query()->where(function (Builder $query): void {
            foreach ([...AplicacionDocumental::PERSONA, ...AplicacionDocumental::PUESTO] as $bandera) {
                $query->orWhere(fn (Builder $una): Builder => $this->sinResponder($una, $bandera));
            }
        })->count();

        return response()->json(['data' => ['total' => $total, 'por_bandera' => $porBandera]])->header('Cache-Control', 'private, no-store');
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
