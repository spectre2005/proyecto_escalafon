<?php

namespace Tests\Feature;

use App\Models\Desvinculacion;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Database\Seeders\Seccion11CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Sección 11 (Desvinculación) desde la ficha: catálogo a) a g) y aceptación de renuncia, registro por vínculo con la fecha
 * de cese del vínculo (decisión B), enlace de PDF por motivo y régimen, agrupación por vínculo, liquidación sensible,
 * permisos, anulación y CRUD genérico cerrado.
 */
class Seccion11Test extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    /** @var array<string, VinculoLaboral> */
    private array $vinculos = [];

    private int $folio = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('legajo.disco_documentos'));
        $this->seed(Seccion11CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create(['numero_doc_identidad' => '99001101']);
        Legajo::query()->create(['usuario_id' => $this->persona->id, 'numero_legajo' => 'TEST-S11-001', 'fecha_apertura' => '2015-01-01']);
        $vinculo = fn (string $regimen, ?string $cese): VinculoLaboral => VinculoLaboral::factory()->create([
            'usuario_id' => $this->persona->id, 'regimen_laboral' => $regimen, 'nombre_puesto' => 'Puesto '.$regimen, 'fecha_inicio' => '2016-01-01', 'fecha_cese' => $cese,
        ]);
        $this->vinculos = [
            'designado' => $vinculo('designado', '2020-06-30'),
            'carrera' => $vinculo('carrera', '2022-12-31'),
            'cas' => $vinculo('plazo_determinado', '2024-03-31'),
            'vigente' => $vinculo('carrera', null),
        ];
    }

    private function url(string $resto = '', ?Usuario $persona = null): string
    {
        return '/api/v1/expedientes/'.($persona ?? $this->persona)->id.'/seccion-11'.$resto;
    }

    private function desvinculacion(string $vinculo, array $cambios = []): array
    {
        $v = $this->vinculos[$vinculo];

        return [...['vinculo_laboral_id' => $v->id, 'motivo_cese' => 'otro', 'fecha_cese' => $v->fecha_cese?->format('Y-m-d') ?? '2025-01-01'], ...$cambios];
    }

    private function subirTipo(string $codigo, array $cambios = [])
    {
        $tipo = TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->firstOrFail();
        $respuesta = $this->subir($this->persona, $this->metadatos($tipo, $this->folio, $this->folio, $cambios));
        if ($respuesta->status() === 201) {
            $this->folio++;
        }

        return $respuesta;
    }

    private function actuarConPermisos(array $permisos, bool $sensibles = false): void
    {
        $codigo = 'rol_'.md5(implode(',', $permisos).($sensibles ? 's' : 'n'));
        config(['permisos.roles.'.$codigo => $permisos]);
        Sanctum::actingAs($this->asignarRol(Usuario::factory()->create(), $codigo, [], ['ve_datos_sensibles' => $sensibles])->refresh());
    }

    public function test_unified_cessation_closes_link_and_registers_once_with_audit(): void
    {
        $this->actuarComo();
        $datos = $this->desvinculacion('vigente', ['registrar_cese' => true, 'confirmar_cierre_vinculo' => true]);
        $this->postJson($this->url('/desvinculaciones'), [...$datos, 'confirmar_cierre_vinculo' => false])->assertUnprocessable();
        $this->assertNull($this->vinculos['vigente']->fresh()->fecha_cese);
        $id = $this->postJson($this->url('/desvinculaciones'), $datos)->assertCreated()->json('data.id');
        $this->assertSame('2025-01-01', $this->vinculos['vigente']->fresh()->fecha_cese->toDateString());
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'desvinculacion.registrar']);
        $this->postJson($this->url('/desvinculaciones'), $datos)->assertUnprocessable();
        $this->assertSame(1, Desvinculacion::query()->where('vinculo_laboral_id', $this->vinculos['vigente']->id)->count());
    }

    public function test_unified_cessation_rolls_back_if_evidence_is_invalid(): void
    {
        $this->actuarComo();
        $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('vigente', [
            'registrar_cese' => true, 'confirmar_cierre_vinculo' => true,
            'documento_id' => '00000000-0000-4000-8000-000000000001',
        ]))->assertUnprocessable();
        $this->assertNull($this->vinculos['vigente']->fresh()->fecha_cese);
        $this->assertDatabaseCount('desvinculaciones', 0);
        $this->assertDatabaseMissing('auditoria_eventos', ['accion' => 'vinculo.cesar']);
    }

    public function test_exceptional_correction_requires_administration_and_keeps_dates_together(): void
    {
        $this->actuarComo();
        $id = $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('carrera'))->assertCreated()->json('data.id');
        $url = $this->url('/desvinculaciones/'.$id.'/corregir-cese');
        $this->postJson($url, ['fecha_cese' => '2022-12-30'])->assertUnprocessable();
        $this->postJson($url, ['fecha_cese' => '2010-01-01', 'motivo_correccion' => 'Error de transcripción comprobado'])->assertUnprocessable();
        $this->postJson($url, ['fecha_cese' => '2022-12-30', 'motivo_correccion' => 'Error de transcripción comprobado'])->assertOk();
        $this->assertSame('2022-12-30', $this->vinculos['carrera']->fresh()->fecha_cese->toDateString());
        $this->assertSame('2022-12-30', Desvinculacion::query()->findOrFail($id)->fecha_cese->toDateString());
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'cese.corregir']);
        $this->actuarConPermisos(['legajo.ver', 'legajo.editar']);
        $this->postJson($url, ['fecha_cese' => '2022-12-29', 'motivo_correccion' => 'Otro cambio sin permiso'])->assertForbidden();
    }

    public function test_catalog_has_the_directive_types_none_mandatory(): void
    {
        $this->seed(Seccion11CatalogoSeeder::class);
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 11)->orderBy('orden')->get()->keyBy('codigo');

        $this->assertSame(['S11_A_TERMINO_DESIGNACION', 'S11_B_TERMINO_CARRERA', 'S11_C_TERMINO_VINCULO', 'S11_D_INFORME_GESTION', 'S11_E_ENTREGA_CARGO',
            'S11_F_LIQUIDACION', 'S11_G_NO_RENOVACION_CAS', 'S11_X_ACEPTACION_RENUNCIA', 'S11_OTROS_UNIVERSIDAD'], $tipos->keys()->all());
        $this->assertTrue($tipos->every(fn (TipoDocumento $t): bool => ! $t->obligatorio && $t->permite_multiples));
        $this->assertSame(['S11_F_LIQUIDACION'], $tipos->where('es_dato_sensible', true)->keys()->values()->all());
        // g) es del DL 1057: solo plazo determinado.
        $g = $tipos['S11_G_NO_RENOVACION_CAS'];
        $this->assertTrue($g->aplica_plazo_determinado && ! $g->aplica_carrera && ! $g->aplica_designado);
        $this->assertStringContainsString('1057', $g->nombre);
        $this->assertNull($tipos['S11_OTROS_UNIVERSIDAD']->formulario_datos);
        $this->assertStringContainsString('Anexo 05', $tipos['S11_X_ACEPTACION_RENUNCIA']->descripcion);
    }

    public function test_registers_one_desvinculacion_per_vinculo_with_the_vinculo_cessation_date(): void
    {
        $admin = $this->actuarComo();

        $id = $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('carrera', [
            'motivo_cese' => 'termino_carrera', 'numero_resolucion' => 'RES-11', 'fecha_entrega_cargo' => '2022-12-30',
            'informe_gestion_presentado' => true, 'fecha_liquidacion' => '2023-01-20', 'observaciones' => 'Cese ordinario',
        ]))->assertCreated()->assertJsonPath('data.values.motivo_cese', 'termino_carrera')->assertJsonPath('data.values.informe_gestion_presentado', true)->json('data.id');
        $this->assertDatabaseHas('desvinculaciones', ['id' => $id, 'registrado_por' => $admin->id, 'usuario_id' => $this->persona->id]);

        // Fecha distinta a la del vínculo: rechazada.
        $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('designado', ['fecha_cese' => '2020-07-01']))->assertUnprocessable()->assertJsonValidationErrors('fecha_cese');
        // Vínculo sin cese: primero en la sección 02.
        $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('vigente'))->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');
        // Un solo registro vigente por vínculo.
        $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('carrera'))->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');
        // Vínculo de otra persona.
        $ajeno = VinculoLaboral::factory()->create(['fecha_cese' => '2021-01-01']);
        $this->postJson($this->url('/desvinculaciones'), ['vinculo_laboral_id' => $ajeno->id, 'motivo_cese' => 'otro', 'fecha_cese' => '2021-01-01'])->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');
        // Motivos: los del esquema más termino_carrera; ninguno inventado.
        $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('designado', ['motivo_cese' => 'despido']))->assertUnprocessable()->assertJsonValidationErrors('motivo_cese');
        $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('designado', ['registrado_por' => $this->persona->id]))->assertUnprocessable()->assertJsonValidationErrors('registrado_por');
        $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('designado', ['anulado' => true]))->assertUnprocessable()->assertJsonValidationErrors('anulado');

        // Corrección: la fecha sigue atada al vínculo.
        $this->patchJson($this->url('/desvinculaciones/'.$id), ['fecha_cese' => '2022-12-30'])->assertUnprocessable()->assertJsonValidationErrors('fecha_cese');
        $this->patchJson($this->url('/desvinculaciones/'.$id), ['fecha_liquidacion' => '2023-02-01'])->assertOk()->assertJsonPath('data.values.fecha_liquidacion', '2023-02-01');

        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(4, 'data.vinculos')
            ->assertJsonCount(1, 'data.desvinculaciones')
            ->assertJsonPath('data.desvinculaciones.0.coincide_fecha_vinculo', true)
            ->assertJsonPath('data.desvinculaciones.0.values.registrado_por', trim($admin->nombres.' '.$admin->apellido_paterno.' '.$admin->apellido_materno))
            ->assertJsonCount(9, 'data.tipos');
    }

    public function test_term_documents_accredit_only_the_matching_cessation(): void
    {
        $this->actuarComo();
        $designacion = Desvinculacion::query()->find($this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('designado', ['motivo_cese' => 'termino_designacion']))->json('data.id'));
        $carrera = Desvinculacion::query()->find($this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('carrera', ['motivo_cese' => 'termino_carrera']))->json('data.id'));

        // a) término de designación: solo a una desvinculación por término de designación.
        $this->subirTipo('S11_A_TERMINO_DESIGNACION', ['enlace_id' => $carrera->id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $doc = $this->subirTipo('S11_A_TERMINO_DESIGNACION', ['enlace_id' => $designacion->id])->assertCreated()->assertJsonPath('data.enlazado', true)->json('data');
        $this->assertSame($doc['id'], $designacion->refresh()->documento_id);
        $this->assertSame($this->vinculos['designado']->id, DocumentoUsuario::query()->find($doc['id'])->vinculo_laboral_id);
        // Ya tiene documento de término: otro se rechaza como acreditación.
        $this->subirTipo('S11_C_TERMINO_VINCULO', ['enlace_id' => $designacion->id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');

        // b) término de carrera.
        $this->subirTipo('S11_B_TERMINO_CARRERA', ['enlace_id' => $designacion->id])->assertUnprocessable();
        $doc = $this->subirTipo('S11_B_TERMINO_CARRERA', ['enlace_id' => $carrera->id])->assertCreated()->json('data');
        $this->assertSame($doc['id'], $carrera->refresh()->documento_id);

        // g) no renovación DL 1057: motivo no_renovacion y vínculo de plazo determinado; al subir abre el registro.
        $doc = $this->subirTipo('S11_G_NO_RENOVACION_CAS')->assertCreated()->assertJsonPath('data.formulario_datos', 'desvinculaciones')->assertJsonPath('data.enlazado', false)->json('data');
        $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('cas', ['motivo_cese' => 'renuncia', 'documento_id' => $doc['id']]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $id = $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('cas', ['motivo_cese' => 'no_renovacion', 'documento_id' => $doc['id']]))->assertCreated()->json('data.id');
        $this->assertSame($doc['id'], Desvinculacion::query()->find($id)->documento_id);
        $this->assertSame($this->vinculos['cas']->id, DocumentoUsuario::query()->find($doc['id'])->vinculo_laboral_id);
        // Con el documento g) enlazado, el motivo no puede cambiar a otro.
        $this->patchJson($this->url('/desvinculaciones/'.$id), ['motivo_cese' => 'renuncia'])->assertUnprocessable()->assertJsonValidationErrors('motivo_cese');
    }

    public function test_non_renewal_letter_only_for_fixed_term_vinculos(): void
    {
        $this->actuarComo();
        // No renovación en un vínculo de carrera: el documento g) no la acredita.
        $carrera = $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('carrera', ['motivo_cese' => 'no_renovacion']))->assertCreated()->json('data.id');
        $this->subirTipo('S11_G_NO_RENOVACION_CAS', ['enlace_id' => $carrera])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
    }

    public function test_resignation_acceptance_and_generic_term_memo(): void
    {
        $this->actuarComo();
        $renuncia = $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('designado', ['motivo_cese' => 'renuncia']))->json('data.id');
        $carrera = $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('carrera', ['motivo_cese' => 'jubilacion']))->json('data.id');

        // Aceptación de renuncia (Anexo 05): solo con motivo renuncia.
        $this->subirTipo('S11_X_ACEPTACION_RENUNCIA', ['enlace_id' => $carrera])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $doc = $this->subirTipo('S11_X_ACEPTACION_RENUNCIA', ['enlace_id' => $renuncia])->assertCreated()->json('data');
        $this->assertSame($doc['id'], Desvinculacion::query()->find($renuncia)->documento_id);
        // c) término de vínculo: cualquier motivo.
        $doc = $this->subirTipo('S11_C_TERMINO_VINCULO', ['enlace_id' => $carrera])->assertCreated()->json('data');
        $this->assertSame($doc['id'], Desvinculacion::query()->find($carrera)->documento_id);
    }

    public function test_complementary_documents_are_grouped_by_vinculo_without_replacing_the_term_document(): void
    {
        $this->actuarComo();
        $doc = $this->subirTipo('S11_C_TERMINO_VINCULO')->assertCreated()->json('data');
        $id = $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('carrera', ['documento_id' => $doc['id']]))->assertCreated()->json('data.id');

        // d) informe de gestión, e) entrega de cargo y f) liquidación: se agrupan por el vínculo, varios por desvinculación.
        foreach (['S11_D_INFORME_GESTION', 'S11_E_ENTREGA_CARGO', 'S11_F_LIQUIDACION', 'S11_F_LIQUIDACION'] as $codigo) {
            $complementario = $this->subirTipo($codigo, ['enlace_id' => $id])->assertCreated()->assertJsonPath('data.enlazado', true)->json('data');
            $this->assertSame($this->vinculos['carrera']->id, DocumentoUsuario::query()->find($complementario['id'])->vinculo_laboral_id);
        }
        $this->assertSame($doc['id'], Desvinculacion::query()->find($id)->documento_id);
        // Un complementario no crea ni acredita una desvinculación.
        $suelto = $this->subirTipo('S11_E_ENTREGA_CARGO')->assertCreated()->json('data');
        $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('designado', ['documento_id' => $suelto['id']]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        // Otros: solo metadatos.
        $this->subirTipo('S11_OTROS_UNIVERSIDAD', ['enlace_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->subirTipo('S11_OTROS_UNIVERSIDAD')->assertCreated()->assertJsonPath('data.formulario_datos', null);

        $data = $this->getJson($this->url())->assertOk()->json('data');
        $this->assertSame(5, collect($data['vinculos'])->firstWhere('id', $this->vinculos['carrera']->id)['documentos']);
        $this->assertSame(range(1, 7), DocumentoUsuario::query()->where('seccion_legajo_id', 11)->pluck('folio_inicio')->sort()->values()->all());

        // Recepción y visor comunes, con bitácora.
        $base = '/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$doc['id'];
        $this->postJson($base.'/recepcionar')->assertOk()->assertJsonPath('data.estado_visible', 'aceptado');
        $this->get($base.'/archivo')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('bitacora_accesos_legajo', ['documento_id' => $doc['id'], 'accion' => 'ver']);
    }

    public function test_liquidation_documents_require_sensitive_access(): void
    {
        $this->actuarComo();
        $doc = $this->subirTipo('S11_F_LIQUIDACION')->assertCreated()->json('data');

        $this->actuarConPermisos(['legajo.ver', 'legajo.editar', 'documento.subir'], false);
        $tipo = collect($this->getJson($this->url())->assertOk()->json('data.tipos'))->firstWhere('codigo', 'S11_F_LIQUIDACION');
        $this->assertTrue($tipo['restringido']);
        $this->assertSame([], $tipo['lista']);
        $this->get('/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$doc['id'].'/archivo')->assertForbidden();
        $this->subirTipo('S11_F_LIQUIDACION')->assertForbidden();
        // Los demás documentos de la sección no son sensibles.
        $this->subirTipo('S11_D_INFORME_GESTION')->assertCreated();
    }

    public function test_permissions_annulment_and_annulled_view(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->actuarConPermisos(['personal.ver']);
        $this->getJson($this->url())->assertForbidden();

        // Solo lectura.
        $this->actuarConPermisos(['legajo.ver']);
        $this->getJson($this->url())->assertOk();
        $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('carrera'))->assertForbidden();

        // Registra y corrige, pero no anula ni ve anuladas.
        $this->actuarConPermisos(['legajo.ver', 'legajo.editar']);
        $id = $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('carrera'))->assertCreated()->json('data.id');
        $this->postJson($this->url('/desvinculaciones/'.$id.'/anular'), ['motivo_anulacion' => 'Sin permiso'])->assertForbidden();
        $this->getJson($this->url('/anulados'))->assertForbidden();

        $this->actuarComo();
        $this->postJson($this->url('/desvinculaciones/'.$id.'/anular'), [])->assertUnprocessable()->assertJsonValidationErrors('motivo_anulacion');
        $this->postJson($this->url('/desvinculaciones/'.$id.'/anular'), ['motivo_anulacion' => 'Registrada por error'])->assertOk()->assertJsonPath('data.values.anulado', true);
        $this->postJson($this->url('/desvinculaciones/'.$id.'/anular'), ['motivo_anulacion' => 'Otra vez'])->assertConflict();
        $this->patchJson($this->url('/desvinculaciones/'.$id), ['observaciones' => 'x'])->assertConflict();
        $this->assertDatabaseHas('desvinculaciones', ['id' => $id, 'anulado' => true, 'motivo_anulacion' => 'Registrada por error']);
        $data = $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data.desvinculaciones')->json('data');
        $this->assertNull(collect($data['vinculos'])->firstWhere('id', $this->vinculos['carrera']->id)['desvinculacion_id']);
        $this->getJson($this->url('/anulados'))->assertOk()->assertJsonCount(1, 'data.desvinculaciones')->assertJsonPath('data.desvinculaciones.0.values.motivo_anulacion', 'Registrada por error');
        // Anulada, el vínculo puede volver a registrarse.
        $this->postJson($this->url('/desvinculaciones'), $this->desvinculacion('carrera'))->assertCreated();

        // Registro ajeno y persona sin legajo.
        $ajeno = Desvinculacion::factory()->create();
        $this->postJson($this->url('/desvinculaciones/'.$ajeno->id.'/anular'), ['motivo_anulacion' => 'Intento ajeno'])->assertNotFound();
        $this->postJson($this->url('/desvinculaciones', Usuario::factory()->create()), $this->desvinculacion('designado'))->assertConflict();
    }

    public function test_generic_crud_is_read_only_and_never_deletes(): void
    {
        $this->actuarComo();
        $registro = Desvinculacion::factory()->create();
        $antes = $registro->refresh()->getAttributes();
        $this->getJson('/api/v1/desvinculaciones')->assertOk();
        $this->getJson('/api/v1/desvinculaciones/'.$registro->id)->assertOk();
        $this->postJson('/api/v1/desvinculaciones', [])->assertStatus(405);
        $this->patchJson('/api/v1/desvinculaciones/'.$registro->id, ['anulado' => true, 'registrado_por' => $this->persona->id])->assertStatus(405);
        $this->putJson('/api/v1/desvinculaciones/'.$registro->id, [])->assertStatus(405);
        $this->deleteJson('/api/v1/desvinculaciones/'.$registro->id)->assertStatus(405);
        $this->assertEquals($antes, $registro->refresh()->getAttributes());
    }
}
