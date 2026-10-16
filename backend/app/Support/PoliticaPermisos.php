<?php

namespace App\Support;

use App\Models\Rol;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PoliticaPermisos
{
    /** @param Collection<int, Rol> $roles */
    public static function precargar(Collection $roles): void
    {
        if ($roles->isEmpty()) {
            return;
        }
        $filas = DB::table('permisos_rol')->join('permisos', 'permisos.id', '=', 'permisos_rol.permiso_id')
            ->whereIn('rol_id', $roles->pluck('id'))->where('permitido', true)->get(['rol_id', 'slug'])->groupBy('rol_id');
        foreach ($roles as $rol) {
            $rol->setRelation('permisosExplicitos', ($filas->get($rol->id) ?? collect())->pluck('slug'));
        }
    }

    /** @param Collection<int, Rol> $roles */
    public static function resolver(Collection $roles): array
    {
        self::precargar($roles->filter(fn (Rol $rol): bool => ! $rol->relationLoaded('permisosExplicitos')));

        return $roles->flatMap(fn (Rol $rol) => $rol->getRelation('permisosExplicitos'))
            ->intersect(array_keys(config('permisos.catalogo')))->unique()->values()->all();
    }
}
