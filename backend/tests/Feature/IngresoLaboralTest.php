<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\PerfilAdministrativo;
use App\Models\PerfilDocente;
use App\Models\ProcesoSeleccion;
use App\Models\RegimenNormativo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use App\Support\AplicacionDocumental;
use App\Support\ConfiguracionFuncionalidades;
use App\Support\PerfilDocumental;
use Database\Seeders\Seccion02CatalogoSeeder;
use Database\Seeders\Seccion11CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class IngresoLaboralTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $persona;

    private string $ley;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actuarComo();
        $this->persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $this->persona->id]);
        $this->ley = RegimenNormativo::query()->where('codigo', '1057')->value('id');
    }

    private function url(?string $vinculo = null): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/vinculos-laborales'.($vinculo ? '/'.$vinculo : '');
    }

    private function datos(array $cambios = []): array
    {
        return [...[
            'tipo_personal' => 'administrativo', 'regimen_normativo_id' => $this->ley,
            'regimen_laboral' => 'plazo_indeterminado', 'modalidad_ingreso' => 'concurso_publico',
            'nombre_puesto' => 'Analista', 'fecha_inicio' => '2026-01-01',
            'proceso_seleccion' => 'CAS-001-2026', 'anio_proceso' => 2026,
            'numero_contrato' => 'C-001-2026', 'fecha_contrato' => '2025-12-30',
        ], ...$cambios];
    }

    public function test_cas_requires_contract_but_not_a_resolution_and_supports_indefinite_duration(): void
    {
        $this->postJson($this->url(), $this->datos(['numero_contrato' => null, 'fecha_contrato' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['numero_contrato', 'fecha_contrato']);
        $this->postJson($this->url(), $this->datos(['fecha_fin_prevista' => '2026-12-31']))
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_fin_prevista');
        $this->postJson($this->url(), $this->datos(['regimen_laboral' => 'carrera']))
            ->assertUnprocessable()->assertJsonValidationErrors('regimen_laboral');
        $this->postJson($this->url(), $this->datos())->assertCreated()->assertJsonPath('data.regimen_laboral', 'plazo_indeterminado');
        $this->assertDatabaseHas('vinculos_laborales', ['usuario_id' => $this->persona->id, 'numero_resolucion' => null, 'fecha_fin_prevista' => null]);
    }

    public function test_confidence_needs_resolution_and_contract_and_cannot_carry_a_contest(): void
    {
        $confianza = $this->datos(['modalidad_ingreso' => 'designacion_confianza', 'regimen_laboral' => 'designado', 'proceso_seleccion' => null, 'anio_proceso' => null]);
        $this->postJson($this->url(), $confianza)->assertUnprocessable()->assertJsonValidationErrors(['numero_resolucion', 'fecha_resolucion']);
        $confianza = [...$confianza, 'numero_resolucion' => 'R-001', 'fecha_resolucion' => '2025-12-29'];
        $this->postJson($this->url(), [...$confianza, 'proceso_seleccion' => 'CAS-001-2026'])->assertUnprocessable()->assertJsonValidationErrors('proceso_seleccion');
        $id = $this->postJson($this->url(), $confianza)->assertCreated()->json('data.id');
        $this->assertDatabaseCount('procesos_seleccion', 0);
        $this->putJson($this->url($id).'/datos-puesto', ['es_cargo_confianza' => false])->assertUnprocessable()->assertJsonValidationErrors('es_cargo_confianza');
        $this->putJson($this->url($id).'/datos-puesto', ['es_cargo_confianza' => true])->assertCreated();
    }

    public function test_contest_is_shared_and_selected_only_with_the_matching_law(): void
    {
        $first = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.proceso_seleccion_id');
        $this->postJson($this->url(), $this->datos(['proceso_seleccion' => ' cas-001-2026 ']))->assertCreated()->assertJsonPath('data.proceso_seleccion_id', $first);
        $this->postJson($this->url(), $this->datos(['proceso_seleccion_id' => $first, 'proceso_seleccion' => null, 'anio_proceso' => null]))->assertCreated()->assertJsonPath('data.proceso_seleccion_id', $first);
        $this->assertDatabaseCount('procesos_seleccion', 1);
        $this->postJson($this->url(), $this->datos(['proceso_seleccion_id' => $first, 'proceso_seleccion' => 'OTRO-2026']))->assertUnprocessable()->assertJsonValidationErrors('proceso_seleccion_id');
        $otraLey = RegimenNormativo::query()->where('codigo', '728')->value('id');
        $this->postJson($this->url(), $this->datos(['proceso_seleccion_id' => $first, 'regimen_normativo_id' => $otraLey]))->assertUnprocessable()->assertJsonValidationErrors('proceso_seleccion_id');
        $this->getJson('/api/v1/procesos-seleccion?regimen_normativo_id='.$this->ley)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
        $this->getJson('/api/v1/procesos-seleccion?regimen_normativo_id='.$otraLey)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/procesos-seleccion?regimen_normativo_id='.$this->ley.'&q=CAS-001')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_new_entries_require_admission_mode_and_contest_or_a_supported_exception(): void
    {
        foreach ([['modalidad_ingreso' => null], ['proceso_seleccion' => null], ['anio_proceso' => null], ['anio_proceso' => 2025], ['modalidad_ingreso' => 'otro_sustentado', 'proceso_seleccion' => null, 'anio_proceso' => null]] as $invalido) {
            $this->postJson($this->url(), $this->datos($invalido))->assertUnprocessable();
        }
        $this->assertDatabaseCount('procesos_seleccion', 0);
        $this->postJson($this->url(), $this->datos(['modalidad_ingreso' => 'otro_sustentado', 'sustento_ingreso' => 'Incorporación por mandato judicial, expediente de prueba.', 'proceso_seleccion' => null, 'anio_proceso' => null]))->assertCreated();
        $this->assertDatabaseCount('procesos_seleccion', 0);
    }

    public function test_classified_entries_cannot_drop_requirements_but_legacy_corrections_remain_possible(): void
    {
        $id = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.id');
        foreach ([['modalidad_ingreso' => null], ['numero_contrato' => null], ['fecha_contrato' => null], ['proceso_seleccion_id' => null, 'proceso_seleccion' => null]] as $invalido) {
            $this->patchJson($this->url($id), $invalido)->assertUnprocessable();
        }
        $legacy = VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'fecha_cese' => null, 'modalidad_ingreso' => null, 'numero_documento_vinculo' => 'REFERENCIA-ANTERIOR']);
        $this->patchJson($this->url($legacy->id), ['nombre_puesto' => 'Puesto corregido'])->assertOk();
        $this->assertSame('REFERENCIA-ANTERIOR', $legacy->refresh()->numero_documento_vinculo);
        $otraLey = RegimenNormativo::query()->where('codigo', '728')->value('id');
        $proceso = ProcesoSeleccion::factory()->create(['regimen_normativo_id' => $otraLey]);
        $this->patchJson($this->url($legacy->id), ['proceso_seleccion_id' => $proceso->id])->assertUnprocessable()->assertJsonValidationErrors('proceso_seleccion_id');
    }

    public function test_document_profile_distinguishes_cas_from_confidence_and_career_and_indefinite_is_not_nonrenewal(): void
    {
        $this->seed([Seccion02CatalogoSeeder::class, Seccion11CatalogoSeeder::class]);
        $id = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.id');
        $vinculo = VinculoLaboral::query()->findOrFail($id);
        $perfil = PerfilDocumental::de($this->persona, collect([$vinculo]));
        foreach (['S02_F_CONTRATO' => true, 'S02_C_RESOLUCION_DESIGNACION' => false, 'S02_E_RESOLUCION_CARRERA' => false, 'S02_A_RESULTADO_SELECCION' => true] as $codigo => $exigido) {
            $this->assertSame($exigido, $perfil->exigencia(TipoDocumento::query()->where('codigo', $codigo)->firstOrFail())['exigido']);
        }
        $carta = TipoDocumento::query()->where('codigo', 'S11_G_NO_RENOVACION_CAS')->firstOrFail();
        $this->assertNotNull($perfil->aplicacion->noAplica($carta));
        $this->assertContains('plazo_indeterminado', $perfil->regimenesClasificados());
    }

    public function test_changing_admission_mode_keeps_the_confidence_profile_in_sync(): void
    {
        $id = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.id');
        PerfilAdministrativo::factory()->create(['vinculo_laboral_id' => $id, 'es_cargo_confianza' => false]);
        $this->patchJson($this->url($id), ['modalidad_ingreso' => 'designacion_confianza', 'regimen_laboral' => 'designado', 'proceso_seleccion_id' => null, 'proceso_seleccion' => null, 'anio_proceso' => null,
            'numero_resolucion' => 'R-002', 'fecha_resolucion' => '2025-12-29'])->assertOk();
        $this->assertDatabaseHas('perfil_administrativo', ['vinculo_laboral_id' => $id, 'es_cargo_confianza' => true]);
    }

    public function test_adendas_do_not_create_an_expiry_for_indefinite_contracts_in_the_panel_reports_or_alerts(): void
    {
        $id = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.id');
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $id, 'fecha_inicio' => today()->subDays(10), 'fecha_fin' => today()->addDays(5), 'anulado' => false]);
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-02')->assertOk()
            ->assertJsonPath('data.vinculos.0.vencimiento.fin_efectivo', null)->assertJsonPath('data.vinculos.0.vencimiento.por_adenda', false);
        $this->getJson('/api/v1/reportes/contratos')->assertOk()->assertJsonPath('data.0.fecha_fin', null)->assertJsonPath('data.0.estado_codigo', 'abierto');
        $this->getJson('/api/v1/vencimientos?origen=contrato')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_manual_confidence_registration_matches_the_position_profile_and_keeps_both_documents(): void
    {
        $datos = $this->datos(['regimen_laboral' => 'designado', 'modalidad_ingreso' => 'designacion_confianza', 'proceso_seleccion' => null, 'anio_proceso' => null,
            'numero_resolucion' => 'R-001', 'fecha_resolucion' => '2025-12-29']);
        unset($datos['tipo_personal'], $datos['regimen_normativo_id']);
        $registro = ['usuarios' => [['numero_doc_identidad' => '01234567', 'nombres' => 'Persona', 'apellido_paterno' => 'Prueba', 'email_institucional' => 'confianza@example.test',
            'tipo_personal' => 'administrativo', 'regimen_normativo_id' => $this->ley]], 'vinculo' => $datos, 'datos_puesto' => ['es_cargo_confianza' => false]];
        $this->postJson('/api/v1/usuarios/con-vinculo', $registro)->assertUnprocessable()->assertJsonValidationErrors('datos_puesto.es_cargo_confianza');
        $this->assertDatabaseMissing('usuarios', ['numero_doc_identidad' => '01234567']);
        $registro['datos_puesto']['es_cargo_confianza'] = true;
        $id = $this->postJson('/api/v1/usuarios/con-vinculo', $registro)->assertCreated()->json('data.vinculo.id');
        $this->assertDatabaseHas('vinculos_laborales', ['id' => $id, 'numero_contrato' => 'C-001-2026', 'numero_resolucion' => 'R-001']);
        $this->assertDatabaseHas('perfil_administrativo', ['vinculo_laboral_id' => $id, 'es_cargo_confianza' => true]);
    }

    public function test_a_failed_link_creation_rolls_back_the_new_contest(): void
    {
        VinculoLaboral::creating(fn () => throw new RuntimeException('Fallo de prueba después de registrar la convocatoria.'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->url(), $this->datos());
            $this->fail('Se esperaba la falla de creación.');
        } catch (RuntimeException $error) {
            $this->assertSame('Fallo de prueba después de registrar la convocatoria.', $error->getMessage());
        }
        $this->assertDatabaseCount('procesos_seleccion', 0);
        $this->assertDatabaseCount('vinculos_laborales', 0);
    }

    public function test_contest_catalog_requires_permission_and_does_not_treat_wildcards_as_search_operators(): void
    {
        ProcesoSeleccion::factory()->create(['regimen_normativo_id' => $this->ley]);
        $url = '/api/v1/procesos-seleccion?regimen_normativo_id='.$this->ley;
        $this->getJson($url.'&q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->actuarComo(null, 'sin_permisos');
        $this->getJson($url)->assertForbidden();
    }

    public function test_teaching_admission_requires_its_resolution_and_classifies_the_teaching_contract_separately(): void
    {
        $this->seed(Seccion02CatalogoSeeder::class);
        $ley = RegimenNormativo::query()->where('codigo', '30220')->value('id');
        $resolucionDocente = TipoDocumento::query()->where('codigo', 'S02_RESOLUCION_DOCENTE')->sole();
        $this->assertNotNull(AplicacionDocumental::para($this->persona)->noAplica($resolucionDocente));
        $this->assertContains('s02.vinculos', ConfiguracionFuncionalidades::deTipo('S02_RESOLUCION_DOCENTE', 2));
        $docente = $this->datos(['tipo_personal' => 'docente', 'regimen_normativo_id' => $ley, 'regimen_laboral' => 'plazo_determinado', 'proceso_seleccion' => 'CONCURSO-DOCENTE-001-2026']);
        $this->postJson($this->url(), $docente)->assertUnprocessable()->assertJsonValidationErrors(['numero_resolucion', 'fecha_resolucion']);
        $id = $this->postJson($this->url(), [...$docente, 'numero_resolucion' => 'R-DOC-001', 'fecha_resolucion' => '2025-12-29'])->assertCreated()->json('data.id');
        $vinculo = VinculoLaboral::query()->findOrFail($id);
        $perfil = PerfilDocumental::de($this->persona, collect([$vinculo]));
        foreach (['S02_RESOLUCION_DOCENTE' => true, 'S02_F_CONTRATO' => true, 'S02_C_RESOLUCION_DESIGNACION' => false, 'S02_E_RESOLUCION_CARRERA' => false] as $codigo => $exigido) {
            $this->assertSame($exigido, $perfil->exigencia(TipoDocumento::query()->where('codigo', $codigo)->firstOrFail())['exigido']);
        }
        $ordinario = [...$docente, 'regimen_laboral' => 'carrera', 'numero_resolucion' => 'R-NOMB-001', 'fecha_resolucion' => '2025-12-29', 'numero_contrato' => null, 'fecha_contrato' => null];
        $this->postJson($this->url(), $ordinario)->assertCreated();
    }

    public function test_a_teaching_link_cannot_use_cas_even_if_the_catalog_was_misclassified(): void
    {
        RegimenNormativo::query()->whereKey($this->ley)->update(['aplica_a' => 'docente']);
        $this->postJson($this->url(), $this->datos(['tipo_personal' => 'docente']))->assertUnprocessable()->assertJsonValidationErrors('regimen_normativo_id');
        $this->assertDatabaseCount('vinculos_laborales', 0);
    }

    public function test_a_teacher_can_register_an_independent_cas_link_under_the_same_identity(): void
    {
        $this->seed(Seccion02CatalogoSeeder::class);
        $leyDocente = RegimenNormativo::query()->where('codigo', '30220')->value('id');
        $this->persona->update(['tipo_personal' => 'docente', 'regimen_normativo_id' => $leyDocente, 'regimen_laboral' => '30220']);
        $identidad = $this->persona->refresh()->getRawOriginal();
        $docente = $this->postJson($this->url(), $this->datos([
            'tipo_personal' => 'docente', 'regimen_normativo_id' => $leyDocente, 'regimen_laboral' => 'carrera',
            'proceso_seleccion' => 'CONCURSO-DOCENTE-001-2026', 'nombre_puesto' => 'Profesor asociado',
            'numero_resolucion' => 'R-NOMB-001', 'fecha_resolucion' => '2025-12-29', 'numero_contrato' => null, 'fecha_contrato' => null,
        ]))->assertCreated()->json('data.id');
        $this->putJson($this->url($docente).'/datos-puesto', ['condicion_docente' => 'ordinario', 'categoria' => 'asociado', 'dedicacion' => 'tiempo_parcial'])->assertCreated();
        $vinculoDocente = VinculoLaboral::query()->findOrFail($docente)->getRawOriginal();
        $perfilDocente = PerfilDocente::query()->where('vinculo_laboral_id', $docente)->sole()->getRawOriginal();

        $cas = $this->postJson($this->url(), $this->datos())->assertCreated()
            ->assertJsonPath('data.usuario_id', $this->persona->id)->assertJsonPath('data.numero_resolucion', null)->json('data.id');
        $this->putJson($this->url($cas).'/datos-puesto', ['grupo_ocupacional' => 'profesional', 'es_cargo_confianza' => false])->assertCreated();
        $this->patchJson($this->url($cas), ['nombre_puesto' => 'Analista de planificación'])->assertOk();

        $this->assertSame($identidad, $this->persona->refresh()->getRawOriginal());
        $this->assertSame($vinculoDocente, VinculoLaboral::query()->findOrFail($docente)->getRawOriginal());
        $this->assertSame($perfilDocente, PerfilDocente::query()->where('vinculo_laboral_id', $docente)->sole()->getRawOriginal());
        $this->assertSame(1, Usuario::query()->where('numero_doc_identidad', $this->persona->numero_doc_identidad)->count());
        $this->assertSame(2, $this->persona->vinculosLaborales()->count());
        $this->assertDatabaseHas('perfil_administrativo', ['vinculo_laboral_id' => $cas, 'grupo_ocupacional' => 'profesional']);
        $panel = $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-02')->assertOk()->assertJsonCount(2, 'data.vinculos')->json('data');
        $filas = collect($panel['vinculos'])->keyBy('id');
        $this->assertTrue($filas[$docente]['vigente']);
        $this->assertTrue($filas[$cas]['vigente']);
        $this->assertSame('30220', $filas[$docente]['codigo_ley']);
        $this->assertSame('1057', $filas[$cas]['codigo_ley']);
        $this->assertEqualsCanonicalizing(['administrativo', 'docente'], $panel['perfil']['tipos_personal']);
        $perfil = PerfilDocumental::de($this->persona, $this->persona->vinculosLaborales()->get());
        foreach (['S02_RESOLUCION_DOCENTE', 'S02_F_CONTRATO'] as $codigo) {
            $tipo = TipoDocumento::query()->where('codigo', $codigo)->sole();
            $this->assertNull($perfil->aplicacion->noAplica($tipo));
            $this->assertTrue($perfil->exigencia($tipo)['exigido']);
        }

        $this->actuarComo(null, 'sin_permisos');
        $this->postJson($this->url(), $this->datos())->assertForbidden();
        $this->assertSame(2, $this->persona->vinculosLaborales()->count());
    }

    public function test_previously_registered_teaching_data_is_not_rewritten_or_blocked_by_an_unrelated_correction(): void
    {
        $ley = RegimenNormativo::query()->where('codigo', '30220')->value('id');
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'tipo_personal' => 'docente', 'regimen_normativo_id' => $ley,
            'modalidad_ingreso' => 'otro_sustentado', 'sustento_ingreso' => 'Registro histórico sustentado.', 'regimen_laboral' => 'plazo_determinado',
            'numero_contrato' => 'DOC-ANTERIOR', 'fecha_contrato' => '2025-12-30', 'numero_resolucion' => null, 'fecha_cese' => null]);
        $this->patchJson($this->url($vinculo->id), ['nombre_puesto' => 'Puesto docente corregido'])->assertOk();
        $this->assertNull($vinculo->refresh()->numero_resolucion);
        $this->assertSame('DOC-ANTERIOR', $vinculo->numero_contrato);
    }
}
