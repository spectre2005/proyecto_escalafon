<?php

namespace Tests\Feature;

use App\Models\Afiliacion;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\LicenciaPermiso;
use App\Models\RegistroSstBienestar;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\Seccion05CatalogoSeeder;
use Database\Seeders\Seccion09CatalogoSeeder;
use Database\Seeders\Seccion10CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Sección 10 (SST y Bienestar Social) desde la ficha: catálogo 10.1 y 10.2, registros de SST y bienestar, afiliación a
 * EPS o seguro social (sin sindicato), consulta del descanso médico de la sección 05, carga y enlace de PDF, permisos de
 * datos sensibles y de salud, anulación y CRUD genérico cerrado.
 */
class Seccion10Test extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    /** @var array<int, int> Siguiente folio por sección. */
    private array $folios = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('legajo.disco_documentos'));
        $this->seed(Seccion10CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create(['numero_doc_identidad' => '99001001']);
        Legajo::query()->create(['usuario_id' => $this->persona->id, 'numero_legajo' => 'TEST-S10-001', 'fecha_apertura' => today()->subYear()->toDateString()]);
    }

    private function url(string $resto = '', ?Usuario $persona = null): string
    {
        return '/api/v1/expedientes/'.($persona ?? $this->persona)->id.'/seccion-10'.$resto;
    }

    private function tipoDoc(string $codigo): TipoDocumento
    {
        return TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->firstOrFail();
    }

    private function subirTipo(string $codigo, array $cambios = [])
    {
        $tipo = $this->tipoDoc($codigo);
        $folio = $this->folios[$tipo->seccion_legajo_id] ??= 1;
        $respuesta = $this->subir($this->persona, $this->metadatos($tipo, $folio, $folio, $cambios));
        if ($respuesta->status() === 201) {
            $this->folios[$tipo->seccion_legajo_id]++;
        }

        return $respuesta;
    }

    private function registro(array $cambios = []): array
    {
        return [...['tipo' => 'entrega_reglamento_sst', 'fecha' => '2025-02-10', 'descripcion' => 'Entrega en la inducción'], ...$cambios];
    }

    private function afiliacion(array $cambios = []): array
    {
        return [...['tipo' => 'eps', 'entidad' => 'EPS de prueba', 'numero_afiliacion' => 'E-1', 'fecha_inicio' => '2024-03-01'], ...$cambios];
    }

    private function actuarConPermisos(array $permisos, bool $sensibles): void
    {
        $codigo = 'rol_'.md5(implode(',', $permisos).($sensibles ? 's' : 'n'));
        config(['permisos.roles.'.$codigo => $permisos]);
        Sanctum::actingAs($this->asignarRol(Usuario::factory()->create(), $codigo, [], ['ve_datos_sensibles' => $sensibles])->refresh());
    }

    public function test_catalog_follows_the_directive_and_marks_only_health_types(): void
    {
        $this->seed(Seccion10CatalogoSeeder::class);
        $this->assertSame(['10.1', '10.2'], SubseccionLegajo::query()->where('seccion_legajo_id', 10)->orderBy('orden')->pluck('codigo')->all());
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 10)->with('subseccionLegajo')->orderBy('orden')->get();

        $this->assertSame([
            'S10_1A_REGLAMENTO_SST' => ['10.1', 'registros_sst_bienestar'], 'S10_1B_SIMULACROS' => ['10.1', 'registros_sst_bienestar'],
            'S10_1C_ASISTENCIA_CAPACITACION' => ['10.1', 'registros_sst_bienestar'], 'S10_1X_ACCIDENTES_ENFERMEDADES' => ['10.1', 'registros_sst_bienestar'],
            'S10_2A_SEGUROS' => ['10.2', 'registros_sst_bienestar'], 'S10_2B_SUBSIDIOS' => ['10.2', 'registros_sst_bienestar'],
            'S10_2D_AFILIACION_EPS' => ['10.2', 'afiliaciones'], 'S10_OTROS_UNIVERSIDAD' => [null, null],
        ], $tipos->mapWithKeys(fn (TipoDocumento $t): array => [$t->codigo => [$t->subseccionLegajo?->codigo, $t->formulario_datos]])->all());
        // Solo accidentes y enfermedades son dato de salud; ninguno es obligatorio. El descanso médico no tiene tipo propio.
        $this->assertSame(['S10_1X_ACCIDENTES_ENFERMEDADES'], $tipos->where('es_dato_salud', true)->pluck('codigo')->values()->all());
        $this->assertTrue($tipos->every(fn (TipoDocumento $t): bool => ! $t->obligatorio && $t->permite_multiples));
        $this->assertFalse($tipos->contains(fn (TipoDocumento $t): bool => str_contains(mb_strtolower($t->nombre), 'descanso')));
    }

    public function test_registers_sst_and_welfare_records_and_validates_types(): void
    {
        $admin = $this->actuarComo();
        foreach (['entrega_reglamento_sst', 'entrenamiento_simulacro', 'capacitacion_sst', 'accidente_trabajo', 'enfermedad_ocupacional', 'tramite_seguro', 'tramite_subsidio'] as $tipo) {
            $this->postJson($this->url('/registros'), $this->registro(['tipo' => $tipo]))->assertCreated()->assertJsonPath('data.values.estado_tramite', 'no_aplica');
        }
        $this->assertSame(7, RegistroSstBienestar::query()->where('registrado_por', $admin->id)->count());

        // examen_medico_realizado (Anexo 05) y valores fuera del esquema: no se registran.
        $this->postJson($this->url('/registros'), $this->registro(['tipo' => 'examen_medico_realizado']))->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->postJson($this->url('/registros'), $this->registro(['tipo' => 'incidente']))->assertUnprocessable()->assertJsonValidationErrors('tipo');
        // El estado solo en trámites.
        $this->postJson($this->url('/registros'), $this->registro(['estado_tramite' => 'aprobado']))->assertUnprocessable()->assertJsonValidationErrors('estado_tramite');
        $id = $this->postJson($this->url('/registros'), $this->registro(['tipo' => 'tramite_subsidio', 'estado_tramite' => 'en_tramite']))->assertCreated()->json('data.id');
        $this->patchJson($this->url('/registros/'.$id), ['estado_tramite' => 'aprobado'])->assertOk()->assertJsonPath('data.values.estado_tramite', 'aprobado');
        $this->patchJson($this->url('/registros/'.$id), ['tipo' => 'capacitacion_sst'])->assertUnprocessable()->assertJsonValidationErrors('estado_tramite');
        $this->postJson($this->url('/registros'), $this->registro(['fecha' => null]))->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->postJson($this->url('/registros'), $this->registro(['registrado_por' => $this->persona->id]))->assertUnprocessable()->assertJsonValidationErrors('registrado_por');

        $data = $this->getJson($this->url())->assertOk()->json('data');
        $this->assertCount(8, $data['registros']);
        $this->assertSame(['accidente_trabajo', 'enfermedad_ocupacional'], collect($data['registros'])->where('salud', true)->pluck('values.tipo')->sort()->values()->all());
    }

    public function test_only_eps_and_social_security_affiliations_belong_to_this_section(): void
    {
        $this->actuarComo();
        $sindicato = Afiliacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'sindicato', 'entidad' => 'Sindicato visible en la 09']);
        $id = $this->postJson($this->url('/afiliaciones'), $this->afiliacion())->assertCreated()->json('data.id');
        $this->postJson($this->url('/afiliaciones'), $this->afiliacion(['tipo' => 'seguro_social', 'entidad' => 'Seguro Social']))->assertCreated();
        $this->postJson($this->url('/afiliaciones'), $this->afiliacion(['tipo' => 'sindicato']))->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->postJson($this->url('/afiliaciones'), $this->afiliacion(['fecha_fin' => '2024-01-01']))->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
        $this->patchJson($this->url('/afiliaciones/'.$id), ['tipo' => 'sindicato'])->assertUnprocessable();

        $respuesta = $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'data.afiliaciones');
        $this->assertStringNotContainsString('Sindicato visible en la 09', $respuesta->getContent());
        $this->patchJson($this->url('/afiliaciones/'.$sindicato->id), ['entidad' => 'Otra'])->assertNotFound();
        $this->postJson($this->url('/afiliaciones/'.$sindicato->id.'/anular'), ['motivo_anulacion' => 'No corresponde'])->assertNotFound();
        // La sección 09 sigue viendo solo el sindicato.
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-09')->assertOk()->assertJsonCount(1, 'data.afiliaciones')
            ->assertJsonPath('data.afiliaciones.0.values.entidad', 'Sindicato visible en la 09');
    }

    public function test_upload_links_each_document_type_only_to_its_record_type(): void
    {
        $this->actuarComo();

        // a) Reglamento: crea su registro; no acepta otro tipo de registro.
        $doc = $this->subirTipo('S10_1A_REGLAMENTO_SST')->assertCreated()->assertJsonPath('data.formulario_datos', 'registros_sst_bienestar')->json('data');
        $this->postJson($this->url('/registros'), $this->registro(['tipo' => 'entrenamiento_simulacro', 'documento_id' => $doc['id']]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $id = $this->postJson($this->url('/registros'), $this->registro(['documento_id' => $doc['id']]))->assertCreated()->json('data.id');
        $this->assertSame($doc['id'], RegistroSstBienestar::query()->find($id)->documento_id);
        $this->postJson($this->url('/registros'), $this->registro(['documento_id' => $doc['id']]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $this->patchJson($this->url('/registros/'.$id), ['tipo' => 'capacitacion_sst'])->assertUnprocessable()->assertJsonValidationErrors('tipo');

        // Accidentes y enfermedades: el documento (dato de salud) acepta ambos tipos y ninguno más.
        $accidente = RegistroSstBienestar::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'enfermedad_ocupacional', 'documento_id' => null]);
        $seguro = RegistroSstBienestar::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'tramite_seguro', 'documento_id' => null]);
        $this->subirTipo('S10_1X_ACCIDENTES_ENFERMEDADES', ['enlace_id' => $seguro->id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $doc = $this->subirTipo('S10_1X_ACCIDENTES_ENFERMEDADES', ['enlace_id' => $accidente->id])->assertCreated()->assertJsonPath('data.enlazado', true)->json('data');
        $this->assertSame($doc['id'], $accidente->refresh()->documento_id);

        // 10.2 a) seguros enlaza solo trámites de seguro.
        $doc = $this->subirTipo('S10_2A_SEGUROS', ['enlace_id' => $seguro->id])->assertCreated()->json('data');
        $this->assertSame($doc['id'], $seguro->refresh()->documento_id);

        // 10.2 d) afiliación: solo EPS o seguro social; nunca sindicato ni un registro de SST.
        $sindicato = Afiliacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'sindicato', 'documento_id' => null]);
        $eps = Afiliacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'eps', 'documento_id' => null]);
        $simulacro = RegistroSstBienestar::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'entrenamiento_simulacro', 'documento_id' => null]);
        $this->subirTipo('S10_2D_AFILIACION_EPS', ['enlace_id' => $sindicato->id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->subirTipo('S10_2D_AFILIACION_EPS', ['enlace_id' => $simulacro->id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $doc = $this->subirTipo('S10_2D_AFILIACION_EPS', ['enlace_id' => $eps->id])->assertCreated()->json('data');
        $this->assertSame($doc['id'], $eps->refresh()->documento_id);
        $this->assertNull($sindicato->refresh()->documento_id);

        // El documento c) de la sección 09 tampoco acepta una afiliación EPS (la 09 no cambia).
        $this->seed(Seccion09CatalogoSeeder::class);
        $eps2 = Afiliacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'eps', 'documento_id' => null]);
        $this->subirTipo('S09_C_AFILIACION_SINDICATO', ['enlace_id' => $eps2->id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');

        // Otros: solo metadatos.
        $doc = $this->subirTipo('S10_OTROS_UNIVERSIDAD')->assertCreated()->assertJsonPath('data.formulario_datos', null)->json('data');
        $this->postJson($this->url('/registros'), $this->registro(['documento_id' => $doc['id']]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');

        // Persona → legajo → sección 10 → tipo, con folios correlativos de la sección.
        $documentos = DocumentoUsuario::query()->where('seccion_legajo_id', 10)->get();
        $this->assertCount(5, $documentos);
        $this->assertSame([1, 2, 3, 4, 5], $documentos->pluck('folio_inicio')->sort()->values()->all());
        $this->assertTrue($documentos->every(fn (DocumentoUsuario $d): bool => $d->usuario_id === $this->persona->id && $d->legajo_id === Legajo::query()->value('id')));
    }

    public function test_health_documents_are_received_and_viewed_with_access_log(): void
    {
        $this->actuarComo();
        $doc = $this->subirTipo('S10_1X_ACCIDENTES_ENFERMEDADES')->assertCreated()->json('data');
        $base = '/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$doc['id'];
        $this->postJson($base.'/recepcionar')->assertOk()->assertJsonPath('data.estado_visible', 'recepcionado');
        $this->get($base.'/archivo')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('bitacora_accesos_legajo', ['documento_id' => $doc['id'], 'accion' => 'ver']);

        // Sin datos sensibles no se ve ni se recepciona.
        $this->actuarConPermisos(['legajo.ver', 'documento.recepcionar'], false);
        $this->get($base.'/archivo')->assertForbidden();
    }

    public function test_shows_medical_leave_from_section_05_read_only_without_duplicating(): void
    {
        $this->seed(Seccion05CatalogoSeeder::class);
        $this->actuarComo();
        $doc = $this->subirTipo('S05_DESCANSO_MEDICO')->assertCreated()->json('data');
        $descanso = LicenciaPermiso::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'descanso_medico', 'fecha_inicio' => '2025-05-02', 'fecha_fin' => '2025-05-04', 'dias' => 3, 'documento_id' => $doc['id']]);
        LicenciaPermiso::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'descanso_medico', 'anulado' => true, 'motivo_anulacion' => 'Duplicado']);
        LicenciaPermiso::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'permiso', 'forma_permiso' => 'papeleta']);
        $antes = [LicenciaPermiso::query()->count(), DocumentoUsuario::query()->count(), RegistroSstBienestar::query()->count()];

        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(1, 'data.descansos_medicos')
            ->assertJsonPath('data.descansos_medicos.0.id', $descanso->id)
            ->assertJsonPath('data.descansos_medicos.0.values.dias', 3)
            ->assertJsonPath('data.descansos_medicos.0.documento.id', $doc['id'])
            ->assertJsonPath('data.descansos_medicos.0.documento.tiene_archivo', true);
        // Solo consulta: no hay registro ni PDF nuevos, y la 10 no tiene ruta para registrarlo.
        $this->assertSame($antes, [LicenciaPermiso::query()->count(), DocumentoUsuario::query()->count(), RegistroSstBienestar::query()->count()]);
        $this->postJson($this->url('/registros'), $this->registro(['tipo' => 'descanso_medico']))->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->get('/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$doc['id'].'/archivo')->assertOk();

        // Sin datos sensibles, la consulta no se entrega.
        $this->actuarConPermisos(['legajo.ver'], false);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_sensitive_access_is_required_for_every_action(): void
    {
        $registro = RegistroSstBienestar::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'entrenamiento_simulacro']);
        $this->getJson($this->url())->assertUnauthorized();

        $this->actuarConPermisos(['legajo.ver', 'legajo.editar', 'documento.subir', 'sistema.administrar'], false);
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url('/registros'), $this->registro())->assertForbidden();
        $this->patchJson($this->url('/registros/'.$registro->id), ['descripcion' => 'x'])->assertForbidden();
        $this->postJson($this->url('/registros/'.$registro->id.'/anular'), ['motivo_anulacion' => 'Sin permiso'])->assertForbidden();
        $this->postJson($this->url('/afiliaciones'), $this->afiliacion())->assertForbidden();
        $this->subirTipo('S10_1A_REGLAMENTO_SST')->assertForbidden();
        $this->getJson('/api/v1/registros-sst-bienestar')->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/registros-sst-bienestar')->assertForbidden();

        $this->actuarConPermisos(['legajo.ver'], true);
        $this->getJson($this->url())->assertOk();
        $this->postJson($this->url('/registros'), $this->registro())->assertForbidden();

        $this->actuarConPermisos(['legajo.ver', 'legajo.editar'], true);
        $this->postJson($this->url('/registros'), $this->registro())->assertCreated();
        $this->postJson($this->url('/registros/'.$registro->id.'/anular'), ['motivo_anulacion' => 'Sin permiso'])->assertForbidden();
        $this->assertFalse($registro->refresh()->anulado);

        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_annulment_requires_reason_keeps_the_record_and_hides_it(): void
    {
        $admin = $this->actuarComo();
        $id = $this->postJson($this->url('/registros'), $this->registro(['tipo' => 'accidente_trabajo']))->assertCreated()->json('data.id');
        $afiliacion = $this->postJson($this->url('/afiliaciones'), $this->afiliacion())->assertCreated()->json('data.id');

        $this->postJson($this->url('/registros/'.$id.'/anular'), [])->assertUnprocessable()->assertJsonValidationErrors('motivo_anulacion');
        $this->postJson($this->url('/registros/'.$id.'/anular'), ['motivo_anulacion' => 'Registrado por error'])->assertOk()->assertJsonPath('data.values.anulado', true);
        $this->postJson($this->url('/registros/'.$id.'/anular'), ['motivo_anulacion' => 'Otra vez'])->assertConflict();
        $this->patchJson($this->url('/registros/'.$id), ['descripcion' => 'Cambio'])->assertConflict();
        $this->postJson($this->url('/afiliaciones/'.$afiliacion.'/anular'), ['motivo_anulacion' => 'Afiliación duplicada'])->assertOk();

        $this->assertDatabaseHas('registros_sst_bienestar', ['id' => $id, 'anulado' => true, 'motivo_anulacion' => 'Registrado por error', 'registrado_por' => $admin->id]);
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data.registros')->assertJsonCount(0, 'data.afiliaciones');

        $ajeno = RegistroSstBienestar::factory()->create(['tipo' => 'capacitacion_sst']);
        $this->postJson($this->url('/registros/'.$ajeno->id.'/anular'), ['motivo_anulacion' => 'Intento ajeno'])->assertNotFound();

        $sinLegajo = Usuario::factory()->create();
        $this->postJson($this->url('/registros', $sinLegajo), $this->registro())->assertConflict();
    }

    public function test_generic_crud_is_read_only_and_never_deletes(): void
    {
        $this->actuarComo();
        $registro = RegistroSstBienestar::factory()->create();
        $antes = $registro->refresh()->getAttributes();
        $this->getJson('/api/v1/registros-sst-bienestar')->assertOk();
        $this->getJson('/api/v1/registros-sst-bienestar/'.$registro->id)->assertOk();
        $this->postJson('/api/v1/registros-sst-bienestar', [])->assertStatus(405);
        $this->patchJson('/api/v1/registros-sst-bienestar/'.$registro->id, ['anulado' => true])->assertStatus(405);
        $this->deleteJson('/api/v1/registros-sst-bienestar/'.$registro->id)->assertStatus(405);
        $this->assertEquals($antes, $registro->refresh()->getAttributes());
    }
}
