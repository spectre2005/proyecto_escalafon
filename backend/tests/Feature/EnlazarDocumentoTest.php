<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\PeriodoPrueba;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use App\Support\Documentos\Folios;
use Database\Seeders\Seccion01CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Enlazar más tarde un documento ya cargado que quedó sin registro (POST .../documentos/{d}/enlazar): mismas reglas que
 * el enlace al subir, una sola vez, con permisos, datos sensibles, legajo abierto, aplicabilidad y auditoría.
 */
class EnlazarDocumentoTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private VinculoLaboral $vinculo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDemo();
        $this->actuarComo();
        $this->persona = $this->personaDemo();
        $this->vinculo = VinculoLaboral::query()->where('usuario_id', $this->persona->id)->where('anulado', false)->whereNull('fecha_cese')->firstOrFail();
    }

    private function cargar(TipoDocumento $tipo, array $cambios = []): string
    {
        $legajo = Legajo::query()->where('usuario_id', $this->persona->id)->where('estado_legajo', 'activo')->firstOrFail();
        $folio = Folios::ultimo($legajo, $tipo->seccion_legajo_id) + 1;

        return $this->subir($this->persona, $this->metadatos($tipo, $folio, $folio, $cambios))->assertCreated()->json('data.id');
    }

    private function enlazar(string $documento, ?string $registro, ?Usuario $persona = null): TestResponse
    {
        return $this->postJson('/api/v1/expedientes/'.($persona ?? $this->persona)->id.'/documentos/'.$documento.'/enlazar', $registro === null ? [] : ['enlace_id' => $registro]);
    }

    private function sinRegistro(string $documento): bool
    {
        $tipos = collect($this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-02')->assertOk()->json('data.subsecciones'))->flatMap(fn (array $s): array => $s['tipos']);

        return (bool) $tipos->flatMap(fn (array $t): array => $t['lista'])->firstWhere('id', $documento)['sin_registro'];
    }

    private function adenda(): VinculoAdenda
    {
        return VinculoAdenda::query()->create(['vinculo_laboral_id' => $this->vinculo->id, 'numero' => 'AD-50', 'fecha_inicio' => '2026-02-01', 'fecha_fin' => '2026-07-31', 'registrado_por' => auth()->id()]);
    }

    public function test_an_unlinked_document_is_linked_once_to_a_compatible_record(): void
    {
        $documento = $this->cargar($this->tipo02('g'));
        $this->assertTrue($this->sinRegistro($documento));
        $adenda = $this->adenda();
        $total = DocumentoUsuario::query()->count();

        $this->enlazar($documento, $adenda->id)->assertOk()->assertJsonPath('data.registro_id', $adenda->id)->assertJsonPath('data.enlazado', true);
        $this->assertSame($documento, $adenda->refresh()->documento_id);
        $this->assertFalse($this->sinRegistro($documento));
        $evento = AuditoriaEvento::query()->where('entidad', 'documentos_usuario')->where('entidad_id', $documento)->where('accion', 'documento.enlazado')->sole();
        $this->assertSame($adenda->id, json_decode($evento->datos_despues, true)['registro_id']);

        // Ya enlazado: no se vuelve a enlazar, ni a otra adenda. No se crea ni se pierde ningún documento.
        $otra = $this->adenda();
        $this->enlazar($documento, $otra->id)->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->assertNull($otra->refresh()->documento_id);
        $this->assertSame($total, DocumentoUsuario::query()->count());
    }

    public function test_link_documents_are_grouped_with_the_link_once(): void
    {
        $tipo = collect(['c', 'd', 'e', 'f'])->map(fn (string $l): TipoDocumento => $this->tipo02($l))->firstOrFail(fn (TipoDocumento $t): bool => (bool) $t->permite_multiples);
        $documento = $this->cargar($tipo);
        $this->assertTrue($this->sinRegistro($documento));
        $this->enlazar($documento, $this->vinculo->id)->assertOk()->assertJsonPath('data.vinculo_laboral_id', $this->vinculo->id);
        $otro = VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'anulado' => false]);
        $this->enlazar($documento, $otro->id)->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->assertSame($this->vinculo->id, DocumentoUsuario::query()->findOrFail($documento)->vinculo_laboral_id);
    }

    public function test_records_of_another_person_or_another_form_are_rejected(): void
    {
        $documento = $this->cargar($this->tipo02('g'));
        $ajena = VinculoAdenda::query()->create(['vinculo_laboral_id' => VinculoLaboral::factory()->create()->id, 'numero' => 'X', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-02-01', 'registrado_por' => auth()->id()]);
        $this->enlazar($documento, $ajena->id)->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        // Un registro de otro formulario (el vínculo) no es una adenda.
        $this->enlazar($documento, $this->vinculo->id)->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        // Y el documento de otra persona no se enlaza desde este expediente.
        $otra = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $otra->id, 'estado_legajo' => 'activo']);
        $this->enlazar($documento, $this->adenda()->id, $otra)->assertNotFound();
        $this->enlazar($documento, null)->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->assertNull(DocumentoUsuario::query()->findOrFail($documento)->vinculo_laboral_id);
    }

    public function test_incompatible_types_and_documents_without_a_data_form_are_rejected(): void
    {
        $this->seed(Seccion01CatalogoSeeder::class);
        $this->persona->update(['tiene_derechohabientes' => true]);
        $documento = $this->cargar(TipoDocumento::query()->where('codigo', 'S01_J_DNI_HIJOS_MENORES')->firstOrFail(), ['relacion_titular' => 'hijo']);
        $conyuge = Familiar::factory()->create(['usuario_id' => $this->persona->id, 'parentesco' => 'conyuge', 'documento_id' => null, 'anulado' => false]);
        $hijo = Familiar::factory()->create(['usuario_id' => $this->persona->id, 'parentesco' => 'hijo', 'documento_id' => null, 'anulado' => false, 'fecha_nacimiento' => now()->subYears(5)->toDateString()]);
        // El DNI de un hijo no acredita a la cónyuge.
        $this->enlazar($documento, $conyuge->id)->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->enlazar($documento, $hijo->id)->assertOk();
        $this->assertSame($documento, $hijo->refresh()->documento_id);
        // Un tipo sin formulario de datos (2.1 a) no tiene registro que enlazar.
        $seleccion = $this->cargar($this->tipo02('a'));
        $this->enlazar($seleccion, $this->vinculo->id)->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
    }

    public function test_a_corrected_document_is_not_linked_and_its_correction_is(): void
    {
        $original = $this->cargar($this->tipo02('g'));
        $correccion = $this->cargar($this->tipo02('g'), ['subsana_a_documento_id' => $original]);
        $adenda = $this->adenda();
        $this->enlazar($original, $adenda->id)->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->enlazar($correccion, $adenda->id)->assertOk();
        $this->assertSame($correccion, $adenda->refresh()->documento_id);
    }

    public function test_it_needs_permission_sensitive_access_and_an_open_file(): void
    {
        $documento = $this->cargar($this->tipo02('g'));
        $adenda = $this->adenda();
        config(['permisos.roles.SOLO_VER' => ['legajo.ver']]);
        $this->actuarComo(null, 'SOLO_VER');
        $this->enlazar($documento, $adenda->id)->assertForbidden();
        $this->actuarComo();
        Legajo::query()->where('usuario_id', $this->persona->id)->update(['estado_legajo' => 'pasivo']);
        $this->enlazar($documento, $adenda->id)->assertConflict();
        $this->assertNull($adenda->refresh()->documento_id);
    }

    public function test_sensitive_sections_need_sensitive_access(): void
    {
        $this->seed(Seccion01CatalogoSeeder::class);
        $this->persona->update(['tiene_derechohabientes' => true]);
        $documento = $this->cargar(TipoDocumento::query()->where('codigo', 'S01_J_DNI_HIJOS_MENORES')->firstOrFail(), ['relacion_titular' => 'hijo']);
        $hijo = Familiar::factory()->create(['usuario_id' => $this->persona->id, 'parentesco' => 'hijo', 'documento_id' => null, 'anulado' => false, 'fecha_nacimiento' => now()->subYears(5)->toDateString()]);
        config(['permisos.roles.SUBE_SIN_SENSIBLES' => ['legajo.ver', 'documento.subir']]);
        $this->actuarComo(null, 'SUBE_SIN_SENSIBLES');
        $this->enlazar($documento, $hijo->id)->assertForbidden();
        $this->assertNull($hijo->refresh()->documento_id);
    }

    public function test_it_does_not_bypass_applicability(): void
    {
        $this->vinculo->update(['tiene_periodo_prueba' => true]);
        $documento = $this->cargar($this->tipo02('m'));
        $periodo = PeriodoPrueba::query()->create(['usuario_id' => $this->persona->id, 'vinculo_laboral_id' => $this->vinculo->id, 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-03-31', 'resultado' => 'pendiente', 'registrado_por' => auth()->id()]);
        VinculoLaboral::query()->where('usuario_id', $this->persona->id)->update(['tiene_periodo_prueba' => false]);
        $this->enlazar($documento, $periodo->id)->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->assertNull($periodo->refresh()->documento_id);
        // Con la condición de nuevo en Sí, el mismo documento se enlaza.
        VinculoLaboral::query()->where('usuario_id', $this->persona->id)->update(['tiene_periodo_prueba' => true]);
        $this->enlazar($documento, $periodo->id)->assertOk();
    }
}
