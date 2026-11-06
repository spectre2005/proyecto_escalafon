<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\NotificacionUsuario;
use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use Database\Seeders\CatalogosInstitucionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SistemaDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_rechaza_carga_fuera_de_local(): void
    {
        $this->artisan('demo:poblar-sistema', ['--aplicar' => true])->expectsOutputToContain('Solo para demostración en local')->assertFailed();
        $this->assertDatabaseCount('usuarios', 0);
    }

    public function test_plan_no_escribe(): void
    {
        app()->detectEnvironment(fn (): string => 'local');
        config(['plataforma.modo' => 'unica']);
        $this->artisan('demo:poblar-sistema')->expectsOutputToContain('Sin cambios')->assertSuccessful();
        $this->assertDatabaseCount('usuarios', 0);
    }

    public function test_carga_es_repetible_con_pdf_reales_y_sin_envios_ni_cambios_a_otras_personas(): void
    {
        app()->detectEnvironment(fn (): string => 'local');
        config(['plataforma.modo' => 'unica']);
        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake(config('legajo.disco_documentos'));
        $this->seed(CatalogosInstitucionSeeder::class);
        $admin = $this->actuarComo();
        $otra = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $otra->id]);
        $antes = $otra->fresh()->getAttributes();

        $this->artisan('demo:poblar-sistema', ['--aplicar' => true])->assertSuccessful();
        $this->assertSame($antes, $otra->fresh()->getAttributes());
        $this->assertSame(0, DocumentoUsuario::query()->where('usuario_id', $otra->id)->count());
        $this->assertSame(8, Usuario::query()->where('email_institucional', 'like', 'integral.%@example.test')->count());
        $this->assertSame(32, RequerimientoDocumento::query()->count());
        $this->assertSame(32, NotificacionUsuario::query()->count());
        $this->assertSame(range(1, 13), DocumentoUsuario::query()->distinct()->orderBy('seccion_legajo_id')->pluck('seccion_legajo_id')->all());
        $this->assertSame(8, RequerimientoDocumento::query()->where('estado', 'aceptado')->count());
        foreach (RequerimientoDocumento::query()->where('estado', 'aceptado')->with('documento.archivo')->get() as $requerimiento) {
            $respuesta = collect($requerimiento->seguimiento)->firstWhere('accion', 'respuesta');
            $this->assertSame($requerimiento->documento->archivo->hash_sha256, $respuesta['archivos'][0]['hash']);
        }
        foreach (ArchivoDocumento::all() as $archivo) {
            $bytes = Storage::disk($archivo->almacenamiento)->get($archivo->storage_path);
            $this->assertStringStartsWith('%PDF-1.4', $bytes);
            $this->assertSame(hash('sha256', $bytes), $archivo->hash_sha256);
            $this->assertSame(1, $archivo->num_paginas);
        }
        $this->assertCount(56, Storage::disk(config('legajo.disco_documentos'))->files('demo-integral/correos'));
        $cuentas = [Usuario::count(), DocumentoUsuario::count(), ArchivoDocumento::count(), RequerimientoDocumento::count()];
        $this->artisan('demo:poblar-sistema', ['--aplicar' => true])->assertSuccessful();
        $this->assertSame($cuentas, [Usuario::count(), DocumentoUsuario::count(), ArchivoDocumento::count(), RequerimientoDocumento::count()]);
        $this->assertSame($admin->id, auth()->id());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
