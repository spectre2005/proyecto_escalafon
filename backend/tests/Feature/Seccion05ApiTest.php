<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\GoceVacacional;
use App\Models\Legajo;
use App\Models\LicenciaPermiso;
use App\Models\PeriodoVacacional;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\Seccion05;
use Database\Seeders\Seccion05CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Sección 05 (Movimientos del personal): catálogo del Anexo 01, registro y consulta de movimientos, carga de
 * documentos por el mecanismo común con su enlace al movimiento, anulación y permisos (incluido el descanso médico
 * como dato de salud).
 */
class Seccion05ApiTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('legajo.disco_documentos'));
        $this->seed(Seccion05CatalogoSeeder::class);
    }

    private function url(Usuario $persona, string $ruta = ''): string
    {
        return '/api/v1/expedientes/'.$persona->id.'/seccion-05'.($ruta === '' ? '' : '/'.$ruta);
    }

    private function personaConLegajo(): Usuario
    {
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '99000501']);
        Legajo::factory()->create(['usuario_id' => $persona->id]);

        return $persona;
    }

    private function tipo05(string $codigo): TipoDocumento
    {
        return TipoDocumento::query()->where('seccion_legajo_id', 5)->where('codigo', $codigo)->where('activo', true)->firstOrFail();
    }

    private function licencia(array $cambios = []): array
    {
        return [...['tipo' => 'licencia_paternidad', 'fecha_inicio' => '2026-03-02', 'fecha_fin' => '2026-03-11', 'dias' => 10, 'numero_documento' => 'MEM-010-2026'], ...$cambios];
    }

    public function test_catalog_follows_annex_01_and_is_idempotent(): void
    {
        $this->seed(Seccion05CatalogoSeeder::class);
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 5)->where('activo', true)->orderBy('orden')->get();

        $this->assertSame(array_column(Seccion05CatalogoSeeder::TIPOS, 0), $tipos->pluck('codigo')->all());
        $this->assertSame(array_keys(Seccion05::TIPOS_DOCUMENTO), $tipos->pluck('codigo')->all());
        $this->assertTrue($tipos->every(fn (TipoDocumento $tipo): bool => $tipo->permite_multiples && ! $tipo->obligatorio && $tipo->subseccion_legajo_id === null));
        $this->assertSame(['S05_DESCANSO_MEDICO'], $tipos->where('es_dato_salud', true)->pluck('codigo')->values()->all());
        $this->assertSame(['S05_OTROS_UNIVERSIDAD'], $tipos->where('es_hook_universidad', true)->pluck('codigo')->values()->all());
        $this->assertNull($this->tipo05('S05_D_ROL_VACACIONES')->formulario_datos);

        // Un tipo que no es de la directiva se desactiva, no se borra.
        $ajeno = TipoDocumento::factory()->create(['seccion_legajo_id' => 5, 'codigo' => 'S05_AJENO', 'activo' => true]);
        $this->seed(Seccion05CatalogoSeeder::class);
        $this->assertFalse($ajeno->refresh()->activo);
        $this->assertSame(8, TipoDocumento::query()->where('seccion_legajo_id', 5)->where('activo', true)->count());
    }

    public function test_show_groups_document_types_by_block_without_open_legajo(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();

        $response = $this->getJson($this->url($persona))->assertOk()
            ->assertJsonPath('data.legajo', null)->assertJsonPath('data.ve_datos_salud', true)
            ->assertJsonCount(8, 'data.tipos')->assertJsonPath('data.tipos.0.literal', 'a')->assertJsonPath('data.tipos.0.estado', 'pendiente')
            ->assertJsonPath('data.licencias', [])->assertJsonPath('data.periodos', [])->assertJsonPath('data.goces', []);
        $bloques = collect($response->json('data.tipos'))->groupBy('bloque')->map(fn ($tipos) => $tipos->pluck('literal')->all())->all();
        $this->assertSame(['licencias' => ['a', 'e', null, null], 'vacaciones' => ['b', 'c', 'd'], 'otros' => [null]], $bloques);

        $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia())->assertConflict();
        $this->assertDatabaseCount('licencias_permisos', 0);
    }

    public function test_leaves_and_permissions_are_validated_and_a_worker_has_many(): void
    {
        $autor = $this->actuarComo();
        $persona = $this->personaConLegajo();
        $url = $this->url($persona, 'licencias-permisos');

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['tipo', 'fecha_inicio', 'fecha_fin', 'dias']);
        $this->postJson($url, $this->licencia(['tipo' => 'vacaciones']))->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->postJson($url, $this->licencia(['fecha_fin' => '2026-03-01']))->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
        $this->postJson($url, $this->licencia(['dias' => 11]))->assertUnprocessable()->assertJsonValidationErrors('dias');
        $this->postJson($url, $this->licencia(['registrado_por' => $persona->id]))->assertUnprocessable()->assertJsonValidationErrors('registrado_por');
        $this->postJson($url, $this->licencia(['anulado' => true]))->assertUnprocessable()->assertJsonValidationErrors('anulado');

        $this->postJson($url, $this->licencia())->assertCreated()->assertJsonPath('data.registrado_por', $autor->id)
            ->assertJsonPath('data.usuario_id', $persona->id)->assertJsonPath('data.con_goce', true);
        // Permiso: la forma (papeleta, memorándum u otro) es obligatoria y solo la llevan los permisos.
        $permiso = ['tipo' => 'permiso', 'fecha_inicio' => '2026-04-06', 'fecha_fin' => '2026-04-06', 'dias' => 0, 'con_goce' => false];
        $this->postJson($url, $permiso)->assertUnprocessable()->assertJsonValidationErrors('forma_permiso');
        $this->postJson($url, [...$permiso, 'forma_permiso' => 'oficio'])->assertUnprocessable()->assertJsonValidationErrors('forma_permiso');
        $this->postJson($url, $this->licencia(['forma_permiso' => 'papeleta']))->assertUnprocessable()->assertJsonValidationErrors('forma_permiso');
        $this->postJson($url, $this->licencia(['tipo' => 'suspension', 'forma_permiso' => 'memorandum']))->assertUnprocessable()->assertJsonValidationErrors('forma_permiso');
        // Permiso por horas (papeleta): 0 días dentro de la fecha.
        $this->postJson($url, [...$permiso, 'forma_permiso' => 'papeleta'])->assertCreated()->assertJsonPath('data.forma_permiso', 'papeleta');
        $this->postJson($url, $this->licencia(['tipo' => 'suspension', 'con_goce' => false]))->assertCreated()->assertJsonPath('data.forma_permiso', null);

        $this->getJson($this->url($persona))->assertOk()->assertJsonCount(3, 'data.licencias')
            ->assertJsonPath('data.licencias.0.values.tipo', 'permiso')->assertJsonPath('data.licencias.0.values.forma_permiso', 'papeleta')->assertJsonPath('data.licencias.0.values.registrado_por', trim($autor->nombres.' '.$autor->apellido_paterno.' '.$autor->apellido_materno));
    }

    public function test_vacation_periods_and_leave_days_belong_to_the_person(): void
    {
        $this->actuarComo();
        $persona = $this->personaConLegajo();
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id]);
        $ajeno = VinculoLaboral::factory()->create();
        $periodos = $this->url($persona, 'periodos-vacacionales');

        $this->postJson($periodos, ['vinculo_laboral_id' => $ajeno->id, 'periodo_anio' => 2025])->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');
        $this->postJson($periodos, ['vinculo_laboral_id' => $vinculo->id, 'periodo_anio' => 1800])->assertUnprocessable()->assertJsonValidationErrors('periodo_anio');
        $id = $this->postJson($periodos, ['vinculo_laboral_id' => $vinculo->id, 'periodo_anio' => 2025])->assertCreated()->assertJsonPath('data.dias_derecho', 30)->json('data.id');
        $this->postJson($periodos, ['vinculo_laboral_id' => $vinculo->id, 'periodo_anio' => 2025])->assertUnprocessable()->assertJsonValidationErrors('periodo_anio');
        $this->postJson($periodos, ['vinculo_laboral_id' => $vinculo->id, 'periodo_anio' => 2026, 'dias_derecho' => 15])->assertCreated();

        $goces = $this->url($persona, 'goces-vacacionales');
        $periodoAjeno = PeriodoVacacional::factory()->create();
        $goce = ['periodo_vacacional_id' => $id, 'tipo' => 'goce', 'fecha_inicio' => '2026-01-05', 'fecha_fin' => '2026-01-19', 'dias' => 15];
        $this->postJson($goces, [...$goce, 'periodo_vacacional_id' => $periodoAjeno->id])->assertUnprocessable()->assertJsonValidationErrors('periodo_vacacional_id');
        $this->postJson($goces, [...$goce, 'tipo' => 'licencia'])->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->postJson($goces, [...$goce, 'dias' => 0])->assertUnprocessable()->assertJsonValidationErrors('dias');
        $this->postJson($goces, $goce)->assertCreated();
        $this->postJson($goces, [...$goce, 'tipo' => 'adelanto', 'fecha_inicio' => '2026-02-02', 'fecha_fin' => '2026-02-06', 'dias' => 5])->assertCreated();
        $this->postJson($goces, [...$goce, 'tipo' => 'postergacion', 'fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-10', 'dias' => 10])->assertCreated();

        $this->getJson($this->url($persona))->assertOk()->assertJsonCount(2, 'data.periodos')->assertJsonCount(3, 'data.goces')
            ->assertJsonPath('data.periodos.1.values.periodo_anio', 2025)->assertJsonPath('data.periodos.1.dias_registrados', 20);
    }

    public function test_document_upload_links_worker_legajo_section_type_and_movement(): void
    {
        $autor = $this->actuarComo();
        $persona = $this->personaConLegajo();
        $legajo = Legajo::query()->where('usuario_id', $persona->id)->firstOrFail();
        $licencia = $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia())->assertCreated()->json('data.id');
        $suspension = $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia(['tipo' => 'suspension']))->assertCreated()->json('data.id');
        $aprueba = $this->tipo05('S05_A_APRUEBA_LICENCIA_PERMISO');

        // a) no acredita una suspensión; b) (goces) no acredita una licencia.
        $this->subir($persona, $this->metadatos($aprueba, 1, 2, ['enlace_id' => $suspension]))->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->subir($persona, $this->metadatos($this->tipo05('S05_B_GOCE_VACACIONAL'), 1, 2, ['enlace_id' => $licencia]))->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->assertDatabaseCount('documentos_usuario', 0);

        // Folios propios de la sección 05, desde 1.
        $this->getJson('/api/v1/expedientes/'.$persona->id.'/documentos/folio-sugerido?seccion=5')->assertOk()->assertJsonPath('data.folio_sugerido', 1);
        $id = $this->subir($persona, $this->metadatos($aprueba, 1, 2, ['enlace_id' => $licencia]))->assertCreated()
            ->assertJsonPath('data.formulario_datos', 'licencias_permisos')->assertJsonPath('data.enlazado', true)->json('data.id');
        $documento = DocumentoUsuario::query()->findOrFail($id);
        $this->assertSame([$persona->id, $legajo->id, 5, $aprueba->id, $autor->id], [$documento->usuario_id, $documento->legajo_id, $documento->seccion_legajo_id, $documento->tipo_documento_id, $documento->created_by]);
        $this->assertSame($id, LicenciaPermiso::query()->findOrFail($licencia)->documento_id);
        // Ya enlazada: no recibe otro documento.
        $this->subir($persona, $this->metadatos($aprueba, 3, 3, ['enlace_id' => $licencia]))->assertUnprocessable()->assertJsonValidationErrors('enlace_id');

        // Documento subido primero y movimiento registrado después, enlazado a él.
        $rol = $this->subir($persona, $this->metadatos($this->tipo05('S05_D_ROL_VACACIONES'), 3, 3))->assertCreated()->assertJsonPath('data.formulario_datos', null)->json('data.id');
        $otorgada = $this->subir($persona, $this->metadatos($this->tipo05('S05_E_LICENCIA_OTORGADA'), 4, 5))->assertCreated()->json('data.id');
        $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia(['tipo' => 'permiso', 'forma_permiso' => 'memorandum', 'documento_id' => $otorgada]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia(['documento_id' => $rol]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia(['tipo' => 'lactancia', 'documento_id' => $otorgada]))->assertCreated()->assertJsonPath('data.documento_id', $otorgada);

        $response = $this->getJson($this->url($persona))->assertOk();
        $tipos = collect($response->json('data.tipos'))->keyBy('codigo');
        $this->assertSame(1, $tipos['S05_A_APRUEBA_LICENCIA_PERMISO']['documentos']);
        $this->assertSame('cargado', $tipos['S05_D_ROL_VACACIONES']['lista'][0]['estado_visible']);
        $this->assertSame(['permiso', ...Seccion05::LICENCIAS_OTORGADAS], $tipos['S05_A_APRUEBA_LICENCIA_PERMISO']['registros']);

        // Visualización: el PDF se sirve con sesión y queda en la bitácora.
        $this->get('/api/v1/expedientes/'.$persona->id.'/documentos/'.$id.'/archivo')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('bitacora_accesos_legajo', ['documento_id' => $id, 'usuario_id' => $autor->id, 'accion' => 'ver']);
    }

    public function test_records_are_annulled_with_reason_never_deleted(): void
    {
        $this->actuarComo();
        $persona = $this->personaConLegajo();
        $licencia = $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia())->assertCreated()->json('data.id');
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id]);
        $periodo = $this->postJson($this->url($persona, 'periodos-vacacionales'), ['vinculo_laboral_id' => $vinculo->id, 'periodo_anio' => 2025])->json('data.id');
        $goce = $this->postJson($this->url($persona, 'goces-vacacionales'), ['periodo_vacacional_id' => $periodo, 'tipo' => 'goce', 'fecha_inicio' => '2026-01-05', 'fecha_fin' => '2026-01-09', 'dias' => 5])->json('data.id');

        $this->postJson($this->url($persona, 'licencias-permisos/'.$licencia.'/anular'), [])->assertUnprocessable()->assertJsonValidationErrors('motivo_anulacion');
        $this->postJson($this->url($persona, 'licencias-permisos/'.$licencia.'/anular'), ['motivo_anulacion' => 'Registrado por error'])->assertOk()->assertJsonPath('data.anulado', true);
        $this->postJson($this->url($persona, 'licencias-permisos/'.$licencia.'/anular'), ['motivo_anulacion' => 'Otra vez'])->assertConflict();
        $this->postJson($this->url(Usuario::factory()->create(), 'goces-vacacionales/'.$goce.'/anular'), ['motivo_anulacion' => 'Persona equivocada'])->assertNotFound();

        // Un período con goces vigentes no se anula hasta anular sus goces.
        $this->postJson($this->url($persona, 'periodos-vacacionales/'.$periodo.'/anular'), ['motivo_anulacion' => 'Año equivocado'])->assertConflict();
        $this->postJson($this->url($persona, 'goces-vacacionales/'.$goce.'/anular'), ['motivo_anulacion' => 'Fechas equivocadas'])->assertOk();
        $this->postJson($this->url($persona, 'periodos-vacacionales/'.$periodo.'/anular'), ['motivo_anulacion' => 'Año equivocado'])->assertOk();

        $this->assertDatabaseHas('licencias_permisos', ['id' => $licencia, 'anulado' => true, 'motivo_anulacion' => 'Registrado por error']);
        $this->getJson($this->url($persona))->assertOk()->assertJsonCount(0, 'data.licencias')->assertJsonCount(0, 'data.periodos')->assertJsonCount(0, 'data.goces');
        $this->getJson($this->url($persona, 'anulados'))->assertOk()->assertJsonCount(1, 'data.licencias_permisos')
            ->assertJsonCount(1, 'data.periodos_vacacionales')->assertJsonCount(1, 'data.goces_vacacionales')
            ->assertJsonPath('data.licencias_permisos.0.values.motivo_anulacion', 'Registrado por error');
        // Anulado el período, ya no recibe goces.
        $this->postJson($this->url($persona, 'goces-vacacionales'), ['periodo_vacacional_id' => $periodo, 'tipo' => 'goce', 'fecha_inicio' => '2026-01-05', 'fecha_fin' => '2026-01-09', 'dias' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('periodo_vacacional_id');
    }

    public function test_permissions_are_checked_by_the_backend(): void
    {
        $persona = $this->personaConLegajo();
        $licencia = LicenciaPermiso::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'permiso']);

        // Solo lectura de legajos: ve la sección, pero no registra ni sube.
        config(['permisos.roles.lector_legajo' => ['legajo.ver']]);
        $this->actuarComo(Usuario::factory()->create(), 'lector_legajo');
        $this->getJson($this->url($persona))->assertOk();
        $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia())->assertForbidden();
        $this->subir($persona, $this->metadatos($this->tipo05('S05_A_APRUEBA_LICENCIA_PERMISO'), 1, 1))->assertForbidden();

        // Asistente: registra, pero no anula ni ve anulados.
        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia())->assertCreated();
        $this->postJson($this->url($persona, 'licencias-permisos/'.$licencia->id.'/anular'), ['motivo_anulacion' => 'Sin permiso'])->assertForbidden();
        $this->getJson($this->url($persona, 'anulados'))->assertForbidden();
        $this->assertFalse($licencia->refresh()->anulado);

        // Sin sesión.
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($persona))->assertUnauthorized();
    }

    public function test_sick_leave_is_health_data_hidden_without_sensitive_access(): void
    {
        $this->actuarComo();
        $persona = $this->personaConLegajo();
        $descanso = $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia(['tipo' => 'descanso_medico']))->assertCreated()->json('data.id');
        $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia(['tipo' => 'permiso', 'forma_permiso' => 'otro']))->assertCreated();
        $documento = $this->subir($persona, $this->metadatos($this->tipo05('S05_DESCANSO_MEDICO'), 1, 1, ['enlace_id' => $descanso]))->assertCreated()->json('data.id');
        $this->getJson($this->url($persona))->assertOk()->assertJsonCount(2, 'data.licencias');

        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $response = $this->getJson($this->url($persona))->assertOk()->assertJsonPath('data.ve_datos_salud', false)
            ->assertJsonCount(1, 'data.licencias')->assertJsonPath('data.licencias.0.values.tipo', 'permiso');
        $tipo = collect($response->json('data.tipos'))->firstWhere('codigo', 'S05_DESCANSO_MEDICO');
        $this->assertTrue($tipo['restringido']);
        $this->assertSame([0, []], [$tipo['documentos'], $tipo['lista']]);
        // Tampoco por el listado genérico de la ficha, ni el PDF, ni registrar o subir uno nuevo.
        $this->getJson('/api/v1/expedientes/'.$persona->id.'/licencias-permisos')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/expedientes/'.$persona->id.'/documentos-usuario')->assertOk()->assertJsonCount(0, 'data');
        $this->get('/api/v1/expedientes/'.$persona->id.'/documentos/'.$documento.'/archivo')->assertForbidden();
        $this->postJson($this->url($persona, 'licencias-permisos'), $this->licencia(['tipo' => 'descanso_medico']))->assertForbidden();
        $this->subir($persona, $this->metadatos($this->tipo05('S05_DESCANSO_MEDICO'), 2, 2))->assertForbidden();
        $this->assertSame(1, LicenciaPermiso::query()->where('tipo', 'descanso_medico')->count());
        $this->assertSame(0, GoceVacacional::query()->count());
    }
}
