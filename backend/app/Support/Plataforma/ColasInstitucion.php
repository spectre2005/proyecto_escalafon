<?php

namespace App\Support\Plataforma;

use App\Models\Plataforma\Institucion;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Colas con varias instituciones (PLATAFORMA_MODO=subdominio). Un trabajo encolado durante una petición institucional
 * lleva el id de su institución, y el worker lo ejecuta con la base, los archivos y la caché de esa institución
 * (ConexionInstitucion). Si al procesarlo la institución ya no opera (no activa o vencida), el trabajo falla sin
 * ejecutarse.
 *
 * Los trabajos y sus fallos se guardan en la base de la plataforma (database/migrations/plataforma), no en la de la
 * institución: un solo worker los ve todos.
 */
final class ColasInstitucion
{
    /** Estado anterior del proceso mientras corre un trabajo de una institución. */
    private static ?array $antes = null;

    /** Con subdominio, la cola «database», sus lotes y sus fallos van a la base de la plataforma. */
    public static function configurar(): void
    {
        if (config('plataforma.modo') === 'subdominio') {
            config(['queue.connections.database.connection' => 'plataforma', 'queue.batching.database' => 'plataforma', 'queue.failed.database' => 'plataforma']);
        }
    }

    public static function registrar(): void
    {
        Queue::createPayloadUsing(fn (): array => app()->bound(Institucion::class) ? ['institucion_id' => app(Institucion::class)->id] : []);
        Queue::before(fn ($evento) => self::entrar($evento->job));
        Queue::after(fn () => self::salir());
        Queue::exceptionOccurred(fn () => self::salir());
        Queue::failing(fn () => self::salir());
    }

    private static function entrar(Job $trabajo): void
    {
        $id = $trabajo->payload()['institucion_id'] ?? null;
        if ($id === null) {
            return;
        }
        $institucion = Institucion::query()->find($id);
        if (! $institucion || ! $institucion->operativa()) {
            // fail() lo marca como fallido y lo borra: el worker ya no lo ejecuta.
            $trabajo->fail(new RuntimeException('La institución del trabajo no está activa o su tiempo de uso venció: no se ejecuta.'));

            return;
        }
        self::$antes = ConexionInstitucion::entrar($institucion);
    }

    private static function salir(): void
    {
        if (self::$antes !== null) {
            ConexionInstitucion::salir(self::$antes);
            self::$antes = null;
        }
    }
}
