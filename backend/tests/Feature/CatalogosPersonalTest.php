<?php

namespace Tests\Feature;

use App\Models\RegimenNormativo;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use Database\Seeders\RegimenNormativoSeeder;
use Database\Seeders\UnidadOrganicaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogosPersonalTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogs_populate_editor_options_without_duplicates(): void
    {
        $this->actuarComo();
        $this->seed([UnidadOrganicaSeeder::class, RegimenNormativoSeeder::class]);
        $unitIds = UnidadOrganica::query()->orderBy('id')->pluck('id')->all();
        $regimeIds = RegimenNormativo::query()->orderBy('id')->pluck('id')->all();
        $this->seed([UnidadOrganicaSeeder::class, RegimenNormativoSeeder::class]);
        $this->assertSame($unitIds, UnidadOrganica::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($regimeIds, RegimenNormativo::query()->orderBy('id')->pluck('id')->all());
        $this->getJson('/api/v1/expediente-opciones')->assertOk()
            ->assertJsonCount(45, 'data.unidades')->assertJsonCount(5, 'data.regimenes');
    }

    public function test_catalog_seed_preserves_existing_unit_names_and_assignments(): void
    {
        $unit = UnidadOrganica::factory()->create(['codigo' => 'UNIQ-URH', 'nombre' => 'Unidad existente']);
        $person = Usuario::factory()->create(['unidad_organica_id' => $unit->id]);
        $this->seed(UnidadOrganicaSeeder::class);
        $this->assertSame('Unidad existente', $unit->refresh()->nombre);
        $this->assertSame($unit->id, $person->refresh()->unidad_organica_id);
        $this->assertDatabaseCount('unidades_organicas', 45);
    }

    public function test_editor_saves_options_from_the_seeded_catalog(): void
    {
        $this->actuarComo();
        $this->seed([UnidadOrganicaSeeder::class, RegimenNormativoSeeder::class]);
        $person = Usuario::factory()->create();
        $unit = UnidadOrganica::query()->where('codigo', 'UNIQ-URH')->firstOrFail();
        $regime = RegimenNormativo::query()->where('codigo', '276')->firstOrFail();
        $this->patchJson('/api/v1/expedientes/'.$person->id, [
            'unidad_organica_id' => $unit->id,
            'regimen_normativo_id' => $regime->id,
        ])->assertOk();
        $this->assertDatabaseHas('usuarios', [
            'id' => $person->id,
            'unidad_organica_id' => $unit->id,
            'regimen_normativo_id' => $regime->id,
        ]);
    }
}
