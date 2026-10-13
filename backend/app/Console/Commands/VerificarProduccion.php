<?php

namespace App\Console\Commands;

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
