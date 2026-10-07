<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentCatalogMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function catalogMigration(): Migration
    {
        return require database_path('migrations/2026_10_07_122355_ensure_document_catalogs_for_remaining_legajo_sections.php');
    }

    public function test_migrations_alone_load_every_implemented_section_without_demo_documents(): void
    {
        foreach (range(1, 11) as $section) {
            $this->assertTrue(TipoDocumento::query()->where('seccion_legajo_id', $section)->where('activo', true)->exists(), "Missing catalog for section {$section}.");
        }
        $this->assertSame(0, DocumentoUsuario::query()->count());
        $this->assertDatabaseHas('tipos_documento', [
            'codigo' => 'S01_I_DNI_CONYUGE_CONCUBINO', 'formulario_datos' => 'familiares', 'activo' => true,
        ]);
        $foreignKeys = collect(Schema::getForeignKeys('documentos_usuario'));
        foreach (['usuario_id' => 'usuarios', 'tipo_documento_id' => 'tipos_documento', 'legajo_id' => 'legajos'] as $column => $table) {
            $this->assertTrue($foreignKeys->contains(fn (array $key): bool => $key['columns'] === [$column] && $key['foreign_table'] === $table));
        }
    }

    public function test_catalog_migration_can_be_repeated_without_changing_existing_document_relationships(): void
    {
        $legajo = Legajo::factory()->create();
        $documents = [];
        foreach ([1, 2, 5, 7, 9, 11] as $section) {
            $type = TipoDocumento::query()->where('seccion_legajo_id', $section)->where('activo', true)->firstOrFail();
            $documents[] = DocumentoUsuario::factory()->create([
                'usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id,
                'seccion_legajo_id' => $section, 'tipo_documento_id' => $type->id,
            ])->fresh();
        }
        $types = TipoDocumento::query()->pluck('id')->sort()->values()->all();
        $migration = $this->catalogMigration();
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($types, TipoDocumento::query()->pluck('id')->sort()->values()->all());
        foreach ($documents as $document) {
            $original = $document->getAttributes();
            $document->refresh();
            $this->assertSame($original, $document->getAttributes());
            $this->assertSame($legajo->usuario_id, $document->usuario->id);
            $this->assertTrue($document->tipoDocumento->activo);
        }
    }

    public function test_database_rejects_a_document_linked_to_a_missing_user(): void
    {
        $type = TipoDocumento::query()->where('seccion_legajo_id', 1)->where('activo', true)->firstOrFail();
        $legajo = Legajo::factory()->create();
        $this->expectException(QueryException::class);
        DocumentoUsuario::factory()->create([
            'usuario_id' => (string) Str::uuid(), 'legajo_id' => $legajo->id,
            'tipo_documento_id' => $type->id, 'seccion_legajo_id' => 1,
        ]);
    }
}
