<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Support\RelacionTitular;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class RelacionTitularTest extends TestCase
{
    use RefreshDatabase, SubeDocumentos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDemo();
        $this->actuarComo();
    }

    public function test_acepta_las_siete_relaciones_y_guarda_el_detalle_de_otro(): void
    {
        $tipo = $this->tipo02('d');
        $tipo->update(['permite_multiples' => true]);
        foreach (RelacionTitular::OPCIONES as $indice => $relacion) {
            $id = $this->subir($this->personaDemo(), $this->metadatos($tipo, $indice * 2 + 1, $indice * 2 + 2, [
                'relacion_titular' => $relacion,
                'relacion_titular_otro' => $relacion === 'otro' ? '  Abuelo  ' : null,
            ]))->assertCreated()->json('data.id');
            $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'relacion_titular' => $relacion,
                'relacion_titular_otro' => $relacion === 'otro' ? 'Abuelo' : null]);
        }
    }

    public function test_otro_requiere_texto_y_rechaza_detalle_en_otras_relaciones(): void
    {
        foreach (['', '   ', str_repeat('a', 151)] as $detalle) {
            $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 2,
                ['relacion_titular' => 'otro', 'relacion_titular_otro' => $detalle]))
                ->assertUnprocessable()->assertJsonValidationErrors('relacion_titular_otro');
        }
        $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 2,
            ['relacion_titular' => 'servidor', 'relacion_titular_otro' => 'Abuelo']))
            ->assertUnprocessable()->assertJsonValidationErrors('relacion_titular_otro');
        $this->assertDatabaseCount('documentos_usuario', 0);
    }

    public function test_conserva_y_lee_el_valor_heredado_pero_no_lo_admite_en_nuevas_cargas(): void
    {
        $id = $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 2))->assertCreated()->json('data.id');
        DocumentoUsuario::query()->findOrFail($id)->update(['relacion_titular' => 'conyuge_concubino']);
        $data = $this->getJson('/api/v1/expedientes/'.$this->personaDemo()->id.'/seccion-02')->assertOk()->json('data');
        $lista = collect($data['subsecciones'])->flatMap(fn (array $seccion): array => $seccion['tipos'])->flatMap(fn (array $tipo): array => $tipo['lista']);
        $this->assertSame('conyuge_concubino', $lista->firstWhere('id', $id)['values']['relacion_titular']);
        $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('d'), 3, 4, ['relacion_titular' => 'conyuge_concubino']))
            ->assertUnprocessable()->assertJsonValidationErrors('relacion_titular');
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'relacion_titular' => 'conyuge_concubino']);
    }

    public function test_la_migracion_es_reversible_y_conserva_los_documentos(): void
    {
        $id = $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 2))->assertCreated()->json('data.id');
        $migration = require database_path('migrations/2026_10_07_071417_add_relacion_titular_otro_to_documentos_usuario_table.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'relacion_titular' => 'servidor', 'relacion_titular_otro' => null]);
    }
}
