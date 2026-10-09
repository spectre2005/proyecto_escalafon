<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AccesoLegajo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccesoSeccionesController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => AccesoLegajo::secciones(), 'version' => AccesoLegajo::version()])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'secciones' => 'required|array|size:13',
            'secciones.*' => 'required|array:id,activa,disponibilidad',
            'secciones.*.id' => 'required|integer|between:1,13|distinct',
            'secciones.*.activa' => 'required|boolean',
            'secciones.*.disponibilidad' => 'required|in:siempre_activa,periodo_pendiente',
        ]);
        abort_unless($request->hasHeader('If-Match'), 428, 'Recarga la configuración antes de guardar.');
        DB::transaction(function () use ($request, $data): void {
            DB::table('secciones_legajo')->orderBy('id')->lockForUpdate()->get();
            abort_unless(hash_equals(AccesoLegajo::version(), $request->header('If-Match')), 409, 'Otro usuario cambió las secciones. Restaura y vuelve a intentar.');
            foreach ($data['secciones'] as $seccion) {
                if ($seccion['id'] === 9) {
                    $seccion['disponibilidad'] = 'periodo_pendiente';
                }
                DB::table('secciones_legajo')->where('id', $seccion['id'])->update($seccion);
            }
        });

        return $this->show();
    }
}
