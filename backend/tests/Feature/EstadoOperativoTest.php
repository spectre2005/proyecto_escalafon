<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EstadoOperativoTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_checks_database_storage_and_fresh_heartbeat_without_leaving_files(): void
    {
        Storage::fake('documentos');
        Cache::put('scheduler.heartbeat', now()->timestamp, 300);
        $this->artisan('sistema:estado')->expectsOutputToContain('"scheduler":true')->assertSuccessful()->execute();
        $this->assertSame([], Storage::disk('documentos')->allFiles());
    }

    public function test_stale_heartbeat_and_incomplete_production_configuration_fail(): void
    {
        Storage::fake('documentos');
        Cache::put('scheduler.heartbeat', now()->subMinutes(4)->timestamp, 300);
        $this->artisan('sistema:estado')->expectsOutputToContain('"scheduler":false')->assertFailed()->execute();
        $this->artisan('sistema:estado', ['--produccion' => true])->assertFailed()->execute();
    }
}
