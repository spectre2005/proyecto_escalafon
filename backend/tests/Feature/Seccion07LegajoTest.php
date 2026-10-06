<?php

namespace Tests\Feature;

use App\Models\Desplazamiento;
use App\Models\DocumentoUsuario;
use App\Models\EvaluacionDesempeno;
use App\Models\Legajo;
use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\Seccion07;
use Database\Seeders\Seccion07CatalogoSeeder;
use Database\Seeders\Seccion07DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Sección 07 desde la ficha (Legajos\Seccion07Controller): catálogo, carga de documentos con su formulario enlazado,
 * reglas por persona, calificación oculta, anulación y cierre del CRUD genérico. Personas de Seccion07DemoSeeder:
 * 99000701 administrativa de carrera, 99000702 docente de carrera, 99000703 plazo determinado.
 */
class Seccion07LegajoTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    /** @var array<string, int> Siguiente folio por persona (los folios son correlativos por legajo y sección). */
    private array $folios = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('legajo.disco_documentos'));
        $this->seed(Seccion07DemoSeeder::class);
    }

    private function persona(string $documento): Usuario
    {
        return Usuario::query()->where('numero_doc_identidad', $documento)->firstOrFail();
    }

    private function tipo07(string $codigo): TipoDocumento
    {
        return TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->firstOrFail();
    }

    private function url(Usuario $persona, string $resto = ''): string
    {
        return '/api/v1/expedientes/'.$persona->id.'/seccion-07'.$resto;
    }

    /** Sube un documento del tipo con folios correlativos y devuelve la respuesta (data). */
    private function subirTipo(Usuario $persona, string $codigo, array $cambios = []): array
    {
        $tipo = $this->tipo07($codigo);
        $folio = $this->folios[$persona->id] ??= 1;
        $respuesta = $this->subir($persona, $this->metadatos($tipo, $folio, $folio, $cambios))->assertCreated()->json('data');
        $this->folios[$persona->id]++;

        return $respuesta;
    }

    private function evaluacion(array $cambios = []): array
    {
        return [...['ciclo_anio' => 2025, 'fecha_evaluacion' => '2025-12-10', 'calificacion' => 'Buen rendimiento', 'puntaje' => 80, 'plan_mejora' => false], ...$cambios];
    }

    private function desplazamiento(array $cambios = []): array
    {
        return [...['tipo' => 'rotacion', 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-06-30', 'puesto_destino' => 'Asistente'], ...$cambios];
    }

    public function test_catalog_seeder_is_idempotent_and_matches_the_directive(): void
    {
        $this->seed(Seccion07CatalogoSeeder::class);
        $this->seed(Seccion07CatalogoSeeder::class);

        $this->assertSame(['7.1', '7.2', '7.3'], SubseccionLegajo::query()->where('seccion_legajo_id', 7)->orderBy('orden')->pluck('codigo')->all());
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 7)->with('subseccionLegajo')->orderBy('orden')->get();
        $this->assertCount(8, $tipos);
        $this->assertSame([
            'S07_71A_GESTION_RENDIMIENTO' => ['7.1', 'evaluaciones_desempeno'], 'S07_71B_ACTA_RETROALIMENTACION' => ['7.1', null],
            'S07_72A_RESOLUCION_PROGRESION' => ['7.2', 'progresiones_carrera'], 'S07_73B_DESIGNACION' => ['7.3', 'desplazamientos'],
            'S07_73C_ROTACION' => ['7.3', 'desplazamientos'], 'S07_73D_DESTAQUE' => ['7.3', 'desplazamientos'],
            'S07_73E_ENCARGO' => ['7.3', 'desplazamientos'], 'S07_73F_OTROS' => ['7.3', 'desplazamientos'],
        ], $tipos->mapWithKeys(fn (TipoDocumento $t): array => [$t->codigo => [$t->subseccionLegajo->codigo, $t->formulario_datos]])->all());
        $progresion = $tipos->firstWhere('codigo', 'S07_72A_RESOLUCION_PROGRESION');
        $this->assertTrue($progresion->aplica_carrera);
        $this->assertFalse($progresion->aplica_plazo_determinado || $progresion->aplica_designado);
        $this->assertTrue($tipos->every(fn (TipoDocumento $t): bool => $t->permite_multiples && ! $t->es_dato_sensible));
        $this->assertTrue($tipos->firstWhere('codigo', 'S07_73F_OTROS')->es_hook_universidad);
    }

    public function test_catalog_seeder_does_not_alter_a_type_that_has_documents(): void
    {
        $this->actuarComo();
        $this->subirTipo($this->persona('99000701'), 'S07_73C_ROTACION');
        $tipo = $this->tipo07('S07_73C_ROTACION');
        $tipo->update(['descripcion' => 'Cambiada a mano']);

        $this->seed(Seccion07CatalogoSeeder::class);
        $this->assertSame('Cambiada a mano', $tipo->refresh()->descripcion);
        $this->assertSame(1, TipoDocumento::query()->where('codigo', 'S07_73C_ROTACION')->count());
    }

    public function test_demo_seeder_runs_twice_without_duplicating(): void
    {
        $antes = [EvaluacionDesempeno::query()->count(), Desplazamiento::query()->count(), Legajo::query()->count(), TipoDocumento::query()->count()];
        $this->seed(Seccion07DemoSeeder::class);
        $this->assertSame($antes, [EvaluacionDesempeno::query()->count(), Desplazamiento::query()->count(), Legajo::query()->count(), TipoDocumento::query()->count()]);
        $this->assertSame(1, Usuario::query()->where('numero_doc_identidad', '99000701')->count());
    }

    public function test_show_returns_subsections_records_summary_and_warnings(): void
    {
        $this->actuarComo();
        $this->getJson($this->url($this->persona('99000701')))->assertOk()
            ->assertJsonPath('data.perfil.es_carrera', true)
            ->assertJsonPath('data.perfil.administrativo_carrera', true)
            ->assertJsonCount(3, 'data.subsecciones')
            ->assertJsonPath('data.subsecciones.1.aplica', true)
            ->assertJsonCount(2, 'data.evaluaciones')
            ->assertJsonCount(1, 'data.progresiones')
            ->assertJsonCount(3, 'data.desplazamientos')
            ->assertJsonPath('data.avisos.evaluacion_ciclo_en_curso', false)
            ->assertJsonPath('data.avisos.planes_mejora_sin_acta', 1)
            ->assertJsonPath('data.subsecciones.2.estado', 'completa');

        $this->getJson($this->url($this->persona('99000702')))->assertOk()
            ->assertJsonPath('data.perfil.docente', true)
            ->assertJsonPath('data.ratificaciones.0.alerta', 'por_vencer')
            ->assertJsonPath('data.avisos.dias_aviso_ratificacion', Seccion07::DIAS_AVISO_RATIFICACION);

        // No es de carrera: 7.2 no aplica y no cuenta en el resumen.
        $this->getJson($this->url($this->persona('99000703'), '/resumen'))->assertOk()
            ->assertJsonPath('data.subsecciones.1.codigo', '7.2')
            ->assertJsonPath('data.subsecciones.1.estado', 'no_aplica')
            ->assertJsonPath('data.subsecciones.2.estado', 'sin_datos')
            ->assertJsonMissingPath('data.subsecciones.0.tipos')
            ->assertJsonStructure(['data' => ['resumen' => ['tipos', 'pendientes', 'exigidos', 'exigidos_cargados', 'exigidos_recepcionados']]]);
    }

    public function test_each_type_links_its_document_to_the_right_record(): void
    {
        $this->actuarComo();
        $carrera = $this->persona('99000701');

        // 7.1 a) evaluación.
        $doc = $this->subirTipo($carrera, 'S07_71A_GESTION_RENDIMIENTO');
        $this->assertSame('evaluaciones_desempeno', $doc['formulario_datos']);
        $id = $this->postJson($this->url($carrera, '/evaluaciones-desempeno'), $this->evaluacion(['documento_id' => $doc['id']]))->assertCreated()->json('data.id');
        $this->assertSame($doc['id'], EvaluacionDesempeno::query()->find($id)->documento_id);
        // Un documento acredita un solo registro.
        $this->postJson($this->url($carrera, '/evaluaciones-desempeno'), $this->evaluacion(['ciclo_anio' => 2024, 'documento_id' => $doc['id']]))
            ->assertUnprocessable()->assertJsonValidationErrors('documento_id');

        // 7.2 a) administrativo de carrera: progresión.
        $vinculo = ProgresionCarrera::query()->where('usuario_id', $carrera->id)->value('vinculo_laboral_id');
        $doc = $this->subirTipo($carrera, 'S07_72A_RESOLUCION_PROGRESION');
        $id = $this->postJson($this->url($carrera, '/progresiones-carrera'), ['vinculo_laboral_id' => $vinculo, 'nivel_nuevo' => 'SP-DI', 'fecha_efectiva' => '2026-01-01', 'numero_resolucion' => 'R-1', 'documento_id' => $doc['id']])
            ->assertCreated()->json('data.id');
        $this->assertSame($doc['id'], ProgresionCarrera::query()->find($id)->documento_id);

        // 7.3: cada literal fija el tipo de desplazamiento; otro tipo se rechaza.
        foreach (['S07_73B_DESIGNACION' => 'designacion', 'S07_73C_ROTACION' => 'rotacion', 'S07_73D_DESTAQUE' => 'destaque', 'S07_73E_ENCARGO' => 'encargo_puesto', 'S07_73F_OTROS' => 'otro'] as $codigo => $tipo) {
            $doc = $this->subirTipo($carrera, $codigo);
            $this->assertSame('desplazamientos', $doc['formulario_datos']);
            $this->postJson($this->url($carrera, '/desplazamientos'), $this->desplazamiento(['tipo' => $tipo === 'rotacion' ? 'destaque' : 'rotacion', 'documento_id' => $doc['id']]))
                ->assertUnprocessable()->assertJsonValidationErrors('documento_id');
            $id = $this->postJson($this->url($carrera, '/desplazamientos'), $this->desplazamiento(['tipo' => $tipo, 'documento_id' => $doc['id']]))->assertCreated()->json('data.id');
            $this->assertSame([$tipo, $doc['id']], array_values(Desplazamiento::query()->find($id)->only(['tipo', 'documento_id'])));
        }
        $doc = $this->subirTipo($carrera, 'S07_73E_ENCARGO');
        $this->postJson($this->url($carrera, '/desplazamientos'), $this->desplazamiento(['tipo' => 'encargo_funciones', 'documento_id' => $doc['id']]))->assertCreated();

        // 7.2 a) docente: el mismo tipo de documento va a ratificaciones_docentes.
        $docente = $this->persona('99000702');
        $perfil = RatificacionDocente::query()->where('usuario_id', $docente->id)->value('perfil_docente_id');
        $doc = $this->subirTipo($docente, 'S07_72A_RESOLUCION_PROGRESION', ['titular_documento' => '99000702']);
        $id = $this->postJson($this->url($docente, '/ratificaciones-docentes'), [
            'perfil_docente_id' => $perfil, 'tipo' => 'promocion', 'categoria_anterior' => 'asociado', 'categoria_nueva' => 'principal', 'numero_resolucion' => 'R-2',
            'fecha_resolucion' => '2026-01-10', 'fecha_inicio_periodo' => '2026-01-10', 'fecha_vencimiento_periodo' => '2033-01-09', 'documento_id' => $doc['id'],
        ])->assertCreated()->assertJsonPath('data.values.resultado', 'pendiente')->json('data.id');
        $this->assertSame($doc['id'], RatificacionDocente::query()->find($id)->documento_id);
    }

    public function test_upload_can_link_an_existing_record_only_of_a_compatible_kind(): void
    {
        $this->actuarComo();
        $carrera = $this->persona('99000701');
        $rotacion = Desplazamiento::query()->where('usuario_id', $carrera->id)->where('tipo', 'rotacion')->firstOrFail();
        $destaque = Desplazamiento::query()->where('usuario_id', $carrera->id)->where('tipo', 'destaque')->firstOrFail();
        $tipo = $this->tipo07('S07_73C_ROTACION');

        $this->subir($carrera, $this->metadatos($tipo, 1, 1, ['enlace_id' => $destaque->id]))->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $doc = $this->subir($carrera, $this->metadatos($tipo, 1, 2, ['enlace_id' => $rotacion->id]))->assertCreated()->assertJsonPath('data.enlazado', true)->json('data');
        $this->assertSame($doc['id'], $rotacion->refresh()->documento_id);

        // Ratificación existente enlazada con el tipo de 7.2 a).
        $docente = $this->persona('99000702');
        $ratificacion = RatificacionDocente::query()->where('usuario_id', $docente->id)->firstOrFail();
        $doc = $this->subir($docente, $this->metadatos($this->tipo07('S07_72A_RESOLUCION_PROGRESION'), 1, 1, ['enlace_id' => $ratificacion->id]))->assertCreated()->json('data');
        $this->assertSame($doc['id'], $ratificacion->refresh()->documento_id);
    }

    public function test_type_without_form_only_stores_metadata(): void
    {
        $this->actuarComo();
        $carrera = $this->persona('99000701');
        $antes = EvaluacionDesempeno::query()->count();
        $doc = $this->subirTipo($carrera, 'S07_71B_ACTA_RETROALIMENTACION');

        $this->assertNull($doc['formulario_datos']);
        $this->assertFalse($doc['enlazado']);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $doc['id'], 'seccion_legajo_id' => 7, 'estado' => 'cargado']);
        $this->assertSame($antes, EvaluacionDesempeno::query()->count());
        $this->postJson($this->url($carrera, '/evaluaciones-desempeno'), $this->evaluacion(['documento_id' => $doc['id']]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $evaluacion = EvaluacionDesempeno::query()->where('usuario_id', $carrera->id)->firstOrFail();
        $this->subir($carrera, $this->metadatos($this->tipo07('S07_71B_ACTA_RETROALIMENTACION'), 2, 2, ['enlace_id' => $evaluacion->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        // Con el acta cargada ya no se avisa del plan de mejora sin acta.
        $this->getJson($this->url($carrera))->assertOk()->assertJsonPath('data.avisos.planes_mejora_sin_acta', 0);
    }

    public function test_permissions_are_checked_by_the_backend(): void
    {
        $carrera = $this->persona('99000701');
        $evaluacion = EvaluacionDesempeno::query()->where('usuario_id', $carrera->id)->firstOrFail();
        $this->getJson($this->url($carrera))->assertUnauthorized();

        $this->actuarComo(Usuario::factory()->create(), 'rol_sin_permisos');
        $this->getJson($this->url($carrera))->assertForbidden();

        // El asistente ve la sección, pero no registra ni anula (seccion07.registrar no se le asigna).
        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $this->getJson($this->url($carrera))->assertOk();
        $this->postJson($this->url($carrera, '/evaluaciones-desempeno'), $this->evaluacion())->assertForbidden();
        $this->patchJson($this->url($carrera, '/evaluaciones-desempeno/'.$evaluacion->id), ['plan_mejora' => true])->assertForbidden();
        $this->postJson($this->url($carrera, '/evaluaciones-desempeno/'.$evaluacion->id.'/anular'), ['motivo_anulacion' => 'Prueba de permiso'])->assertForbidden();

        // Registrar sin administrar el sistema: registra, pero no anula.
        config(['permisos.roles.registrador_07' => ['legajo.ver', 'seccion07.ver', 'seccion07.registrar']]);
        $this->actuarComo(Usuario::factory()->create(), 'registrador_07');
        $this->postJson($this->url($carrera, '/evaluaciones-desempeno'), $this->evaluacion())->assertCreated();
        $this->postJson($this->url($carrera, '/evaluaciones-desempeno/'.$evaluacion->id.'/anular'), ['motivo_anulacion' => 'Prueba de permiso'])->assertForbidden();
        $this->assertFalse($evaluacion->refresh()->anulado);
    }

    public function test_grade_and_score_are_hidden_without_their_permission(): void
    {
        $carrera = $this->persona('99000701');
        $this->actuarComo();
        $respuesta = $this->getJson($this->url($carrera))->assertOk()
            ->assertJsonPath('data.ve_calificacion', false)
            ->assertJsonPath('data.evaluaciones.0.values.calificacion', null)
            ->assertJsonPath('data.evaluaciones.0.values.puntaje', null)
            ->assertJsonPath('data.evaluaciones.0.calificacion_oculta', true);
        $this->assertStringNotContainsString('Sujeto a observación', $respuesta->getContent());
        $this->postJson($this->url($carrera, '/evaluaciones-desempeno'), $this->evaluacion(['ciclo_anio' => (int) today()->year, 'fecha_evaluacion' => today()->toDateString(), 'calificacion' => 'Muy secreta']))->assertCreated()
            ->assertJsonPath('data.values.calificacion', null);
        // Tampoco por la vista genérica del expediente ni por el CRUD genérico.
        $this->assertStringNotContainsString('Sujeto a observación', $this->getJson('/api/v1/expedientes/'.$carrera->id.'/evaluaciones-desempeno')->assertOk()->getContent());
        $this->assertStringNotContainsString('Sujeto a observación', $this->getJson('/api/v1/evaluaciones-desempeno')->assertOk()->getContent());

        config(['permisos.roles.evaluador_prueba' => ['legajo.ver', 'seccion07.ver', 'seccion07.ver_calificacion'], 'permisos.pendientes' => []]);
        $this->actuarComo(Usuario::factory()->create(), 'evaluador_prueba');
        $this->getJson($this->url($carrera))->assertOk()
            ->assertJsonPath('data.ve_calificacion', true)
            ->assertJsonPath('data.evaluaciones.0.values.calificacion', 'Muy secreta');
    }

    public function test_annulled_records_leave_listings_and_summary_and_are_never_deleted(): void
    {
        $this->actuarComo();
        $plazo = $this->persona('99000703');
        $evaluacion = EvaluacionDesempeno::query()->where('usuario_id', $plazo->id)->firstOrFail();
        $this->getJson($this->url($plazo, '/resumen'))->assertOk()->assertJsonPath('data.subsecciones.0.estado', 'parcial');

        $this->postJson($this->url($plazo, '/evaluaciones-desempeno/'.$evaluacion->id.'/anular'), ['motivo_anulacion' => 'x'])->assertUnprocessable();
        $this->postJson($this->url($plazo, '/evaluaciones-desempeno/'.$evaluacion->id.'/anular'), ['motivo_anulacion' => 'Registrada por error'])->assertOk()
            ->assertJsonPath('data.values.anulado', true);
        $this->postJson($this->url($plazo, '/evaluaciones-desempeno/'.$evaluacion->id.'/anular'), ['motivo_anulacion' => 'Segundo intento'])->assertConflict();
        $this->patchJson($this->url($plazo, '/evaluaciones-desempeno/'.$evaluacion->id), ['plan_mejora' => true])->assertConflict();

        $this->assertDatabaseHas('evaluaciones_desempeno', ['id' => $evaluacion->id, 'anulado' => true, 'motivo_anulacion' => 'Registrada por error']);
        $this->getJson($this->url($plazo))->assertOk()->assertJsonCount(0, 'data.evaluaciones');
        $this->getJson($this->url($plazo, '/resumen'))->assertOk()->assertJsonPath('data.subsecciones.0.estado', 'sin_datos');
        $this->getJson('/api/v1/seccion07/evaluaciones?q=99000703')->assertOk()->assertJsonPath('total', 0);

        // Otra persona no puede anular registros ajenos por la ruta de esta.
        $ajeno = Desplazamiento::query()->where('usuario_id', $this->persona('99000701')->id)->firstOrFail();
        $this->postJson($this->url($plazo, '/desplazamientos/'.$ajeno->id.'/anular'), ['motivo_anulacion' => 'Intento ajeno'])->assertNotFound();
    }

    public function test_career_progression_and_teacher_ratification_follow_the_person_profile(): void
    {
        $this->actuarComo();
        $plazo = $this->persona('99000703');
        $vinculoPlazo = VinculoLaboral::query()->where('usuario_id', $plazo->id)->value('id');
        $this->postJson($this->url($plazo, '/progresiones-carrera'), ['vinculo_laboral_id' => $vinculoPlazo, 'nivel_nuevo' => 'SP-ES', 'fecha_efectiva' => '2026-01-01', 'numero_resolucion' => 'R-9'])
            ->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');

        // Un vínculo de otra persona tampoco sirve.
        $ajeno = ProgresionCarrera::query()->where('usuario_id', $this->persona('99000701')->id)->value('vinculo_laboral_id');
        $this->postJson($this->url($plazo, '/progresiones-carrera'), ['vinculo_laboral_id' => $ajeno, 'nivel_nuevo' => 'SP-ES', 'fecha_efectiva' => '2026-01-01', 'numero_resolucion' => 'R-9'])
            ->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');

        // Ratificación para quien no es docente: rechazada.
        $perfil = RatificacionDocente::query()->where('usuario_id', $this->persona('99000702')->id)->value('perfil_docente_id');
        $this->postJson($this->url($this->persona('99000701'), '/ratificaciones-docentes'), [
            'perfil_docente_id' => $perfil, 'tipo' => 'ratificacion', 'categoria_nueva' => 'asociado', 'numero_resolucion' => 'R-3',
            'fecha_resolucion' => '2026-01-10', 'fecha_inicio_periodo' => '2026-01-10', 'fecha_vencimiento_periodo' => '2031-01-09',
        ])->assertUnprocessable()->assertJsonValidationErrors('perfil_docente_id');
        $this->assertSame(0, ProgresionCarrera::query()->where('usuario_id', $plazo->id)->count());
    }

    public function test_rejects_invalid_dates_required_fields_and_unknown_fields(): void
    {
        $this->actuarComo();
        $carrera = $this->persona('99000701');
        $this->postJson($this->url($carrera, '/desplazamientos'), $this->desplazamiento(['fecha_fin' => '2024-12-31']))->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
        $this->postJson($this->url($carrera, '/desplazamientos'), $this->desplazamiento(['tipo' => 'traslado']))->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->postJson($this->url($carrera, '/evaluaciones-desempeno'), $this->evaluacion(['fecha_evaluacion' => today()->addDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('fecha_evaluacion');
        $this->postJson($this->url($carrera, '/evaluaciones-desempeno'), $this->evaluacion(['calificacion' => null]))->assertUnprocessable()->assertJsonValidationErrors('calificacion');
        $this->postJson($this->url($carrera, '/evaluaciones-desempeno'), $this->evaluacion(['registrado_por' => $carrera->id]))->assertUnprocessable()->assertJsonValidationErrors('registrado_por');
        $this->postJson($this->url($carrera, '/evaluaciones-desempeno'), $this->evaluacion(['anulado' => true]))->assertUnprocessable()->assertJsonValidationErrors('anulado');
        $docente = $this->persona('99000702');
        $perfil = RatificacionDocente::query()->where('usuario_id', $docente->id)->value('perfil_docente_id');
        $this->postJson($this->url($docente, '/ratificaciones-docentes'), [
            'perfil_docente_id' => $perfil, 'tipo' => 'ratificacion', 'categoria_nueva' => 'asociado', 'numero_resolucion' => 'R-4',
            'fecha_resolucion' => '2026-01-10', 'fecha_inicio_periodo' => '2026-01-10', 'fecha_vencimiento_periodo' => '2025-01-09',
        ])->assertUnprocessable()->assertJsonValidationErrors('fecha_vencimiento_periodo');

        // Corrección: valida contra lo guardado (fin antes del inicio registrado) y guarda quién registró con la sesión.
        $desplazamiento = Desplazamiento::query()->where('usuario_id', $carrera->id)->where('tipo', 'destaque')->firstOrFail();
        $this->patchJson($this->url($carrera, '/desplazamientos/'.$desplazamiento->id), ['fecha_fin' => $desplazamiento->fecha_inicio->copy()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
        $this->patchJson($this->url($carrera, '/desplazamientos/'.$desplazamiento->id), ['puesto_destino' => 'Jefe(a) de unidad'])->assertOk()
            ->assertJsonPath('data.values.puesto_destino', 'Jefe(a) de unidad');
    }

    public function test_requires_an_open_file_to_register(): void
    {
        $this->actuarComo();
        $sinLegajo = Usuario::factory()->create();
        $this->postJson($this->url($sinLegajo, '/desplazamientos'), $this->desplazamiento())->assertConflict();
        $this->getJson($this->url($sinLegajo))->assertOk()->assertJsonPath('data.legajo', null);
    }

    public function test_generic_crud_of_section_07_is_read_only(): void
    {
        $this->actuarComo();
        foreach (['evaluaciones-desempeno' => EvaluacionDesempeno::class, 'progresiones-carrera' => ProgresionCarrera::class,
            'ratificaciones-docentes' => RatificacionDocente::class, 'desplazamientos' => Desplazamiento::class] as $recurso => $clase) {
            $registro = $clase::query()->firstOrFail();
            $antes = $registro->getAttributes();
            $total = $clase::query()->count();
            $this->getJson('/api/v1/'.$recurso)->assertOk();
            $this->getJson('/api/v1/'.$recurso.'/'.$registro->id)->assertOk();
            $this->postJson('/api/v1/'.$recurso, [])->assertStatus(405);
            $this->patchJson('/api/v1/'.$recurso.'/'.$registro->id, ['anulado' => true, 'motivo_anulacion' => 'Por el CRUD'])->assertStatus(405);
            $this->putJson('/api/v1/'.$recurso.'/'.$registro->id, [])->assertStatus(405);
            $this->deleteJson('/api/v1/'.$recurso.'/'.$registro->id)->assertStatus(405);
            $this->assertSame($total, $clase::query()->count());
            $this->assertEquals($antes, $registro->refresh()->getAttributes());
        }
        $this->assertSame(0, DocumentoUsuario::query()->count());
    }
}
