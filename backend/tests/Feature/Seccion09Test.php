<?php

namespace Tests\Feature;

use App\Models\Afiliacion;
use App\Models\Controversia;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\Seccion09CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Sección 09 (Relaciones laborales) desde la ficha: catálogo, controversias individuales y colectivas, afiliación al
 * sindicato, carga y enlace de PDF (cada tipo solo con su registro), permisos sensibles, anulación y CRUD genérico cerrado.
 */
class Seccion09Test extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private int $folio = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('legajo.disco_documentos'));
        $this->seed(Seccion09CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create(['numero_doc_identidad' => '99000901']);
        Legajo::query()->create(['usuario_id' => $this->persona->id, 'numero_legajo' => 'TEST-S09-001', 'fecha_apertura' => today()->subYear()->toDateString()]);
    }

    private function url(string $resto = '', ?Usuario $persona = null): string
    {
        return '/api/v1/expedientes/'.($persona ?? $this->persona)->id.'/seccion-09'.$resto;
    }

    private function tipo09(string $codigo): TipoDocumento
    {
        return TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->firstOrFail();
    }

    /** Sube un PDF del tipo con folio correlativo; devuelve la respuesta. */
    private function subirTipo(string $codigo, array $cambios = [])
    {
        $respuesta = $this->subir($this->persona, $this->metadatos($this->tipo09($codigo), $this->folio, $this->folio, $cambios));
        if ($respuesta->status() === 201) {
            $this->folio++;
        }

        return $respuesta;
    }

    private function controversia(array $cambios = []): array
    {
        return [...['tipo' => 'individual', 'instancia' => 'entidad', 'materia' => 'Reclamo por pago de beneficios', 'fecha_inicio' => '2025-03-01'], ...$cambios];
    }

    private function afiliacion(array $cambios = []): array
    {
        return [...['entidad' => 'Sindicato de servidores', 'numero_afiliacion' => 'AF-10', 'fecha_inicio' => '2024-01-15'], ...$cambios];
    }

    /** Rol con los permisos dados; con o sin acceso a datos sensibles. */
    private function actuarConPermisos(array $permisos, bool $sensibles): Usuario
    {
        $codigo = 'rol_'.md5(implode(',', $permisos).($sensibles ? 's' : 'n'));
        config(['permisos.roles.'.$codigo => $permisos]);
        $usuario = $this->asignarRol(Usuario::factory()->create(), $codigo, [], ['ve_datos_sensibles' => $sensibles])->refresh();
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    public function test_catalog_has_the_directive_types_and_is_idempotent(): void
    {
        $this->seed(Seccion09CatalogoSeeder::class);
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 9)->orderBy('orden')->get();

        $this->assertSame([
            'S09_A_CONTROVERSIAS_INDIVIDUALES' => 'controversias', 'S09_B_CONTROVERSIAS_COLECTIVAS' => 'controversias',
            'S09_C_AFILIACION_SINDICATO' => 'afiliaciones', 'S09_OTROS_UNIVERSIDAD' => null,
        ], $tipos->pluck('formulario_datos', 'codigo')->all());
        // Nada es obligatorio; todo es sensible y admite varios documentos.
        $this->assertTrue($tipos->every(fn (TipoDocumento $t): bool => ! $t->obligatorio && $t->es_dato_sensible && $t->permite_multiples && $t->subseccion_legajo_id === null));
        $this->assertTrue($tipos->firstWhere('codigo', 'S09_OTROS_UNIVERSIDAD')->es_hook_universidad);
    }

    public function test_registers_lists_and_corrects_controversies_and_union_membership(): void
    {
        $admin = $this->actuarComo();
        $individual = $this->postJson($this->url('/controversias'), $this->controversia())->assertCreated()
            ->assertJsonPath('data.values.estado', 'en_tramite')->json('data.id');
        $this->postJson($this->url('/controversias'), $this->controversia(['tipo' => 'colectiva', 'instancia' => 'tribunal_servicio_civil']))->assertCreated();
        $afiliacion = $this->postJson($this->url('/afiliaciones'), $this->afiliacion())->assertCreated()->json('data.id');

        $this->assertDatabaseHas('controversias', ['id' => $individual, 'registrado_por' => $admin->id, 'usuario_id' => $this->persona->id]);
        $this->assertDatabaseHas('afiliaciones', ['id' => $afiliacion, 'tipo' => 'sindicato', 'registrado_por' => $admin->id]);

        $this->patchJson($this->url('/controversias/'.$individual), ['estado' => 'resuelta', 'fecha_resolucion' => '2025-08-01', 'resultado' => 'Fundado'])->assertOk()
            ->assertJsonPath('data.values.estado', 'resuelta');
        $this->patchJson($this->url('/afiliaciones/'.$afiliacion), ['fecha_fin' => '2025-12-31'])->assertOk()->assertJsonPath('data.values.fecha_fin', '2025-12-31');

        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(2, 'data.controversias')
            ->assertJsonCount(1, 'data.afiliaciones')
            ->assertJsonCount(4, 'data.tipos')
            ->assertJsonPath('data.afiliaciones.0.values.registrado_por', trim($admin->nombres.' '.$admin->apellido_paterno.' '.$admin->apellido_materno));
    }

    public function test_only_union_affiliations_are_exposed_or_managed(): void
    {
        $this->actuarComo();
        $eps = Afiliacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'eps', 'entidad' => 'EPS privada']);

        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data.afiliaciones');
        $this->assertStringNotContainsString('EPS privada', $this->getJson($this->url())->getContent());
        $this->patchJson($this->url('/afiliaciones/'.$eps->id), ['entidad' => 'Otra'])->assertNotFound();
        $this->postJson($this->url('/afiliaciones/'.$eps->id.'/anular'), ['motivo_anulacion' => 'No corresponde'])->assertNotFound();
        // El cliente no elige el tipo de afiliación.
        $this->postJson($this->url('/afiliaciones'), $this->afiliacion(['tipo' => 'eps']))->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->assertFalse($eps->refresh()->anulado);
    }

    public function test_upload_links_each_type_only_to_its_matching_record(): void
    {
        $this->actuarComo();

        // a) individual: crea la controversia enlazada; no acepta una colectiva.
        $doc = $this->subirTipo('S09_A_CONTROVERSIAS_INDIVIDUALES')->assertCreated()->assertJsonPath('data.formulario_datos', 'controversias')->json('data');
        $this->postJson($this->url('/controversias'), $this->controversia(['tipo' => 'colectiva', 'documento_id' => $doc['id']]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $id = $this->postJson($this->url('/controversias'), $this->controversia(['documento_id' => $doc['id']]))->assertCreated()->json('data.id');
        $this->assertSame($doc['id'], Controversia::query()->find($id)->documento_id);
        // Un documento acredita un solo registro.
        $this->postJson($this->url('/controversias'), $this->controversia(['documento_id' => $doc['id']]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        // Con documento a) enlazado, la controversia no puede pasar a colectiva.
        $this->patchJson($this->url('/controversias/'.$id), ['tipo' => 'colectiva'])->assertUnprocessable()->assertJsonValidationErrors('tipo');

        // b) colectiva: al subir se enlaza a una existente; una individual se rechaza.
        $colectiva = Controversia::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'colectiva', 'instancia' => 'poder_judicial', 'documento_id' => null]);
        $individual = Controversia::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'individual', 'instancia' => 'entidad', 'documento_id' => null]);
        $this->subirTipo('S09_B_CONTROVERSIAS_COLECTIVAS', ['enlace_id' => $individual->id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $doc = $this->subirTipo('S09_B_CONTROVERSIAS_COLECTIVAS', ['enlace_id' => $colectiva->id])->assertCreated()->assertJsonPath('data.enlazado', true)->json('data');
        $this->assertSame($doc['id'], $colectiva->refresh()->documento_id);
        $this->assertNull($individual->refresh()->documento_id);

        // c) afiliación: solo a sindicato; ni controversias ni afiliaciones de otro tipo.
        $eps = Afiliacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'eps', 'documento_id' => null]);
        $this->subirTipo('S09_C_AFILIACION_SINDICATO', ['enlace_id' => $eps->id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->subirTipo('S09_C_AFILIACION_SINDICATO', ['enlace_id' => $individual->id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $doc = $this->subirTipo('S09_C_AFILIACION_SINDICATO')->assertCreated()->assertJsonPath('data.formulario_datos', 'afiliaciones')->json('data');
        $this->postJson($this->url('/controversias'), $this->controversia(['documento_id' => $doc['id']]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $id = $this->postJson($this->url('/afiliaciones'), $this->afiliacion(['documento_id' => $doc['id']]))->assertCreated()->json('data.id');
        $this->assertSame([$doc['id'], 'sindicato'], array_values(Afiliacion::query()->find($id)->only(['documento_id', 'tipo'])));

        // Otros: solo metadatos, sin formulario ni enlace.
        $doc = $this->subirTipo('S09_OTROS_UNIVERSIDAD')->assertCreated()->assertJsonPath('data.formulario_datos', null)->json('data');
        $this->subirTipo('S09_OTROS_UNIVERSIDAD', ['enlace_id' => $individual->id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->postJson($this->url('/afiliaciones'), $this->afiliacion(['documento_id' => $doc['id']]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');

        // Relación trabajador → legajo → sección 09 → tipo; folios correlativos de la sección.
        $documentos = DocumentoUsuario::query()->where('usuario_id', $this->persona->id)->get();
        $this->assertCount(4, $documentos);
        $this->assertTrue($documentos->every(fn (DocumentoUsuario $d): bool => $d->seccion_legajo_id === 9 && $d->legajo_id === Legajo::query()->value('id') && $d->estado === 'aceptado'));
        $this->assertSame([1, 2, 3, 4], $documentos->pluck('folio_inicio')->sort()->values()->all());
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tipos.0.documentos', 1)->assertJsonPath('data.tipos.1.documentos', 1);
    }

    public function test_documents_can_be_received_and_viewed_with_access_log(): void
    {
        $this->actuarComo();
        $doc = $this->subirTipo('S09_C_AFILIACION_SINDICATO')->assertCreated()->json('data');
        $base = '/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$doc['id'];

        $this->postJson($base.'/recepcionar')->assertOk()->assertJsonPath('data.estado_visible', 'aceptado');
        $this->get($base.'/archivo')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('bitacora_accesos_legajo', ['documento_id' => $doc['id'], 'accion' => 'ver']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tipos.2.estado', 'aceptado');
    }

    public function test_sensitive_access_is_required_for_every_action(): void
    {
        $controversia = Controversia::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'individual', 'instancia' => 'entidad']);
        $this->getJson($this->url())->assertUnauthorized();

        // Ve y edita legajos, sube documentos y administra, pero sin datos sensibles: nada de la 09.
        $this->actuarConPermisos(['legajo.ver', 'legajo.editar', 'documento.subir', 'sistema.administrar'], false);
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url('/controversias'), $this->controversia())->assertForbidden();
        $this->patchJson($this->url('/controversias/'.$controversia->id), ['materia' => 'Cambio'])->assertForbidden();
        $this->postJson($this->url('/controversias/'.$controversia->id.'/anular'), ['motivo_anulacion' => 'Sin permiso'])->assertForbidden();
        $this->subirTipo('S09_A_CONTROVERSIAS_INDIVIDUALES')->assertForbidden();
        $this->getJson('/api/v1/controversias')->assertForbidden();
        $this->getJson('/api/v1/afiliaciones')->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/controversias')->assertForbidden();

        // Con datos sensibles pero sin legajo.editar: lee, no registra.
        $this->actuarConPermisos(['legajo.ver'], true);
        $this->getJson($this->url())->assertOk();
        $this->postJson($this->url('/controversias'), $this->controversia())->assertForbidden();

        // Registra y corrige, pero anular es solo de administración.
        $this->actuarConPermisos(['legajo.ver', 'legajo.editar'], true);
        $this->postJson($this->url('/controversias'), $this->controversia())->assertCreated();
        $this->patchJson($this->url('/controversias/'.$controversia->id), ['materia' => 'Materia corregida'])->assertOk();
        $this->postJson($this->url('/controversias/'.$controversia->id.'/anular'), ['motivo_anulacion' => 'Sin permiso'])->assertForbidden();
        $this->assertFalse($controversia->refresh()->anulado);

        // El asistente (sin datos sensibles) no ve la sección.
        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_annulment_requires_reason_keeps_the_record_and_hides_it(): void
    {
        $admin = $this->actuarComo();
        $id = $this->postJson($this->url('/afiliaciones'), $this->afiliacion())->assertCreated()->json('data.id');

        $this->postJson($this->url('/afiliaciones/'.$id.'/anular'), [])->assertUnprocessable()->assertJsonValidationErrors('motivo_anulacion');
        $this->postJson($this->url('/afiliaciones/'.$id.'/anular'), ['motivo_anulacion' => 'Registrada por error'])->assertOk()
            ->assertJsonPath('data.values.anulado', true)->assertJsonPath('data.values.motivo_anulacion', 'Registrada por error');
        $this->postJson($this->url('/afiliaciones/'.$id.'/anular'), ['motivo_anulacion' => 'Otra vez'])->assertConflict();
        $this->patchJson($this->url('/afiliaciones/'.$id), ['entidad' => 'Cambio'])->assertConflict();

        $this->assertDatabaseHas('afiliaciones', ['id' => $id, 'anulado' => true, 'registrado_por' => $admin->id]);
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data.afiliaciones');

        // Un registro de otra persona no se anula por la ruta de esta.
        $otra = Controversia::factory()->create(['tipo' => 'individual', 'instancia' => 'entidad']);
        $this->postJson($this->url('/controversias/'.$otra->id.'/anular'), ['motivo_anulacion' => 'Intento ajeno'])->assertNotFound();
    }

    public function test_validates_schema_fields_dates_and_rejects_author_or_annulment_fields(): void
    {
        $this->actuarComo();
        $this->postJson($this->url('/controversias'), $this->controversia(['tipo' => 'grupal']))->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->postJson($this->url('/controversias'), $this->controversia(['instancia' => 'fiscalia']))->assertUnprocessable()->assertJsonValidationErrors('instancia');
        $this->postJson($this->url('/controversias'), $this->controversia(['estado' => 'apelada']))->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->postJson($this->url('/controversias'), $this->controversia(['materia' => null]))->assertUnprocessable()->assertJsonValidationErrors('materia');
        $this->postJson($this->url('/controversias'), $this->controversia(['fecha_resolucion' => '2025-01-01']))->assertUnprocessable()->assertJsonValidationErrors('fecha_resolucion');
        $this->postJson($this->url('/controversias'), $this->controversia(['registrado_por' => $this->persona->id]))->assertUnprocessable()->assertJsonValidationErrors('registrado_por');
        $this->postJson($this->url('/controversias'), $this->controversia(['anulado' => true]))->assertUnprocessable()->assertJsonValidationErrors('anulado');
        $this->postJson($this->url('/afiliaciones'), $this->afiliacion(['entidad' => '']))->assertUnprocessable()->assertJsonValidationErrors('entidad');
        $this->postJson($this->url('/afiliaciones'), $this->afiliacion(['fecha_fin' => '2023-12-31']))->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');

        $id = $this->postJson($this->url('/controversias'), $this->controversia())->assertCreated()->json('data.id');
        $this->patchJson($this->url('/controversias/'.$id), ['fecha_resolucion' => '2025-02-01'])->assertUnprocessable()->assertJsonValidationErrors('fecha_resolucion');

        // Sin legajo abierto no se registra.
        $sinLegajo = Usuario::factory()->create();
        $this->postJson($this->url('/controversias', $sinLegajo), $this->controversia())->assertConflict();
        $this->getJson($this->url('', $sinLegajo))->assertOk()->assertJsonPath('data.legajo', null);
    }

    public function test_generic_crud_is_read_only_and_never_deletes(): void
    {
        $this->actuarComo();
        foreach (['controversias' => Controversia::factory()->create(), 'afiliaciones' => Afiliacion::factory()->create()] as $recurso => $registro) {
            $antes = $registro->refresh()->getAttributes();
            $this->getJson('/api/v1/'.$recurso)->assertOk();
            $this->getJson('/api/v1/'.$recurso.'/'.$registro->id)->assertOk();
            $this->postJson('/api/v1/'.$recurso, [])->assertStatus(405);
            $this->patchJson('/api/v1/'.$recurso.'/'.$registro->id, ['anulado' => true, 'registrado_por' => $this->persona->id])->assertStatus(405);
            $this->putJson('/api/v1/'.$recurso.'/'.$registro->id, [])->assertStatus(405);
            $this->deleteJson('/api/v1/'.$recurso.'/'.$registro->id)->assertStatus(405);
            $this->assertEquals($antes, $registro->refresh()->getAttributes());
        }
    }
}
