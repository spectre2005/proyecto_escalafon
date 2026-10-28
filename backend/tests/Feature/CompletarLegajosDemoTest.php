<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompletarLegajosDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_se_ejecuta_en_local_y_no_escribe_fuera_de_el(): void
    {
        Legajo::factory()->create();
        $antes = DocumentoUsuario::query()->count();

        $this->artisan('demo:completar-legajos', ['--aplicar' => true])
            ->expectsOutputToContain('Solo para demostración en local')
            ->assertFailed();

        $this->assertSame($antes, DocumentoUsuario::query()->count());
    }
}
