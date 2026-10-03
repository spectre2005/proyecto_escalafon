<?php

namespace App\Providers;

use App\Models\Usuario;
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
            Gate::define($permiso, fn (Usuario $usuario): bool => $usuario->tienePermiso($permiso));
        }

        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()
            : Password::min(8)->letters()->numbers());
    }
}
