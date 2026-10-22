<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\AuditoriaEvento;
use App\Models\Capacitacion;
use App\Models\DocumentoUsuario;
use App\Models\EvaluacionDesempeno;
use App\Models\FormacionAcademica;
use App\Models\Legajo;
use App\Models\LicenciaPermiso;
use App\Models\Reconocimiento;
use App\Models\RegimenNormativo;
use App\Models\Sancion;
use App\Models\TiempoServicioReconocido;
use App\Models\TipoDocumento;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Informe escalafonario y constancias de trabajo y de tiempo de servicios (datos del PDF). Datos ficticios. */
class EmisionesPersonalesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/reportes/personales/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $this->travelTo(now('America/Lima')->setDate(2026, 10, 22)->setTime(10, 0));
    }

    private function persona(): array
    {
        $persona = Usuario::factory()->create(['nombres' => 'ANA PRUEBA', 'apellido_paterno' => 'FICTICIA', 'apellido_materno' => 'EJEMPLO',
            'tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => '00112233', 'tipo_personal' => 'administrativo']);
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id, 'numero_legajo' => 'LEG-0001', 'estado_legajo' => 'activo', 'fecha_apertura' => '2020-03-02']);

        return [$persona, $legajo];
    }

    private function documento(Legajo $legajo, int $seccion, array $datos = [], array $tipo = []): DocumentoUsuario
    {
        return DocumentoUsuario::factory()->create(['usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id, 'seccion_legajo_id' => $seccion,
            'archivo_id' => ArchivoDocumento::factory()->create(['estado_antivirus' => 'limpio'])->id,
            'tipo_documento_id' => TipoDocumento::factory()->create(['seccion_legajo_id' => $seccion, 'es_dato_sensible' => false, 'es_dato_salud' => false, ...$tipo])->id,
            'created_by' => Usuario::factory(), 'estado' => 'aceptado', ...$datos]);
    }

    private function vinculo(Usuario $persona, array $datos = []): VinculoLaboral
    {
        return VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'nombre_puesto' => 'ESPECIALISTA EN ESCALAFON', 'tipo_personal' => 'administrativo',
            'regimen_laboral' => 'plazo_determinado', 'regimen_normativo_id' => RegimenNormativo::factory()->create(['nombre' => 'Decreto Legislativo N° 1057 (CAS)'])->id,
            'unidad_organica_id' => UnidadOrganica::factory()->create(['nombre' => 'UNIDAD DE PRUEBA'])->id, 'fecha_inicio' => '2021-01-04', ...$datos]);
    }

    private function vinculoAcreditado(Usuario $persona, Legajo $legajo, array $datos = []): VinculoLaboral
    {
        $vinculo = $this->vinculo($persona, $datos);
        $this->documento($legajo, 2, ['vinculo_laboral_id' => $vinculo->id]);

        return $vinculo;
    }

    private function tiempo(Usuario $persona, ?DocumentoUsuario $documento, array $datos = []): TiempoServicioReconocido
    {
        return TiempoServicioReconocido::factory()->create(['usuario_id' => $persona->id, 'documento_id' => $documento?->id, 'numero_resolucion' => 'RD-0150-2024',
            'fecha_resolucion' => '2024-05-10', 'anios' => 12, 'meses' => 3, 'dias' => 9, ...$datos]);
    }

    public function test_complete_worker_report_includes_each_section_with_its_support_and_audits_the_emission(): void
    {
        $admin = $this->actuarComo();
        [$persona, $legajo] = $this->persona();
        $vinculo = $this->vinculoAcreditado($persona, $legajo, ['numero_documento_vinculo' => 'CONTRATO-CAS-045-2021', 'fecha_fin_prevista' => '2026-12-31']);
        $this->tiempo($persona, $this->documento($legajo, 6));
        FormacionAcademica::factory()->create(['usuario_id' => $persona->id, 'nivel' => 'titulo_profesional', 'denominacion' => 'LICENCIADA EN ADMINISTRACION',
            'institucion' => 'UNIVERSIDAD FICTICIA', 'fecha_obtencion' => '2015-07-20', 'documento_id' => $this->documento($legajo, 3)->id]);
        Capacitacion::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'curso', 'nombre' => 'GESTION DE LEGAJOS', 'horas' => 24, 'fecha_inicio' => '2023-02-01', 'fecha_fin' => '2023-02-10']);
        Reconocimiento::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'carta', 'motivo' => 'APOYO EN DIGITALIZACION', 'fecha' => '2024-01-15']);
        Sancion::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'AMONESTACION_ESCRITA', 'numero_resolucion' => 'RS-001-2022', 'fecha_resolucion' => '2022-06-01']);
        LicenciaPermiso::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'permiso', 'con_goce' => false, 'fecha_inicio' => '2025-03-03', 'fecha_fin' => '2025-03-04', 'dias' => 2]);
        LicenciaPermiso::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'descanso_medico', 'fecha_inicio' => '2025-04-01', 'fecha_fin' => '2025-04-05', 'dias' => 5]);
        EvaluacionDesempeno::factory()->create(['usuario_id' => $persona->id, 'ciclo_anio' => 2024, 'calificacion' => 'SECRETO_CALIFICACION', 'puntaje' => 18.5, 'plan_mejora' => false]);
        FormacionAcademica::factory()->create(['usuario_id' => $persona->id, 'denominacion' => 'REGISTRO ANULADO', 'anulado' => true]);
        Sancion::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'SUSPENSION', 'numero_resolucion' => 'RS-002-2023', 'fecha_resolucion' => '2023-01-10']);
        Reconocimiento::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'labor_docente', 'motivo' => 'TUTORIA', 'fecha' => '2024-06-01']);

        $respuesta = $this->getJson(self::URL.$persona->id.'/informe-escalafonario')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.titulo', 'Informe escalafonario')->assertJsonPath('data.emitido_en', '22/10/2026')
            ->assertJsonPath('data.persona.nombre', 'FICTICIA EJEMPLO, ANA PRUEBA')->assertJsonPath('data.persona.tipo_documento', 'DNI')
            ->assertJsonPath('data.persona.numero_documento', '00112233')->assertJsonPath('data.persona.numero_legajo', 'LEG-0001')
            ->assertJsonPath('data.vinculos.0.numero_oficial', 'CONTRATO-CAS-045-2021')->assertJsonPath('data.vinculos.0.ley', 'Decreto Legislativo N° 1057 (CAS)')
            ->assertJsonPath('data.vinculos.0.tipo_vinculo', 'Plazo determinado')->assertJsonPath('data.vinculos.0.unidad', 'UNIDAD DE PRUEBA')
            ->assertJsonPath('data.vinculos.0.inicio', '04/01/2021')->assertJsonPath('data.vinculos.0.fin_previsto', '31/12/2026')
            ->assertJsonPath('data.vinculos.0.situacion_codigo', 'vigente')->assertJsonPath('data.vinculos.0.sustento', 'Documento de vinculación aceptado')
            ->assertJsonPath('data.tiempos_reconocidos.0.anios', 12)->assertJsonPath('data.tiempos_reconocidos.0.sustento', 'Documento aceptado')
            ->assertJsonPath('data.formacion.0.nivel', 'Título profesional')->assertJsonPath('data.formacion.0.fecha_obtencion', '20/07/2015')
            ->assertJsonCount(1, 'data.formacion')->assertJsonPath('data.capacitaciones.0.horas', 24)->assertJsonPath('data.capacitaciones.0.sustento', 'Sin documento de sustento')
            ->assertJsonPath('data.reconocimientos.0.motivo', 'APOYO EN DIGITALIZACION')->assertJsonPath('data.sanciones.0.numero_resolucion', 'RS-001-2022')
            // Etiquetas visibles con tilde; el valor guardado no cambia.
            ->assertJsonPath('data.reconocimientos.0.tipo', 'Felicitación')->assertJsonPath('data.sanciones.0.tipo', 'Amonestación escrita')
            ->assertJsonPath('data.sanciones.1.tipo', 'Suspensión')->assertJsonPath('data.reconocimientos.1.tipo', 'Reconocimiento por labor docente')
            ->assertJsonCount(1, 'data.licencias')->assertJsonPath('data.licencias.0.goce', 'Sin goce')
            ->assertJsonPath('data.evaluaciones.0.ciclo', 2024)->assertJsonPath('data.omitidas', []);

        // Ni el identificador interno del vínculo, ni la calificación, ni el descanso médico (dato de salud).
        $texto = $respuesta->getContent();
        $this->assertStringNotContainsString($vinculo->id, $texto);
        $this->assertStringNotContainsString('SECRETO_CALIFICACION', $texto);
        $this->assertStringNotContainsString('18.5', $texto);
        $this->assertStringNotContainsString('Descanso', $texto);
        $this->assertStringNotContainsString('REGISTRO ANULADO', $texto);

        $evento = AuditoriaEvento::query()->where('accion', 'reporte.exportado')->sole();
        $this->assertSame($admin->id, $evento->actor_usuario_id);
        $this->assertSame($persona->id, $evento->usuario_titular_id);
        $this->assertSame(['formato' => 'informe-escalafonario', 'emitible' => true], json_decode($evento->datos_despues, true));
    }

    public function test_work_certificate_with_several_links_only_certifies_supported_ones_and_states_what_can_be_known(): void
    {
        $this->actuarComo();
        [$persona, $legajo] = $this->persona();
        $this->vinculoAcreditado($persona, $legajo, ['nombre_puesto' => 'ASISTENTE ANTERIOR', 'fecha_inicio' => '2018-01-02', 'fecha_cese' => '2019-12-31', 'numero_documento_vinculo' => 'RES-010-2018']);
        $this->vinculoAcreditado($persona, $legajo, ['nombre_puesto' => 'PLAZO VENCIDO', 'fecha_inicio' => '2020-01-02', 'fecha_fin_prevista' => '2020-12-31']);
        $this->vinculo($persona, ['nombre_puesto' => 'VIGENTE SIN DOCUMENTO', 'fecha_inicio' => '2025-01-02']);
        $this->vinculoAcreditado($persona, $legajo, ['nombre_puesto' => 'ANULADO', 'anulado' => true, 'motivo_anulacion' => 'Error de registro']);

        $this->getJson(self::URL.$persona->id.'/constancia-trabajo')->assertOk()->assertJsonPath('data.emitible', true)
            ->assertJsonCount(2, 'data.vinculos')
            ->assertJsonPath('data.vinculos.0.puesto', 'ASISTENTE ANTERIOR')->assertJsonPath('data.vinculos.0.cese', '31/12/2019')
            ->assertJsonPath('data.vinculos.0.situacion', 'Concluido el 31/12/2019 (cese registrado)')
            ->assertJsonPath('data.vinculos.1.puesto', 'PLAZO VENCIDO')->assertJsonPath('data.vinculos.1.situacion_codigo', 'no_determinada')
            ->assertJsonPath('data.vinculos.1.situacion', 'No determinada: el plazo terminó el 31/12/2020 y no hay cese registrado')
            // El vínculo vigente no tiene documento aceptado: no permite afirmar que sigue trabajando.
            ->assertJsonPath('data.situacion', 'No se registra un vínculo laboral sustentado que esté vigente a la fecha de emisión.')
            ->assertJsonPath('data.no_incluidos.0.puesto', 'VIGENTE SIN DOCUMENTO')->assertJsonPath('data.no_incluidos.0.motivo', 'Sin documento de vinculación en el legajo')
            ->assertJsonCount(1, 'data.no_incluidos');

        // El informe muestra los tres vínculos no anulados, en orden.
        $this->getJson(self::URL.$persona->id.'/informe-escalafonario')->assertOk()->assertJsonCount(3, 'data.vinculos')
            ->assertJsonPath('data.vinculos.2.situacion_codigo', 'vigente')->assertJsonPath('data.vinculos.2.sustento', 'Sin documento de vinculación en el legajo');
    }

    public function test_current_supported_link_with_an_addendum_is_stated_as_current(): void
    {
        $this->actuarComo();
        [$persona, $legajo] = $this->persona();
        $vinculo = $this->vinculoAcreditado($persona, $legajo, ['fecha_inicio' => '2024-01-02', 'fecha_fin_prevista' => '2024-12-31']);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2026-12-31']);

        $this->getJson(self::URL.$persona->id.'/constancia-trabajo')->assertOk()->assertJsonPath('data.vinculos.0.fin_previsto', '31/12/2026')
            ->assertJsonPath('data.vinculos.0.situacion_codigo', 'vigente')->assertJsonPath('data.situacion', 'Mantiene un vínculo laboral vigente a la fecha de emisión.');
    }

    public function test_person_without_records_gets_empty_sections_and_no_certificate(): void
    {
        $this->actuarComo();
        [$persona] = $this->persona();

        $this->getJson(self::URL.$persona->id.'/informe-escalafonario')->assertOk()
            ->assertJsonPath('data.vinculos', [])->assertJsonPath('data.tiempos_reconocidos', [])->assertJsonPath('data.formacion', [])
            ->assertJsonPath('data.capacitaciones', [])->assertJsonPath('data.reconocimientos', [])->assertJsonPath('data.sanciones', [])
            ->assertJsonPath('data.licencias', [])->assertJsonPath('data.evaluaciones', []);
        $this->getJson(self::URL.$persona->id.'/constancia-trabajo')->assertOk()->assertJsonPath('data.emitible', false)
            ->assertJsonPath('data.motivo', 'Ningún vínculo laboral tiene un documento de vinculación aceptado en la sección 02 del legajo.')->assertJsonPath('data.vinculos', []);
        $this->getJson(self::URL.$persona->id.'/constancia-tiempo-servicios')->assertOk()->assertJsonPath('data.emitible', false)
            ->assertJsonPath('data.motivo', 'No hay tiempo de servicios reconocido por resolución con documento aceptado en la sección 06 del legajo.')->assertJsonPath('data.tiempos', []);
    }

    public function test_official_number_is_never_replaced_by_the_internal_code_and_missing_dates_stay_empty(): void
    {
        $this->actuarComo();
        [$persona, $legajo] = $this->persona();
        $vinculo = $this->vinculoAcreditado($persona, $legajo, ['numero_documento_vinculo' => null, 'fecha_fin_prevista' => null, 'fecha_cese' => null]);

        $respuesta = $this->getJson(self::URL.$persona->id.'/constancia-trabajo')->assertOk()
            ->assertJsonPath('data.vinculos.0.numero_oficial', null)->assertJsonPath('data.vinculos.0.fin_previsto', null)->assertJsonPath('data.vinculos.0.cese', null)
            ->assertJsonMissingPath('data.vinculos.0.id');
        $this->assertStringNotContainsString($vinculo->id, $respuesta->getContent());
    }

    public function test_service_time_certificate_lists_each_supported_resolution_without_adding_them_up(): void
    {
        $this->actuarComo();
        [$persona, $legajo] = $this->persona();
        $this->tiempo($persona, $this->documento($legajo, 6), ['numero_resolucion' => 'RD-0100-2020', 'fecha_resolucion' => '2020-02-01', 'anios' => 5, 'meses' => 0, 'dias' => 0]);
        $this->tiempo($persona, $this->documento($legajo, 6), ['numero_resolucion' => 'RD-0200-2024', 'fecha_resolucion' => '2024-02-01', 'anios' => 9, 'meses' => 2, 'dias' => 15]);
        $this->tiempo($persona, null, ['numero_resolucion' => 'SIN-DOC-01']);
        $this->tiempo($persona, $this->documento($legajo, 6, ['estado' => 'anulado']), ['numero_resolucion' => 'DOC-ANULADO']);
        $original = $this->documento($legajo, 6);
        $this->tiempo($persona, $original, ['numero_resolucion' => 'SUBSANADA']);
        $this->documento($legajo, 6, ['subsana_a_documento_id' => $original->id]);
        $this->tiempo($persona, $this->documento($legajo, 6), ['numero_resolucion' => 'ANULADO', 'anulado' => true, 'motivo_anulacion' => 'Error']);

        $respuesta = $this->getJson(self::URL.$persona->id.'/constancia-tiempo-servicios')->assertOk()->assertJsonPath('data.emitible', true)
            ->assertJsonCount(2, 'data.tiempos')
            ->assertJsonPath('data.tiempos.0', ['numero_resolucion' => 'RD-0100-2020', 'fecha_resolucion' => '01/02/2020', 'anios' => 5, 'meses' => 0, 'dias' => 0])
            ->assertJsonPath('data.tiempos.1', ['numero_resolucion' => 'RD-0200-2024', 'fecha_resolucion' => '01/02/2024', 'anios' => 9, 'meses' => 2, 'dias' => 15])
            ->assertJsonPath('data.no_incluidos', [
                ['numero_resolucion' => 'SIN-DOC-01', 'motivo' => 'Sin documento de sustento'],
                ['numero_resolucion' => 'DOC-ANULADO', 'motivo' => 'Documento anulado'],
                ['numero_resolucion' => 'SUBSANADA', 'motivo' => 'Documento reemplazado por una subsanación'],
            ])
            ->assertJsonMissingPath('data.total')->assertJsonMissingPath('data.tiempo_total');
        // Ninguna suma inventada: 14 años, 2 meses y 15 días no aparece en ningún campo.
        $this->assertStringNotContainsString('"anios":14', $respuesta->getContent());
    }

    public function test_recognized_time_without_supporting_document_is_informative_only(): void
    {
        $this->actuarComo();
        [$persona] = $this->persona();
        $this->tiempo($persona, null);

        $this->getJson(self::URL.$persona->id.'/constancia-tiempo-servicios')->assertOk()->assertJsonPath('data.emitible', false)->assertJsonPath('data.tiempos', []);
        $this->getJson(self::URL.$persona->id.'/informe-escalafonario')->assertOk()->assertJsonPath('data.tiempos_reconocidos.0.numero_resolucion', 'RD-0150-2024')
            ->assertJsonPath('data.tiempos_reconocidos.0.sustento', 'Sin documento de sustento');
    }

    public function test_only_administration_with_legajo_access_can_emit_and_hidden_sections_are_declared(): void
    {
        [$persona] = $this->persona();
        Sancion::factory()->create(['usuario_id' => $persona->id, 'numero_resolucion' => 'RS-OCULTA']);

        $this->getJson(self::URL.$persona->id.'/informe-escalafonario')->assertUnauthorized();
        foreach (['informe-escalafonario', 'constancia-trabajo', 'constancia-tiempo-servicios'] as $documento) {
            $this->actuarComo(codigo: 'asistente_legajo');
            $this->getJson(self::URL.$persona->id.'/'.$documento)->assertForbidden();
        }
        config(['permisos.roles.prueba_solo_admin' => ['sistema.administrar']]);
        $this->actuarComo(codigo: 'prueba_solo_admin');
        $this->getJson(self::URL.$persona->id.'/informe-escalafonario')->assertForbidden();
        $this->assertSame(0, AuditoriaEvento::query()->where('accion', 'reporte.exportado')->count());

        // Con acceso, pero sin sanciones ni sección 07: esas partes no salen y el informe lo dice.
        config(['permisos.roles.prueba_sin_sanciones' => ['sistema.administrar', 'legajo.ver']]);
        $this->actuarComo(codigo: 'prueba_sin_sanciones');
        $respuesta = $this->getJson(self::URL.$persona->id.'/informe-escalafonario')->assertOk()->assertJsonPath('data.sanciones', null)
            ->assertJsonPath('data.evaluaciones', null)->assertJsonPath('data.omitidas', ['Sanciones disciplinarias', 'Evaluaciones de desempeño']);
        $this->assertStringNotContainsString('RS-OCULTA', $respuesta->getContent());
    }

    public function test_sanctions_are_not_even_queried_without_permission(): void
    {
        [$persona, $legajo] = $this->persona();
        $documento = $this->documento($legajo, 8, ['nombre' => 'DOC-SANCION-SECRETA', 'num_resolucion' => 'RS-SECRETA-77'], ['codigo' => 'SAN_RESOLUCION_SANCION']);
        Sancion::factory()->create(['usuario_id' => $persona->id, 'documento_id' => $documento->id, 'numero_resolucion' => 'RS-SECRETA-77', 'falta_imputada' => 'FALTA SECRETA']);
        $consultas = [];
        DB::listen(function ($consulta) use (&$consultas): void {
            $consultas[] = $consulta->sql;
        });
        // Por referencia: una función flecha copiaría la lista vacía al definirse.
        $tocaSanciones = function () use (&$consultas): bool {
            return collect($consultas)->contains(fn (string $sql): bool => preg_match('/\bsanciones\b/', $sql) === 1);
        };

        config(['permisos.roles.prueba_sin_sanciones' => ['sistema.administrar', 'legajo.ver', 'seccion07.ver']]);
        $this->actuarComo(codigo: 'prueba_sin_sanciones');
        $consultas = [];
        foreach (['informe-escalafonario', 'constancia-trabajo', 'constancia-tiempo-servicios'] as $tipo) {
            $texto = $this->getJson(self::URL.$persona->id.'/'.$tipo)->assertOk()->getContent();
            foreach (['RS-SECRETA-77', 'FALTA SECRETA', 'DOC-SANCION-SECRETA', $documento->id] as $secreto) {
                $this->assertStringNotContainsString($secreto, $texto);
            }
        }
        $this->assertFalse($tocaSanciones(), 'Sin seccion08.sanciones.ver no se consulta la tabla de sanciones.');
        $auditoria = AuditoriaEvento::query()->where('accion', 'reporte.exportado')->pluck('datos_despues')->implode(' ');
        $this->assertStringNotContainsString('SECRETA', $auditoria);

        // La sonda sí detecta la consulta cuando el permiso existe.
        $this->actuarComo();
        $consultas = [];
        $this->getJson(self::URL.$persona->id.'/informe-escalafonario')->assertOk()->assertJsonPath('data.sanciones.0.numero_resolucion', 'RS-SECRETA-77');
        $this->assertTrue($tocaSanciones());
    }

    public function test_unknown_document_or_person_without_legajo_is_rejected(): void
    {
        $this->actuarComo();
        [$persona] = $this->persona();
        $sinLegajo = Usuario::factory()->create();

        $this->getJson(self::URL.$persona->id.'/otro-documento')->assertNotFound();
        $this->getJson(self::URL.$sinLegajo->id.'/constancia-trabajo')->assertConflict();
    }

    public function test_emitting_documents_does_not_change_the_records(): void
    {
        $this->actuarComo();
        [$persona, $legajo] = $this->persona();
        $this->vinculoAcreditado($persona, $legajo, ['fecha_fin_prevista' => '2020-12-31']);
        $this->tiempo($persona, $this->documento($legajo, 6));
        Capacitacion::factory()->create(['usuario_id' => $persona->id]);
        $tablas = ['usuarios', 'legajos', 'vinculos_laborales', 'vinculo_adendas', 'documentos_usuario', 'archivos_documento', 'tiempo_servicios_reconocidos', 'capacitaciones', 'historial_estados_documento'];
        $antes = collect($tablas)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->orderBy('id')->get()->toArray()])->all();

        foreach (['informe-escalafonario', 'constancia-trabajo', 'constancia-tiempo-servicios'] as $documento) {
            $this->getJson(self::URL.$persona->id.'/'.$documento)->assertOk();
        }

        $this->assertEquals($antes, collect($tablas)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->orderBy('id')->get()->toArray()])->all());
        $this->assertSame(3, AuditoriaEvento::query()->where('accion', 'reporte.exportado')->count());
    }
}
