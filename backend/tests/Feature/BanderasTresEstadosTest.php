<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\RegimenNormativo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\AplicacionDocumental;
use App\Support\PerfilDocumental;
use Database\Seeders\Seccion01CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Banderas del perfil con tres estados (Sí / No / Sin responder) y una sola fuente (AplicacionDocumental):
 * No no se ofrece ni se carga (lo ya cargado se ve y se subsana), Sin responder se ofrece con aviso y queda por
 * determinar, Sí se exige. Las del puesto salen de los vínculos vigentes; con_discapacidad exige permiso y auditoría.
 */
class BanderasTresEstadosTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private const DJBR = 'S01_E_DJ_BIENES_RENTAS';

    private Usuario $persona;

    private int $folio = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('legajo.disco_documentos'));
        $this->seed(Seccion01CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create(['numero_doc_identidad' => '40000002']);
        Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo']);
    }

    private function tipo(string $codigo): TipoDocumento
    {
        return TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->firstOrFail();
    }

    private function vinculo(array $datos = []): VinculoLaboral
    {
        return VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, ...$datos]);
    }

    private function aplicacion(): AplicacionDocumental
    {
        return AplicacionDocumental::para($this->persona->refresh());
    }

    private function exigencia(string $codigo): array
    {
        return PerfilDocumental::de($this->persona->refresh(), $this->persona->vinculosLaborales()->get())->exigencia($this->tipo($codigo));
    }

    private function seccion01(): array
    {
        return $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-01')->assertOk()->json('data');
    }

    private function noAplicables(): array
    {
        return $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/documentos/no-aplicables')->assertOk()->json();
    }

    private function subirDjbr(array $cambios = []): TestResponse
    {
        $this->folio++;
        $respuesta = $this->subir($this->persona, $this->metadatos($this->tipo(self::DJBR), $this->folio, $this->folio, $cambios));
        if ($respuesta->status() === 201) {
            $this->folio = $respuesta->json('data.folio_fin');
        }

        return $respuesta;
    }

    public function test_several_values_combine_yes_if_any_no_only_if_all(): void
    {
        $this->assertTrue(AplicacionDocumental::combinar([true, false]));
        $this->assertTrue(AplicacionDocumental::combinar([null, true]));
        $this->assertFalse(AplicacionDocumental::combinar([false, false]));
        $this->assertNull(AplicacionDocumental::combinar([false, null]));
        $this->assertNull(AplicacionDocumental::combinar([null, null]));
        $this->assertNull(AplicacionDocumental::combinar([]));
    }

    public function test_three_states_decide_offer_requirement_and_notice(): void
    {
        $this->actuarComo();
        $vinculo = $this->vinculo(['obligado_djbr' => null]);
        $tipo = $this->tipo(self::DJBR);

        // Sin responder: se ofrece con aviso, queda por determinar y no cuenta en la completitud.
        $this->assertNull($this->aplicacion()->noAplica($tipo));
        $this->assertStringStartsWith('Falta definir si corresponde: «Obligado a presentar declaración jurada de bienes y rentas» está sin responder',
            $this->aplicacion()->porDefinir($tipo));
        $this->assertNull($this->exigencia(self::DJBR)['exigido']);
        $lista = $this->noAplicables();
        $this->assertContains(self::DJBR, array_column($lista['por_definir'], 'codigo'));
        $this->assertNotContains(self::DJBR, array_column($lista['data'], 'codigo'));
        $data = $this->seccion01();
        $e = collect($data['tipos'])->firstWhere('codigo', self::DJBR);
        $this->assertSame([null, true], [$e['exigido'], $e['aplica']]);
        $this->assertNotContains('e', array_column($data['resumen']['detalle'], 'literal'));

        // Sí: se exige y cuenta.
        $vinculo->update(['obligado_djbr' => true]);
        $this->assertNull($this->aplicacion()->porDefinir($tipo));
        $this->assertTrue($this->exigencia(self::DJBR)['exigido']);
        $this->assertContains('e', array_column($this->seccion01()['resumen']['detalle'], 'literal'));

        // No: no se ofrece, no se exige y la carga se rechaza explicando qué hacer.
        $vinculo->update(['obligado_djbr' => false]);
        $this->assertFalse($this->exigencia(self::DJBR)['exigido']);
        $this->assertContains(self::DJBR, array_column($this->noAplicables()['data'], 'codigo'));
        $this->assertFalse(collect($this->seccion01()['tipos'])->firstWhere('codigo', self::DJBR)['aplica']);
        $mensaje = $this->subirDjbr()->assertUnprocessable()->json('errors.tipo_documento_id.0');
        $this->assertStringContainsString('está en No', $mensaje);
        $this->assertStringContainsString('cámbialo a Sí en el vínculo laboral (sección 02) y vuelve a intentarlo', $mensaje);
    }

    public function test_several_active_links_and_ceased_or_annulled_ones(): void
    {
        $uno = $this->vinculo(['obligado_djbr' => true]);
        $dos = $this->vinculo(['obligado_djbr' => false]);
        $this->assertTrue($this->aplicacion()->banderas['obligado_djbr']);
        $uno->update(['obligado_djbr' => false]);
        $this->assertFalse($this->aplicacion()->banderas['obligado_djbr']);
        $dos->update(['obligado_djbr' => null]);
        $this->assertNull($this->aplicacion()->banderas['obligado_djbr']);
        $uno->update(['obligado_djbr' => null]);
        $this->assertNull($this->aplicacion()->banderas['obligado_djbr']);

        // Un vínculo anulado no cuenta ni para exigir ni para ofrecer.
        $uno->update(['obligado_djbr' => false]);
        $dos->update(['obligado_djbr' => false]);
        $this->vinculo(['obligado_djbr' => true, 'anulado' => true, 'motivo_anulacion' => 'Duplicado']);
        $this->assertFalse($this->aplicacion()->banderas['obligado_djbr']);
        $this->assertNotNull($this->aplicacion()->noAplica($this->tipo(self::DJBR)));

        // Un vínculo cesado en Sí no se exige (cuentan los vigentes), pero el tipo se ofrece (digitalizar la historia).
        $this->vinculo(['obligado_djbr' => true, 'fecha_inicio' => '2020-01-01', 'fecha_cese' => '2021-01-01']);
        $this->assertFalse($this->exigencia(self::DJBR)['exigido']);
        $this->assertTrue($this->aplicacion()->bandera('obligado_djbr', paraOfrecer: true));
        $this->assertNull($this->aplicacion()->noAplica($this->tipo(self::DJBR)));
    }

    public function test_without_active_link_puesto_flags_come_from_the_person_with_notice(): void
    {
        $this->persona->update(['obligado_djbr' => true]);
        $this->assertTrue($this->exigencia(self::DJBR)['exigido']);
        $this->assertTrue($this->aplicacion()->sinVinculoVigente);
        $this->assertStringStartsWith('No tiene un vínculo laboral vigente', $this->aplicacion()->avisos()[0]);

        // Con vínculo vigente manda el vínculo, aunque la copia de la persona diga Sí.
        $this->vinculo(['obligado_djbr' => null]);
        $this->assertNull($this->exigencia(self::DJBR)['exigido']);
        $this->assertSame([], $this->aplicacion()->avisos());
    }

    public function test_changing_a_flag_keeps_loaded_documents_and_allows_correcting_them(): void
    {
        $this->actuarComo();
        $vinculo = $this->vinculo(['obligado_djbr' => true]);
        $id = $this->subirDjbr()->assertCreated()->json('data.id');
        $vinculo->update(['obligado_djbr' => false]);

        // Lo cargado sigue en el legajo y se ve en su tipo.
        $this->assertSame(1, DocumentoUsuario::query()->where('usuario_id', $this->persona->id)->count());
        $this->assertSame(1, collect($this->seccion01()['tipos'])->firstWhere('codigo', self::DJBR)['documentos']);
        // Subsanar lo existente se permite; un documento nuevo no.
        $this->subirDjbr(['subsana_a_documento_id' => $id])->assertCreated();
        $this->subirDjbr()->assertUnprocessable();
    }

    /** PENDIENTE de confirmar con el cliente (pregunta 18): g, i y j atados a tiene_derechohabientes. */
    public function test_family_documents_follow_dependants_flag_only_without_family_members(): void
    {
        $this->actuarComo();
        $hijos = fn (array $data): array => collect($data['tipos'])->firstWhere('codigo', 'S01_J_DNI_HIJOS_MENORES');

        $this->persona->update(['tiene_derechohabientes' => null]);
        $j = $hijos($this->seccion01());
        $this->assertSame([null, true], [$j['exigido'], $j['aplica']]);
        $this->assertStringStartsWith('Falta definir si corresponde', $j['motivo']);

        $this->persona->update(['tiene_derechohabientes' => true]);
        $data = $this->seccion01();
        $this->assertSame([null, true], [$hijos($data)['exigido'], $hijos($data)['aplica']]);
        $this->assertStringContainsString('falta registrar familiares', $hijos($data)['motivo']);
        $this->assertStringContainsString('falta registrar familiares', $data['avisos'][0]);

        $this->persona->update(['tiene_derechohabientes' => false]);
        $this->assertSame([false, false], [$hijos($this->seccion01())['exigido'], $hijos($this->seccion01())['aplica']]);
        $this->assertContains('S01_J_DNI_HIJOS_MENORES', array_column($this->noAplicables()['data'], 'codigo'));

        // Con familiares registrados no se oculta: se exige por familiar, como antes.
        Familiar::factory()->create(['usuario_id' => $this->persona->id, 'parentesco' => 'hijo', 'fecha_nacimiento' => today()->subYears(3)]);
        $this->assertSame([true, true], [$hijos($this->seccion01())['exigido'], $hijos($this->seccion01())['aplica']]);
        $this->assertNotContains('S01_J_DNI_HIJOS_MENORES', array_column($this->noAplicables()['data'], 'codigo'));
    }

    public function test_disability_changes_require_permission_and_are_audited(): void
    {
        $admin = $this->actuarComo();
        $this->assertContains('discapacidad.editar', $admin->permisos());
        $uri = '/api/v1/expedientes/'.$this->persona->id;
        $eventos = fn () => AuditoriaEvento::query()->where('accion', 'persona.con_discapacidad')->where('entidad_id', $this->persona->id);

        $this->patchJson($uri, ['con_discapacidad' => true])->assertOk();
        $evento = $eventos()->sole();
        $this->assertSame([$admin->id, $this->persona->id], [$evento->actor_usuario_id, $evento->usuario_titular_id]);
        $this->assertSame(['con_discapacidad' => null], json_decode($evento->datos_antes, true));
        $this->assertSame(['con_discapacidad' => true], json_decode($evento->datos_despues, true));
        $this->assertNotNull($evento->fecha_evento);
        // El mismo valor no es un cambio: no se audita.
        $this->patchJson($uri, ['con_discapacidad' => true])->assertOk();
        $this->assertSame(1, $eventos()->count());

        // El asistente (aun con datos sensibles) no tiene el permiso: pendiente del cliente.
        $asistente = $this->asignarRol(Usuario::factory()->create(), 'asistente_legajo', [], ['ve_datos_sensibles' => true])->refresh();
        $this->assertNotContains('discapacidad.editar', $asistente->permisos());
        Sanctum::actingAs($asistente);
        $this->patchJson($uri, ['con_discapacidad' => false])->assertForbidden();
        $this->patchJson($uri, ['con_discapacidad' => null])->assertForbidden();
        $this->assertTrue($this->persona->refresh()->con_discapacidad);
        $this->patchJson($uri, ['con_discapacidad' => true, 'tiene_derechohabientes' => false])->assertOk();
        $this->assertFalse($this->persona->refresh()->tiene_derechohabientes);
        $this->assertSame(1, $eventos()->count());
    }

    public function test_person_creation_defaults_flags_to_unanswered_and_audits_disability(): void
    {
        $this->actuarComo();
        $fila = fn (string $dni, array $extra = []): array => ['numero_doc_identidad' => $dni, 'nombres' => 'Prueba', 'apellido_paterno' => 'Banderas',
            'email_institucional' => "p{$dni}@example.test", 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'CAS', ...$extra];
        $this->postJson('/api/v1/usuarios', ['usuarios' => [$fila('50000001'), $fila('50000002', ['con_discapacidad' => true, 'tiene_derechohabientes' => false])]])
            ->assertCreated();
        $sin = Usuario::query()->where('numero_doc_identidad', '50000001')->sole();
        $con = Usuario::query()->where('numero_doc_identidad', '50000002')->sole();
        $this->assertSame([null, null], [$sin->con_discapacidad, $sin->tiene_derechohabientes]);
        $this->assertSame([true, false], [$con->con_discapacidad, $con->tiene_derechohabientes]);
        $this->assertSame([null, null], [$sin->obligado_djbr, $sin->tiene_periodo_prueba]);
        $eventos = fn () => AuditoriaEvento::query()->where('accion', 'persona.con_discapacidad');
        $this->assertSame([$con->id], $eventos()->pluck('entidad_id')->all());

        // El CRUD de usuarios también audita y no se salta el permiso.
        $this->patchJson('/api/v1/usuarios/'.$con->id, ['con_discapacidad' => false])->assertOk();
        $this->assertSame(2, $eventos()->count());
    }

    public function test_link_form_keeps_puesto_flags_unanswered_by_default(): void
    {
        $this->actuarComo();
        $datos = ['tipo_personal' => 'administrativo', 'regimen_laboral' => 'plazo_determinado', 'regimen_normativo_id' => RegimenNormativo::factory()->create()->id,
            'nombre_puesto' => 'Analista', 'fecha_inicio' => '2026-01-01'];
        $id = $this->postJson('/api/v1/expedientes/'.$this->persona->id.'/vinculos-laborales', $datos)->assertCreated()->json('data.id');
        $vinculo = VinculoLaboral::query()->findOrFail($id);
        $this->assertSame([null, null, null, null], [$vinculo->obligado_djbr, $vinculo->exige_colegiatura, $vinculo->exige_estudios_basicos, $vinculo->tiene_periodo_prueba]);

        $id = $this->postJson('/api/v1/expedientes/'.$this->persona->id.'/vinculos-laborales', [...$datos, 'obligado_djbr' => false, 'tiene_periodo_prueba' => null])
            ->assertCreated()->json('data.id');
        $this->assertSame([false, null], [VinculoLaboral::query()->findOrFail($id)->obligado_djbr, VinculoLaboral::query()->findOrFail($id)->tiene_periodo_prueba]);
    }

    public function test_probation_period_is_rejected_only_when_its_flag_is_no(): void
    {
        $this->actuarComo();
        $vinculo = $this->vinculo(['tiene_periodo_prueba' => false]);
        $uri = '/api/v1/expedientes/'.$this->persona->id.'/periodos-prueba';
        $datos = ['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-01', 'resultado' => 'pendiente'];
        $this->assertStringContainsString('está en No', $this->postJson($uri, $datos)->assertUnprocessable()->json('errors.vinculo_laboral_id.0'));
        $vinculo->update(['tiene_periodo_prueba' => null]);
        $this->assertStringNotContainsString('está en No', (string) $this->postJson($uri, $datos)->json('errors.vinculo_laboral_id.0'));
    }

    public function test_counter_of_people_with_unanswered_flags_is_for_administration(): void
    {
        $this->actuarComo();
        // La persona del setUp y la del administrador: banderas de la persona sin responder y sin vínculo vigente.
        $completa = Usuario::factory()->create(['tiene_derechohabientes' => false, 'con_discapacidad' => false]);
        VinculoLaboral::factory()->create(['usuario_id' => $completa->id, 'obligado_djbr' => true, 'exige_colegiatura' => false, 'exige_estudios_basicos' => false, 'tiene_periodo_prueba' => false]);
        $puesto = Usuario::factory()->create(['tiene_derechohabientes' => true, 'con_discapacidad' => false]);
        VinculoLaboral::factory()->create(['usuario_id' => $puesto->id, 'obligado_djbr' => true, 'exige_colegiatura' => null, 'exige_estudios_basicos' => false, 'tiene_periodo_prueba' => false]);
        // Un Sí en otro vínculo vigente resuelve la bandera; un vínculo cesado no cuenta.
        VinculoLaboral::factory()->create(['usuario_id' => $completa->id, 'obligado_djbr' => null, 'exige_colegiatura' => null, 'fecha_inicio' => '2020-01-01', 'fecha_cese' => '2021-01-01']);

        $this->getJson('/api/v1/personal/banderas-sin-responder')->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.por_bandera.tiene_derechohabientes', 2)->assertJsonPath('data.por_bandera.con_discapacidad', 2)
            ->assertJsonPath('data.por_bandera.exige_colegiatura', 1)->assertJsonPath('data.por_bandera.obligado_djbr', 0);

        $this->actuarComo(null, 'asistente_legajo');
        $this->getJson('/api/v1/personal/banderas-sin-responder')->assertForbidden();
    }
}
