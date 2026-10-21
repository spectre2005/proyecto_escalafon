<?php

namespace Tests\Feature;

use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\AplicacionDocumental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Etapa B: solo se ofrecen y se cargan los tipos de documento que corresponden a la persona (docente o administrativo y
 * régimen). Si no se puede saber, se ofrece todo; subsanar un documento existente se permite siempre.
 */
class AplicacionDocumentalTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private function tipo(string $codigo): TipoDocumento
    {
        return TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->firstOrFail();
    }

    private function vinculo(Usuario $persona, array $datos): VinculoLaboral
    {
        return VinculoLaboral::factory()->create(['usuario_id' => $persona->id, ...$datos]);
    }

    public function test_staff_type_comes_from_all_links_or_from_the_person(): void
    {
        $tipo = $this->tipo('FORM_CAPACITACION');
        $tipo->update(['aplica_tipo_personal' => 'docente']);

        // Sin vínculos: se usan los datos de la persona (por ejemplo, del Excel de personal; usuarios.tipo_personal es obligatorio).
        $persona = Usuario::factory()->create(['tipo_personal' => 'administrativo']);
        $this->assertSame('Solo para personal docente.', AplicacionDocumental::para($persona)->noAplica($tipo));
        $this->assertNull(AplicacionDocumental::para(Usuario::factory()->create(['tipo_personal' => 'docente']))->noAplica($tipo));

        // Con vínculos manda el vínculo; un vínculo docente cesado también cuenta (el legajo guarda la historia).
        $this->vinculo($persona, ['tipo_personal' => 'administrativo', 'regimen_laboral' => 'carrera']);
        $this->assertSame('Solo para personal docente.', AplicacionDocumental::para($persona)->noAplica($tipo));
        $this->vinculo($persona, ['tipo_personal' => 'docente', 'regimen_laboral' => 'carrera', 'fecha_cese' => today()->subYear()]);
        $this->assertNull(AplicacionDocumental::para($persona)->noAplica($tipo));
        // Un vínculo anulado no cuenta.
        $otra = Usuario::factory()->create(['tipo_personal' => 'administrativo']);
        $this->vinculo($otra, ['tipo_personal' => 'docente', 'regimen_laboral' => 'carrera', 'anulado' => true]);
        $this->assertSame('Solo para personal docente.', AplicacionDocumental::para($otra)->noAplica($tipo));

        $this->assertSame('docente', $this->tipo('S02_RESOLUCION_DOCENTE')->aplica_tipo_personal);
        // Los tipos generales se ofrecen a cualquiera; la resolución docente pertenece al perfil docente.
        $this->assertSame([], array_filter(TipoDocumento::query()->where('activo', true)->pluck('aplica_tipo_personal', 'codigo')->all(),
            fn (string $valor, string $codigo): bool => $valor !== 'ambos' && ! in_array($codigo, ['FORM_CAPACITACION', 'S02_RESOLUCION_DOCENTE'], true), ARRAY_FILTER_USE_BOTH));
    }

    public function test_regime_filters_only_when_every_regime_is_known(): void
    {
        $designacion = $this->tipo('S11_A_TERMINO_DESIGNACION');
        $this->assertSame([false, false, true], [$designacion->aplica_carrera, $designacion->aplica_plazo_determinado, $designacion->aplica_designado]);
        $persona = Usuario::factory()->create();

        $this->vinculo($persona, ['tipo_personal' => 'administrativo', 'regimen_laboral' => 'carrera']);
        $this->assertSame('No corresponde a su régimen laboral.', AplicacionDocumental::para($persona)->noAplica($designacion));
        $this->assertNull(AplicacionDocumental::para($persona)->noAplica($this->tipo('S11_B_TERMINO_CARRERA')));

        // Un régimen que el sistema no clasifica (por ejemplo, 276): no se filtra.
        $this->vinculo($persona, ['tipo_personal' => 'administrativo', 'regimen_laboral' => '276']);
        $this->assertNull(AplicacionDocumental::para($persona)->noAplica($designacion));

        // Un vínculo designado anterior hace que el término de designación corresponda.
        $otra = Usuario::factory()->create();
        $this->vinculo($otra, ['tipo_personal' => 'administrativo', 'regimen_laboral' => 'carrera']);
        $this->vinculo($otra, ['tipo_personal' => 'administrativo', 'regimen_laboral' => 'designado', 'fecha_cese' => today()->subYear()]);
        $this->assertNull(AplicacionDocumental::para($otra)->noAplica($designacion));
    }

    public function test_upload_rejects_types_that_do_not_apply_but_allows_correcting_existing_documents(): void
    {
        $this->prepararDemo();
        $this->actuarComo();
        $persona = $this->personaDemo();
        $tiposPersonal = $persona->vinculosLaborales()->where('anulado', false)->pluck('tipo_personal')->unique()->values()->all();
        $this->assertSame(1, count($tiposPersonal));
        $otro = $tiposPersonal[0] === 'docente' ? 'administrativo' : 'docente';
        $tipo = $this->tipo02('a');

        $id = $this->subir($persona, $this->metadatos($tipo, 1, 1))->assertCreated()->json('data.id');
        $tipo->update(['aplica_tipo_personal' => $otro]);
        $this->subir($persona, $this->metadatos($tipo, 2, 2))->assertUnprocessable()
            ->assertJsonValidationErrors(['tipo_documento_id' => "Este tipo de documento no corresponde a la persona. Solo para personal {$otro}."]);
        // Subsanar el documento que ya está en el legajo se permite.
        $this->subir($persona, $this->metadatos($tipo, 2, 2, ['subsana_a_documento_id' => $id]))->assertCreated();

        // El frontend recibe los tipos que no corresponden, con su motivo.
        $lista = $this->getJson('/api/v1/expedientes/'.$persona->id.'/documentos/no-aplicables')->assertOk()->json('data');
        $this->assertContains(['id' => $tipo->id, 'codigo' => $tipo->codigo, 'motivo' => "Solo para personal {$otro}."], $lista);
        $this->actuarComo(null, 'sin_permisos_legajo');
        $this->getJson('/api/v1/expedientes/'.$persona->id.'/documentos/no-aplicables')->assertForbidden();
    }
}
