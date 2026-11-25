<?php

namespace App\Providers;

use App\Models\Usuario;
use App\Support\Antivirus\ClamAv;
use App\Support\Antivirus\EscanerAntivirus;
use App\Support\Antivirus\SinAntivirus;
use App\Support\Plataforma\AlmacenamientoUnica;
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
        // En producción siempre clamav: ANTIVIRUS_DRIVER=ninguno solo vale en desarrollo (config/antivirus.php).
        $this->app->bind(EscanerAntivirus::class, fn (): EscanerAntivirus => config('antivirus.driver') === 'ninguno' && ! $this->app->isProduction()
            ? new SinAntivirus
            : new ClamAv((string) config('antivirus.clamav.host'), (int) config('antivirus.clamav.puerto'),
                (int) config('antivirus.clamav.tiempo_conexion'), (int) config('antivirus.clamav.tiempo_respuesta')));
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

        // Modo unica con carpeta institucional propia (PLATAFORMA_ALMACENAMIENTO_UNICA); vacía, no hace nada.
        AlmacenamientoUnica::aplicar();

        // Varias instituciones: los trabajos en cola llevan su institución y se ejecutan con sus recursos.
        ColasInstitucion::configurar();
        ColasInstitucion::registrar();
    }
}
