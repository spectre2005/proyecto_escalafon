<?php

namespace App\Console\Commands;

use App\Models\Rol;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SincronizarPermisos extends Command
{
    protected $signature = 'permisos:importar-legacy {--aplicar : Inserta políticas iniciales; sin esta opción solo muestra el resumen}';

    protected $description = 'Importa una vez permisos vigentes desde la configuración para iniciar la transición a BD';

    public function handle(): int
    {
        $filas = [];
        $catalogo = DB::table('permisos')->pluck('id', 'slug');
        $asignables = array_diff(array_keys(config('permisos.catalogo')), array_keys(config('permisos.derivados')), config('permisos.pendientes'));
        foreach (Rol::query()->get() as $rol) {
            $configurados = config('permisos.roles.'.$rol->codigo, []);
            $slugs = $configurados === '*' ? $asignables : array_intersect($configurados, $asignables);
            foreach (config('permisos.derivados') as $slug => $campo) {
                if ($rol->{$campo}) {
                    $slugs[] = $slug;
                }
            }
            foreach (array_unique($slugs) as $slug) {
                if (! isset($catalogo[$slug])) {
                    $this->error('El catálogo de BD no contiene todos los permisos.');

                    return self::FAILURE;
                }
                $filas[] = ['rol_id' => $rol->id, 'permiso_id' => $catalogo[$slug], 'permitido' => true];
            }
        }
        if ($this->option('aplicar')) {
            DB::transaction(function () use ($filas): void {
                foreach ($filas as $fila) {
                    DB::table('permisos_rol')->insertOrIgnore($fila);
                }
            });
        }
        $this->info(count($filas).' asignaciones '.($this->option('aplicar') ? 'procesadas.' : 'previstas; no se escribió ningún permiso.'));

        return self::SUCCESS;
    }
}
