<?php

namespace App\Providers;

use App\Models\Usuario;
use App\Support\Plataforma\ColasInstitucion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (array_keys(config('permisos.catalogo')) as $permiso) {
            Gate::define($permiso, fn (Usuario $usuario): bool => $usuario->tienePermiso($permiso)
                || (in_array($permiso, ['legajo.ver', 'datos_sensibles.ver', 'seccion07.ver', 'seccion08.sanciones.ver'], true)
                    && request()->isMethod('GET') && request()->is('api/v1/expedientes/*')
                    && request()->route('usuario') === $usuario->id && $usuario->tienePermiso('legajo.propio')));
        }

        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()
            : Password::min(8)->letters()->numbers());

        // Varias instituciones: los trabajos en cola llevan su institución y se ejecutan con sus recursos.
        ColasInstitucion::configurar();
        ColasInstitucion::registrar();
    }
}
