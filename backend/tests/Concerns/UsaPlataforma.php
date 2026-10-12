<?php

namespace Tests\Concerns;

use App\Models\Plataforma\AdministradorPlataforma;
use App\Models\Plataforma\Institucion;

/**
 * Base de plataforma para las pruebas: SQLite en memoria propia (conexión «plataforma», phpunit.xml), distinta de la
 * institucional. Se recrea en cada prueba con sus migraciones (database/migrations/plataforma).
 */
trait UsaPlataforma
{
    protected function setUpUsaPlataforma(): void
    {
        $this->artisan('migrate:fresh', ['--database' => 'plataforma', '--path' => 'database/migrations/plataforma'])->assertSuccessful();
        // Igual que AuthenticationTest: el contenedor fija CACHE_STORE=file y phpunit.xml no lo impone; el limitador
        // de intentos va en memoria para cada prueba (sigue activo). Las peticiones llegan como el SPA (dominio stateful).
        config(['sanctum.stateful' => ['localhost:5173'], 'cache.limiter' => 'array']);
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    protected function administrador(array $datos = []): AdministradorPlataforma
    {
        return AdministradorPlataforma::query()->create(['nombre' => 'Superadministración', 'email' => 'super@example.test', 'password' => 'Clave-segura-2026', 'activo' => true, ...$datos]);
    }

    protected function institucion(array $datos = []): Institucion
    {
        $slug = $datos['slug'] ?? 'entidad-'.substr(md5((string) microtime(true)), 0, 6);

        return Institucion::query()->create(['nombre' => 'Entidad de prueba', 'slug' => $slug, 'estado' => Institucion::REGISTRADA,
            'base_datos' => 'escalafon_'.str_replace('-', '_', $slug), 'almacenamiento' => 'instituciones/'.$slug, ...$datos]);
    }
}
