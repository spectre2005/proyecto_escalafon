<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\PerfilDocente;
use App\Models\RegimenNormativo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\AplicacionDocumental;
use App\Support\PerfilDocumental;
use App\Support\RegimenesLaborales;
use App\Support\Seccion11;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Régimen: el tipo de vínculo (carrera, plazo determinado, designado) sale solo de los vínculos; la persona guarda la ley
 * (regimen_normativo_id). Sin vínculo, o con uno anterior sin tipo, el tipo está Sin definir: no se oculta nada por régimen
 * y lo que depende de él queda por determinar. Los vínculos nuevos exigen uno de los tres.
 */
class RegimenVinculoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $persona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persona = Usuario::factory()->create(['tipo_personal' => 'administrativo']);
        Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo']);
    }

    private function ley(string $codigo): RegimenNormativo
    {
        return RegimenNormativo::query()->where('codigo', $codigo)->firstOrFail();
    }

    /** Tipo de documento que solo aplica a un tipo de vínculo (sin bandera ni funcionalidad que lo decida). */
    private function soloPara(string $tipoVinculo): TipoDocumento
    {
        return TipoDocumento::factory()->make(['codigo' => 'PRUEBA_REGIMEN', 'seccion_legajo_id' => 11, 'obligatorio' => true, 'aplica_tipo_personal' => 'ambos',
            'aplica_carrera' => $tipoVinculo === 'carrera', 'aplica_plazo_determinado' => $tipoVinculo === 'plazo_determinado', 'aplica_designado' => $tipoVinculo === 'designado']);
    }

    private function vinculo(?string $tipo, array $datos = []): VinculoLaboral
    {
        return VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'regimen_laboral' => $tipo, ...$datos]);
    }

    private function exigencia(TipoDocumento $tipo): array
    {
        return PerfilDocumental::de($this->persona->refresh(), $this->persona->vinculosLaborales()->get())->exigencia($tipo);
    }

    private function noAplica(TipoDocumento $tipo): ?string
    {
        return AplicacionDocumental::para($this->persona->refresh())->noAplica($tipo);
    }

    public function test_without_links_the_type_is_undefined_even_if_the_person_stored_one(): void
    {
        // Dato anterior: un tipo guardado en la columna de la ley de la persona. Ya no cuenta.
        $this->persona->update(['regimen_laboral' => 'plazo_determinado']);
        $exigencia = $this->exigencia($this->soloPara('carrera'));
        $this->assertNull($exigencia['exigido']);
        $this->assertStringContainsString('tipo de vínculo (carrera, plazo determinado o designado) está sin definir', $exigencia['motivo']);
        $this->assertNull($this->noAplica($this->soloPara('carrera')));
    }

    public function test_an_old_link_without_type_leaves_it_undefined_and_a_defined_one_filters(): void
    {
        $vinculo = $this->vinculo(null);
        $this->assertNull($this->exigencia($this->soloPara('carrera'))['exigido']);
        $this->assertNull($this->noAplica($this->soloPara('carrera')));

        $vinculo->update(['regimen_laboral' => 'plazo_determinado']);
        $this->assertSame(['exigido' => false, 'motivo' => 'No se exige para su tipo de vínculo.'], $this->exigencia($this->soloPara('carrera')));
        $this->assertSame('No corresponde a su régimen laboral.', $this->noAplica($this->soloPara('carrera')));
        $this->assertTrue($this->exigencia($this->soloPara('plazo_determinado'))['exigido']);
    }

    public function test_a_defined_link_next_to_an_undefined_one_cannot_rule_it_out(): void
    {
        $this->vinculo('plazo_determinado');
        $this->vinculo(null);
        $this->assertNull($this->exigencia($this->soloPara('carrera'))['exigido']);
        $this->assertNull($this->noAplica($this->soloPara('carrera')));
    }

    public function test_new_links_require_one_of_the_three_types_and_old_ones_cannot_go_back_to_undefined(): void
    {
        $this->actuarComo();
        $url = '/api/v1/expedientes/'.$this->persona->id.'/vinculos-laborales';
        $datos = ['tipo_personal' => 'administrativo', 'regimen_normativo_id' => $this->ley('276')->id, 'nombre_puesto' => 'Analista', 'fecha_inicio' => '2026-01-01'];
        $this->postJson($url, $datos)->assertUnprocessable()->assertJsonValidationErrors('regimen_laboral');
        $this->postJson($url, [...$datos, 'regimen_laboral' => '276'])->assertUnprocessable()->assertJsonValidationErrors('regimen_laboral');
        $this->postJson($url, [...$datos, 'regimen_laboral' => 'carrera'])->assertCreated();

        $anterior = $this->vinculo(null);
        $this->patchJson($url.'/'.$anterior->id, ['nombre_puesto' => 'Analista II'])->assertOk();
        $this->assertNull($anterior->refresh()->regimen_laboral);
        $this->patchJson($url.'/'.$anterior->id, ['regimen_laboral' => null])->assertUnprocessable()->assertJsonValidationErrors('regimen_laboral');
        $this->patchJson($url.'/'.$anterior->id, ['regimen_laboral' => 'designado'])->assertOk();
        $this->assertSame('designado', $anterior->refresh()->regimen_laboral);
    }

    public function test_the_ficha_says_where_the_undefined_type_is_defined(): void
    {
        $this->actuarComo();
        $this->persona->update(['regimen_normativo_id' => $this->ley('276')->id]);
        $url = '/api/v1/expedientes/'.$this->persona->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.regimen.sin_definir', true)->assertJsonPath('data.regimen.ley.codigo', '276')
            ->assertJsonPath('data.regimen.mensaje', 'Sin definir: se define al registrar el vínculo en 2.2.');
        $anterior = $this->vinculo(null);
        $this->getJson($url)->assertOk()->assertJsonPath('data.regimen.mensaje', RegimenesLaborales::SIN_DEFINIR_VINCULO);
        $anterior->update(['regimen_laboral' => 'carrera']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.regimen.sin_definir', false)->assertJsonPath('data.regimen.tipos_vinculo', ['carrera'])
            ->assertJsonPath('data.regimen.mensaje', null);
    }

    public function test_person_creation_takes_the_law_by_id_or_by_code(): void
    {
        $this->actuarComo();
        $fila = fn (string $dni, array $extra): array => ['numero_doc_identidad' => $dni, 'nombres' => 'Alta', 'apellido_paterno' => 'Régimen',
            'email_institucional' => "r{$dni}@example.test", 'tipo_personal' => 'administrativo', ...$extra];
        $this->postJson('/api/v1/usuarios', ['usuarios' => [$fila('51000001', ['regimen_normativo_id' => $this->ley('1057')->id]), $fila('51000002', ['regimen_laboral' => 'cas'])]])
            ->assertCreated();
        foreach (['51000001', '51000002'] as $dni) {
            $persona = Usuario::query()->where('numero_doc_identidad', $dni)->sole();
            $this->assertSame([$this->ley('1057')->id, 'CAS'], [$persona->regimen_normativo_id, $persona->regimen_laboral]);
        }
        $this->postJson('/api/v1/usuarios', ['usuarios' => [$fila('51000003', ['tipo_personal' => 'docente', 'regimen_laboral' => 'CAS'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('usuarios.0.regimen_laboral');
        $this->postJson('/api/v1/usuarios', ['usuarios' => [$fila('51000004', [])]])
            ->assertUnprocessable()->assertJsonPath('errors', ['usuarios.0.regimen_normativo_id' => ['Indica la ley (régimen normativo) de la persona: CAS, 276, 30057, 728.']]);
    }

    public function test_the_person_list_gives_the_law_and_null_when_it_is_undefined(): void
    {
        $this->actuarComo();
        $this->persona->update(['regimen_normativo_id' => null, 'regimen_laboral' => '276']);
        $this->getJson('/api/v1/usuarios/'.$this->persona->id)->assertOk()->assertJsonPath('data.ley', null)->assertJsonPath('data.regimen_laboral', '276');
        $this->persona->update(['regimen_normativo_id' => $this->ley('276')->id]);
        $this->getJson('/api/v1/usuarios/'.$this->persona->id)->assertOk()->assertJsonPath('data.ley.codigo', '276');
        $fila = collect($this->getJson('/api/v1/usuarios?per_page=100')->assertOk()->json('data'))->firstWhere('id', $this->persona->id);
        $this->assertSame('276', $fila['ley']['codigo'] ?? null);
    }

    public function test_cas_non_renewal_with_an_undefined_link_says_where_to_define_it(): void
    {
        $general = 'No corresponde.';
        $this->assertSame('Define el tipo de vínculo en la sección 02 (2.2: «Completar tipo» si el vínculo está cerrado).', Seccion11::motivoRechazo(Seccion11::NO_RENOVACION_CAS, 'no_renovacion', null, $general));
        $this->assertSame($general, Seccion11::motivoRechazo(Seccion11::NO_RENOVACION_CAS, 'no_renovacion', 'carrera', $general));
        $this->assertSame($general, Seccion11::motivoRechazo(Seccion11::NO_RENOVACION_CAS, 'renuncia', null, $general));
        $this->assertNull(Seccion11::motivoRechazo(Seccion11::NO_RENOVACION_CAS, 'no_renovacion', 'plazo_determinado', $general));
    }

    public function test_undefined_links_get_a_hint_but_nothing_is_saved_on_its_own(): void
    {
        $docente = $this->vinculo(null, ['tipo_personal' => 'docente', 'regimen_normativo_id' => $this->ley('30220')->id]);
        PerfilDocente::factory()->create(['vinculo_laboral_id' => $docente->id, 'condicion_docente' => 'ordinario']);
        $cas = $this->vinculo(null, ['regimen_normativo_id' => $this->ley('1057')->id]);
        $dl276 = $this->vinculo(null, ['regimen_normativo_id' => $this->ley('276')->id]);
        $this->assertSame('carrera', RegimenesLaborales::sugerencia($docente->fresh())['valor']);
        $this->assertSame('plazo_determinado', RegimenesLaborales::sugerencia($cas->fresh())['valor']);
        $this->assertSame(['valor' => null, 'motivo' => 'Confirma si es carrera o designado.'], RegimenesLaborales::sugerencia($dl276->fresh()));
        $this->assertNull(RegimenesLaborales::sugerencia($this->vinculo('carrera')));
        $this->assertNull($docente->fresh()->regimen_laboral);

        // Por HTTP (2.2), con la ley cargada desde el listado de vínculos.
        $this->actuarComo();
        $vinculos = collect($this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-02')->assertOk()->json('data.vinculos'))->keyBy('id');
        $this->assertSame(['valor' => 'plazo_determinado', 'motivo' => 'Contrato CAS (D.L. 1057).'], $vinculos[$cas->id]['tipo_sugerido']);
        $this->assertSame(['valor' => null, 'motivo' => 'Confirma si es carrera o designado.'], $vinculos[$dl276->id]['tipo_sugerido']);
    }
}
