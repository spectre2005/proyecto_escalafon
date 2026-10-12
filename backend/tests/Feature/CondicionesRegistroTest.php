<?php

namespace Tests\Feature;

use App\Models\Colegiatura;
use App\Models\DeclaracionVerificacion;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\Documentos\Folios;
use Database\Seeders\Seccion01CatalogoSeeder;
use Database\Seeders\Seccion02CatalogoSeeder;
use Database\Seeders\Seccion03CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Condiciones del perfil en sus tres estados para los registros que controlan (CondicionesRegistro) y las entradas del
 * menú que ocultan (modulos_ocultos): Sí permite, No rechaza lo nuevo y conserva lo existente, Sin responder (null) no
 * se interpreta como Sí ni como No y se permite.
 */
class CondicionesRegistroTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Seccion01CatalogoSeeder::class);
        $this->seed(Seccion02CatalogoSeeder::class);
        $this->seed(Seccion03CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create(['tiene_derechohabientes' => null, 'con_discapacidad' => null]);
        Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo']);
        $this->url = '/api/v1/expedientes/'.$this->persona->id;
        $this->actuarComo();
    }

    private function vinculo(array $condiciones): VinculoLaboral
    {
        return VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'anulado' => false, 'fecha_cese' => null, 'fecha_inicio' => '2026-01-01', ...$condiciones]);
    }

    private function ocultos(): array
    {
        return $this->getJson($this->url)->assertOk()->json('data.condiciones.modulos_ocultos');
    }

    public function test_derechohabiente_family_members_follow_the_flag(): void
    {
        $familiar = fn (bool $derechohabiente, string $nombre) => $this->postJson($this->url.'/familiares', ['apellidos_nombres' => $nombre, 'parentesco' => 'hijo', 'es_derechohabiente' => $derechohabiente]);
        // Sin responder: se permite (no es No).
        $familiar(true, 'Hija Uno')->assertCreated();
        $this->persona->update(['tiene_derechohabientes' => true]);
        $familiar(true, 'Hija Dos')->assertCreated();
        $this->persona->update(['tiene_derechohabientes' => false]);
        $familiar(true, 'Hija Tres')->assertUnprocessable()->assertJsonValidationErrors('es_derechohabiente');
        $familiar(false, 'Padre')->assertCreated();
        // Lo existente se conserva y se corrige; un familiar no derechohabiente no pasa a serlo.
        $existente = Familiar::query()->where('apellidos_nombres', 'Hija Dos')->sole();
        $this->patchJson($this->url.'/familiares/'.$existente->id, ['apellidos_nombres' => 'Hija Dos Corregida'])->assertOk();
        $padre = Familiar::query()->where('apellidos_nombres', 'Padre')->sole();
        $this->patchJson($this->url.'/familiares/'.$padre->id, ['es_derechohabiente' => true])->assertUnprocessable()->assertJsonValidationErrors('es_derechohabiente');
        // CRUD de administración: misma regla.
        $this->postJson('/api/v1/familiares', ['usuario_id' => $this->persona->id, 'parentesco' => 'hijo', 'apellidos_nombres' => 'Hija Cuatro', 'es_derechohabiente' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('es_derechohabiente');
        $this->assertSame(3, Familiar::query()->where('usuario_id', $this->persona->id)->count());
    }

    public function test_disability_certificates_follow_the_flag(): void
    {
        $certificado = fn (string $numero) => $this->postJson($this->url.'/certificados-discapacidad', ['persona' => 'servidor', 'numero_conadis' => $numero]);
        $certificado('C-1')->assertCreated();
        $this->persona->update(['con_discapacidad' => true]);
        $certificado('C-2')->assertCreated();
        $this->persona->update(['con_discapacidad' => false]);
        $certificado('C-3')->assertUnprocessable()->assertJsonValidationErrors('persona');
        $this->postJson('/api/v1/certificados-discapacidad', ['usuario_id' => $this->persona->id, 'persona' => 'servidor', 'numero_conadis' => 'C-4'])
            ->assertUnprocessable()->assertJsonValidationErrors('persona');
    }

    public function test_the_asset_declaration_follows_the_job_condition(): void
    {
        $declaracion = fn (string $tipo) => $this->postJson($this->url.'/declaraciones-verificaciones', ['tipo' => $tipo, 'fecha' => '2026-01-10', 'resultado' => 'sin_registros']);
        $vinculo = $this->vinculo(['obligado_djbr' => null]);
        $declaracion('djbr')->assertCreated();
        $vinculo->update(['obligado_djbr' => true]);
        $declaracion('djbr')->assertCreated();
        $vinculo->update(['obligado_djbr' => false]);
        $declaracion('djbr')->assertUnprocessable()->assertJsonValidationErrors('tipo');
        // Otras declaraciones no dependen de esta condición; una DJBR existente se sigue corrigiendo.
        $otra = $declaracion('antecedente_penal')->assertCreated()->json('data.id');
        $djbr = DeclaracionVerificacion::query()->where('usuario_id', $this->persona->id)->where('tipo', 'djbr')->firstOrFail();
        $this->patchJson($this->url.'/declaraciones-verificaciones/'.$djbr->id, ['tipo' => 'djbr', 'fecha' => '2026-02-01', 'resultado' => 'sin_registros'])->assertOk();
        $this->patchJson($this->url.'/declaraciones-verificaciones/'.$otra, ['tipo' => 'djbr', 'fecha' => '2026-01-10', 'resultado' => 'sin_registros'])->assertUnprocessable()->assertJsonValidationErrors('tipo');
    }

    public function test_probation_periods_and_their_subsection_follow_the_job_condition(): void
    {
        $vinculo = $this->vinculo(['tiene_periodo_prueba' => null]);
        $periodo = fn () => $this->postJson($this->url.'/periodos-prueba', ['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-03-31']);
        // Sin responder: la 2.4 se ve (por definir) y se puede registrar.
        $this->assertNotContains('s02-prueba', $this->ocultos());
        $this->getJson($this->url.'/seccion-02')->assertOk()->assertJsonPath('data.tiene_periodo_prueba', null);
        // No y sin datos: se oculta y se rechaza lo nuevo.
        $vinculo->update(['tiene_periodo_prueba' => false]);
        $this->assertContains('s02-prueba', $this->ocultos());
        $periodo()->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');
        // Sí: se ve y se registra.
        $vinculo->update(['tiene_periodo_prueba' => true]);
        $this->assertNotContains('s02-prueba', $this->ocultos());
        $periodo()->assertCreated();
        // De vuelta a No con un período registrado: la 2.4 sigue visible (lo registrado no se esconde), sin registros nuevos.
        $vinculo->update(['tiene_periodo_prueba' => false]);
        $this->assertNotContains('s02-prueba', $this->ocultos());
        $periodo()->assertUnprocessable();
    }

    public function test_the_probation_document_m_is_rejected_with_no_but_can_be_corrected(): void
    {
        Storage::fake((string) config('legajo.disco_documentos'));
        $vinculo = $this->vinculo(['tiene_periodo_prueba' => true]);
        $tipo = TipoDocumento::query()->where('codigo', 'S02_M_EVALUACION_PERIODO_PRUEBA')->firstOrFail();
        $subir = function (array $cambios = []) use ($tipo) {
            $folio = Folios::ultimo(Legajo::query()->where('usuario_id', $this->persona->id)->firstOrFail(), 2) + 1;

            return $this->subir($this->persona, $this->metadatos($tipo, $folio, $folio, $cambios));
        };
        $original = $subir()->assertCreated()->json('data.id');
        $vinculo->update(['tiene_periodo_prueba' => false]);
        $subir()->assertUnprocessable()->assertJsonValidationErrors('tipo_documento_id');
        $subir(['subsana_a_documento_id' => $original])->assertCreated();
    }

    public function test_colleges_and_basic_studies_follow_the_job_conditions(): void
    {
        $vinculo = $this->vinculo(['exige_colegiatura' => null, 'exige_estudios_basicos' => null]);
        $administrador = auth()->id();
        $colegiatura = fn (string $numero) => $this->postJson('/api/v1/colegiaturas', ['usuario_id' => $this->persona->id, 'colegio_profesional' => 'Colegio', 'numero_colegiatura' => $numero, 'registrado_por' => $administrador]);
        $formacion = fn (string $nivel) => $this->postJson('/api/v1/formacion-academica', ['usuario_id' => $this->persona->id, 'nivel' => $nivel, 'denominacion' => 'Estudios', 'institucion' => 'Colegio', 'registrado_por' => $administrador]);
        $this->assertNotContains('colegiaturas', $this->ocultos());
        $vinculo->update(['exige_colegiatura' => false, 'exige_estudios_basicos' => false]);
        // No y sin colegiaturas: la entrada se oculta y no se crea una nueva.
        $this->assertContains('colegiaturas', $this->ocultos());
        $colegiatura('CAL-1')->assertUnprocessable()->assertJsonValidationErrors('numero_colegiatura');
        $formacion('educacion_basica')->assertUnprocessable()->assertJsonValidationErrors('nivel');
        $formacion('bachiller')->assertCreated();
        // Sí: se permite; con una colegiatura registrada la entrada se ve aunque vuelva a No.
        $vinculo->update(['exige_colegiatura' => true, 'exige_estudios_basicos' => true]);
        $colegiatura('CAL-2')->assertCreated();
        $formacion('educacion_basica')->assertCreated();
        $vinculo->update(['exige_colegiatura' => false]);
        $this->assertNotContains('colegiaturas', $this->ocultos());
        $this->assertSame(1, Colegiatura::query()->where('usuario_id', $this->persona->id)->count());
    }

    public function test_job_condition_entries_are_hidden_for_roles_without_sensitive_data_too(): void
    {
        $this->vinculo(['tiene_periodo_prueba' => false, 'exige_colegiatura' => false]);
        $this->actuarComo(null, 'asistente_legajo');
        $this->assertSame(['s02-prueba', 'colegiaturas'], $this->ocultos());
    }
}
