<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;

class GenerarContratoApi extends Command
{
    protected $signature = 'api:contrato {--check= : Compara con un inventario JSON existente}';

    protected $description = 'Emite un inventario determinista de rutas API, middleware y fuentes del contrato';

    public function handle(): int
    {
        $rutas = [];
        $fuentes = [];
        foreach (Route::getRoutes() as $ruta) {
            if (! str_starts_with($ruta->uri(), 'api/v1/')) {
                continue;
            }
            $accion = $ruta->getActionName();
            if (str_contains($accion, '@')) {
                [$clase, $metodo] = explode('@', $accion, 2);
                $reflexion = new ReflectionMethod($clase, $metodo);
                $archivo = $reflexion->getFileName();
                $fuentes[substr($archivo, strlen(base_path()) + 1)] = hash_file('sha256', $archivo);
            }
            $rutas[] = ['uri' => '/'.$ruta->uri(), 'methods' => array_values(array_diff($ruta->methods(), ['HEAD'])),
                'action' => $accion, 'middleware' => $ruta->gatherMiddleware(), 'constraints' => $ruta->wheres];
        }
        foreach (['app/Http', 'app/Support'] as $directorio) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directorio), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fuente) {
                if ($fuente->getExtension() === 'php') {
                    $archivo = $fuente->getPathname();
                    $fuentes[substr($archivo, strlen(base_path()) + 1)] = hash_file('sha256', $archivo);
                }
            }
        }
        foreach (['routes/api.php', 'config/permisos.php', 'config/antivirus.php', 'config/deployment.php', 'app/Models/Usuario.php'] as $archivo) {
            $fuentes[$archivo] = hash_file('sha256', base_path($archivo));
        }
        usort($rutas, fn (array $a, array $b): int => [$a['uri'], $a['methods']] <=> [$b['uri'], $b['methods']]);
        ksort($fuentes);
        $json = json_encode(['version' => 1, 'routes' => $rutas, 'validation_sources_sha256' => $fuentes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        if ($archivo = $this->option('check')) {
            if (! is_file($archivo) || file_get_contents($archivo) !== $json) {
                $this->error('El contrato cambió. Revisa rutas, permisos y validaciones y regenera el inventario.');

                return self::FAILURE;
            }
            $this->info('Contrato API sin divergencias.');

            return self::SUCCESS;
        }
        $this->output->write($json);

        return self::SUCCESS;
    }
}
