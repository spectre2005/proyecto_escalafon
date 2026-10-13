<?php

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Trabajo de prueba: anota con qué base, carpeta de PDF y prefijo de caché se ejecutó (ColasInstitucionTest). */
class TrabajoQueAnotaSuEntorno implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<array{base: string, documentos: string, cache: string}> */
    public static array $ejecuciones = [];

    public function handle(): void
    {
        self::$ejecuciones[] = [
            'base' => (string) DB::connection()->getDatabaseName(),
            'documentos' => Storage::disk((string) config('legajo.disco_documentos'))->path(''),
            'cache' => (string) config('cache.prefix'),
        ];
    }
}
