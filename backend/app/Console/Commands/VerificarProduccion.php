<?php

namespace App\Console\Commands;

use App\Support\Plataforma\AlmacenamientoUnica;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Revisa que la configuración sea segura para producción. El contenedor de producción lo ejecuta antes de arrancar
 * PHP y no arranca si hay un error (docker/produccion/entrada.sh). Los avisos no detienen el arranque.
 */
class VerificarProduccion extends Command
{
    protected $signature = 'produccion:verificar';

    protected $description = 'Revisa que la configuración sea segura para producción (HTTPS, sin depuración, claves, cookies)';

    private const CLAVES_DEBILES = ['', 'secret', 'password', 'postgres', 'root', 'app', 'admin', '123456', 'escalafon'];

    /** @var list<string> */
    private array $errores = [];

    /** @var list<string> */
    private array $avisos = [];

    public function handle(): int
    {
        // La misma instancia atiende cada llamada del proceso (Artisan::call en las pruebas): sin restos de la anterior.
        $this->errores = [];
        $this->avisos = [];
        $this->exigir(app()->environment('production'), 'APP_ENV debe ser production.');
        $this->exigir(! config('app.debug'), 'APP_DEBUG debe ser false: con depuración, un error muestra código y datos.');
        $this->exigir(Str::length((string) config('app.key')) > 0, 'Falta APP_KEY (php artisan key:generate --show).');
        $this->exigir(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL debe empezar con https://.');
        $this->exigir(config('session.secure') === true, 'SESSION_SECURE_COOKIE debe ser true: la cookie de sesión solo viaja por HTTPS.');
        $this->exigir(config('session.http_only') !== false, 'SESSION_HTTP_ONLY no puede ser false.');
        foreach (['database.connections.pgsql.password' => 'DB_PASSWORD', 'database.connections.plataforma.password' => 'PLATAFORMA_DB_PASSWORD'] as $clave => $variable) {
            $valor = (string) config($clave);
            $this->exigir(! in_array(Str::lower($valor), self::CLAVES_DEBILES, true) && Str::length($valor) >= 16,
                "{$variable} debe tener al menos 16 caracteres y no ser una clave conocida.");
        }
        // Las cuentas e instituciones de prueba (db:seed en local) se configuran con estas variables. En producción los
        // seeders no hacen nada, pero si están puestas es que se copió .env.example: su contraseña es pública.
        foreach (['auth.administrador_inicial.email' => 'ADMIN_EMAIL', 'auth.administrador_inicial.password' => 'ADMIN_PASSWORD',
            'plataforma.administrador_prueba.email' => 'PLATAFORMA_ADMIN_EMAIL', 'plataforma.administrador_prueba.password' => 'PLATAFORMA_ADMIN_PASSWORD',
            'plataforma.institucion_prueba.slug' => 'PLATAFORMA_PRUEBA_SLUG', 'plataforma.institucion_prueba.nombre' => 'PLATAFORMA_PRUEBA_NOMBRE'] as $clave => $variable) {
            $this->exigir(trim((string) config($clave)) === '', "{$variable} debe quedar vacía: solo sirve para las cuentas de prueba en local y su contraseña es pública.");
        }
        if (config('plataforma.modo') === 'subdominio') {
            $dominio = (string) config('plataforma.dominio');
            $this->exigir($dominio !== '' && $dominio !== 'localhost' && ! str_ends_with($dominio, '.localhost'), 'PLATAFORMA_DOMINIO debe ser el dominio real (p. ej. escalafon.pe).');
            $this->exigir(in_array(config('session.domain'), [null, '', 'null'], true), 'SESSION_DOMAIN debe quedar vacío: la cookie de cada institución es solo de su subdominio.');
            $estado = (array) config('sanctum.stateful');
            $this->exigir(in_array($dominio, $estado, true) && in_array('*.'.$dominio, $estado, true),
                "SANCTUM_STATEFUL_DOMAINS debe incluir {$dominio} y *.{$dominio}.");
        } else {
            $this->avisar('PLATAFORMA_MODO=unica: una sola institución, sin panel de plataforma operativo.');
        }
        $this->exigir(trim((string) config('identidad.proveedor')) !== 'simulado',
            'IDENTIDAD_PROVEEDOR no puede ser simulado: sus datos son ficticios (en producción se bloquea igual). Usa ninguno hasta tener un proveedor oficial.');
        $this->exigir(trim((string) config('identidad.proveedor')) !== 'factiliza' || trim((string) config('identidad.factiliza.token')) !== '',
            'IDENTIDAD_PROVEEDOR=factiliza exige FACTILIZA_TOKEN: sin él la consulta por DNI no funciona. Usa ninguno o define el token.');
        if (trim((string) config('identidad.proveedor')) === 'factiliza') {
            $this->avisar('IDENTIDAD_PROVEEDOR=factiliza: el DNI consultado se envía a un servicio externo de pago, no oficial (no es RENIEC).');
        }
        $this->almacenamientoUnica();
        if (config('app.crud_generico_escritura')) {
            $this->avisar('API_CRUD_GENERICO_ESCRITURA=true: el CRUD genérico permite borrar y editar sin las reglas de cada sección (auditoría, historial, folios).');
        }
        if (! config('app.api_habilitada')) {
            $this->avisar('API_HABILITADA=false: la API responderá 403 a todas las peticiones.');
        }
        if (config('logging.channels.'.config('logging.default').'.level') === 'debug' || config('logging.channels.stderr.level') === 'debug') {
            $this->avisar('LOG_LEVEL=debug registra demasiado detalle en producción; usa warning o error.');
        }

        foreach ($this->avisos as $aviso) {
            $this->warn("Aviso: {$aviso}");
        }
        foreach ($this->errores as $error) {
            $this->error("Error: {$error}");
        }
        if ($this->errores !== []) {
            $this->error(count($this->errores).' error(es): la configuración no es segura para producción.');

            return self::FAILURE;
        }
        $this->info('Configuración de producción verificada.');

        return self::SUCCESS;
    }

    /**
     * PLATAFORMA_ALMACENAMIENTO_UNICA (AlmacenamientoUnica). Con subdominio se ignora: aviso si está puesta. En unica, con
     * valor debe corresponder a esta base y a una carpeta ya normalizada (si no, la aplicación ni siquiera arranca: aquí
     * se repite para que el motivo quede en este informe); vacía, aviso si la carpeta de esta base ya está normalizada.
     */
    private function almacenamientoUnica(): void
    {
        $valor = trim((string) config('plataforma.almacenamiento_unica'));
        if (config('plataforma.modo') !== 'unica') {
            if ($valor !== '') {
                $this->avisar('PLATAFORMA_ALMACENAMIENTO_UNICA solo se usa con PLATAFORMA_MODO=unica: con subdominio se ignora (cada institución usa su carpeta). Déjala vacía.');
            }

            return;
        }
        if ($valor !== '') {
            $motivo = AlmacenamientoUnica::motivo($valor);
            $this->exigir($motivo === null, (string) $motivo);

            return;
        }
        if ($carpeta = AlmacenamientoUnica::carpetaSinConfigurar()) {
            $this->avisar("La carpeta {$carpeta} de la institución de esta base ya es una carpeta real con archivos, pero PLATAFORMA_ALMACENAMIENTO_UNICA está vacía: "
                .'el modo unica sigue usando las carpetas comunes. Si se normalizó con plataforma:normalizar-almacenamiento, '
                ."añade PLATAFORMA_ALMACENAMIENTO_UNICA={$carpeta}; si sus archivos siguen en las carpetas comunes, no la añadas.");
        }
    }

    private function exigir(bool $cumple, string $mensaje): void
    {
        if (! $cumple) {
            $this->errores[] = $mensaje;
        }
    }

    private function avisar(string $mensaje): void
    {
        $this->avisos[] = $mensaje;
    }
}
