<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\ReferenciaLegajoPasivo;
use App\Models\RegimenNormativo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Formatos de la directiva (anexos 06 a 09), traídos de feature/posible_implementacion_funcional con sus correcciones. */
class FormatosDirectivaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/reportes/formatos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
    }

    private function documento(Legajo $legajo, int $seccion = 3, array $tipo = []): DocumentoUsuario
    {
        return DocumentoUsuario::factory()->create(['usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id,
            'archivo_id' => ArchivoDocumento::factory()->create(['estado_antivirus' => 'limpio'])->id, 'seccion_legajo_id' => $seccion,
            'tipo_documento_id' => TipoDocumento::factory()->create(['seccion_legajo_id' => $seccion, 'es_dato_sensible' => false, 'es_dato_salud' => false, ...$tipo])->id,
            'created_by' => Usuario::factory(), 'estado' => 'aceptado']);
    }

    public function test_matrix_pages_and_exports_all_rows_with_directive_columns_and_audits_the_export(): void
    {
        $admin = $this->actuarComo();
        foreach (range(1, 23) as $n) {
            Legajo::factory()->create(['numero_legajo' => sprintf('PRUEBA-%03d', $n), 'estado_legajo' => 'activo', 'fecha_apertura' => '2026-03-05',
                'usuario_id' => Usuario::factory()->create(['nombres' => 'Persona de prueba', 'tipo_doc_identidad' => 'dni'])->id]);
        }
        Legajo::factory()->create(['numero_legajo' => 'OTRO-001', 'estado_legajo' => 'pasivo']);

        $this->getJson(self::URL)->assertOk()->assertJsonCount(4, 'data');
        $this->getJson(self::URL.'/matriz?q=PRUEBA&estado=activo')->assertOk()->assertJsonPath('total', 23)->assertJsonCount(20, 'data')
            ->assertJsonPath('columnas.tipo_documento_identidad', 'Tipo de documento de identidad')
            ->assertJsonPath('data.0.tipo_documento_identidad', 'DNI')->assertJsonPath('data.0.apertura', '05/03/2026')->assertJsonPath('data.0.estado', 'Activo')
            ->assertJsonMissingPath('data.0.telefono');
        $this->getJson(self::URL.'/matriz?q=PRUEBA&estado=activo&exportar=1')->assertOk()->assertJsonCount(23, 'data');

        $evento = AuditoriaEvento::query()->where('accion', 'reporte.exportado')->sole();
        $this->assertSame($admin->id, $evento->actor_usuario_id);
        $this->assertSame(23, json_decode($evento->datos_despues, true)['filas']);
    }

    public function test_matrix_shows_the_law_and_link_type_of_the_current_link(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo']);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'nombre_puesto' => 'ESPECIALISTA', 'regimen_laboral' => 'plazo_determinado',
            'regimen_normativo_id' => RegimenNormativo::factory()->create(['nombre' => 'Decreto Legislativo N° 1057 (CAS)'])->id, 'fecha_inicio' => now()->subYear(), 'fecha_cese' => null]);

        $this->getJson(self::URL.'/matriz?legajo_id='.$legajo->id)->assertOk()->assertJsonPath('data.0.puesto', 'ESPECIALISTA')
            ->assertJsonPath('data.0.regimen', 'Decreto Legislativo N° 1057 (CAS) · Plazo determinado');
    }

    public function test_cover_needs_a_legajo_and_uses_day_month_year(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '00123456']);
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id, 'numero_legajo' => 'OTRO-001', 'estado_legajo' => 'pasivo', 'fecha_pasivo' => '2026-01-31']);

        $this->getJson(self::URL.'/caratula')->assertUnprocessable();
        $this->getJson(self::URL.'/caratula?legajo_id='.$legajo->id)->assertOk()->assertJsonPath('data.0.numero_legajo', 'OTRO-001')
            ->assertJsonPath('data.0.documento_identidad', '00123456')->assertJsonPath('data.0.cese', '31/01/2026');
    }

    public function test_incorporation_control_names_the_section_and_honors_dates_and_visibility(): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create(['estado_legajo' => 'activo']);
        $this->documento($legajo)->update(['folio_inicio' => 25, 'folio_fin' => 27, 'fecha_incorporacion' => '2026-10-10', 'motivo_incorporacion' => 'Obtención de grado']);
        $this->documento($legajo)->update(['estado' => 'anulado', 'fecha_incorporacion' => '2026-10-11']);
        $this->documento($legajo, 1, ['es_dato_sensible' => true])->update(['fecha_incorporacion' => '2026-10-12']);

        $this->getJson(self::URL.'/incorporaciones?desde=2026-10-01&hasta=2026-10-31&seccion=3')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.motivo', 'Obtención de grado')->assertJsonPath('data.0.folios', 'folios 25 al 27')
            ->assertJsonPath('data.0.seccion', '03 Formación académica y capacitación')->assertJsonPath('data.0.fecha', '10/10/2026')->assertJsonPath('data.0.estado', 'Aceptado');
        $this->getJson(self::URL.'/incorporaciones?desde=2026-11-01')->assertOk()->assertJsonPath('total', 0);

        // El anulado nunca aparece; la sección 01 solo para quien ve datos sensibles.
        $this->getJson(self::URL.'/incorporaciones')->assertOk()->assertJsonPath('total', 2);
        config(['permisos.roles.prueba_sin_sensibles' => ['sistema.administrar', 'legajo.ver']]);
        $this->actuarComo(codigo: 'prueba_sin_sensibles');
        $this->getJson(self::URL.'/incorporaciones')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.seccion', '03 Formación académica y capacitación');
    }

    public function test_passive_reference_has_the_directive_columns(): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create(['estado_legajo' => 'activo']);
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $legajo->usuario_id, 'nombre_puesto' => 'TÉCNICO', 'regimen_laboral' => 'carrera', 'fecha_cese' => '2020-12-31']);
        ReferenciaLegajoPasivo::factory()->create(['legajo_id' => $legajo->id, 'vinculo_laboral_id' => $vinculo->id, 'folio_inicio_pasivo' => 10, 'folio_fin_pasivo' => 11,
            'tipo_documento_id' => TipoDocumento::factory()->create(['seccion_legajo_id' => 3, 'nombre' => 'Grado de bachiller', 'es_dato_sensible' => false, 'es_dato_salud' => false])->id,
            'fedateado' => true, 'fecha_fedateo' => '1998-11-25', 'fiscalizado' => false]);

        $this->getJson(self::URL.'/referencias')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.seccion', '03 Formación académica y capacitación')
            ->assertJsonPath('data.0.puesto', 'TÉCNICO')->assertJsonPath('data.0.cese', '31/12/2020')->assertJsonPath('data.0.folios', 'folios 10 al 11')
            ->assertJsonPath('data.0.fedateado', 'SÍ')->assertJsonPath('data.0.fecha_fedateo', '25/11/1998')->assertJsonPath('data.0.fiscalizado', 'NO')
            ->assertJsonMissingPath('data.0.legajo_origen');
    }

    public function test_search_finds_names_in_any_order_identity_numbers_and_both_states(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['nombres' => 'Ana Lucía', 'apellido_paterno' => 'Torres', 'apellido_materno' => 'Quispe', 'numero_doc_identidad' => '99000841']);
        Legajo::factory()->create(['usuario_id' => $persona->id, 'numero_legajo' => 'DEMO-ACTIVO-001', 'estado_legajo' => 'activo']);
        $pasivo = Legajo::factory()->create(['usuario_id' => $persona->id, 'numero_legajo' => 'DEMO-PASIVO-002', 'estado_legajo' => 'pasivo']);
        Legajo::factory()->create(['usuario_id' => Usuario::factory()->create(['nombres' => 'Ana', 'apellido_paterno' => 'Rojas'])->id]);

        foreach ([self::URL.'/legajos', self::URL.'/matriz'] as $ruta) {
            foreach (['Ana Torres', 'Torres Ana', '  ANA   TORRES ', '99000841'] as $texto) {
                $this->getJson($ruta.'?'.http_build_query(['q' => $texto]))->assertOk()->assertJsonPath('total', 2);
            }
            $this->getJson($ruta.'?q=DEMO-PASIVO')->assertOk()->assertJsonPath('total', 1);
            $this->getJson($ruta.'?q=SinCoincidencias')->assertOk()->assertJsonPath('total', 0);
        }
        $this->getJson(self::URL.'/legajos?q=DEMO-PASIVO')->assertJsonPath('data.0.id', $pasivo->id)->assertJsonMissingPath('data.0.usuario.domicilio');
    }

    public function test_only_who_administers_the_system_can_use_the_formats(): void
    {
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson(self::URL)->assertForbidden();
        $this->getJson(self::URL.'/legajos?q=Ana')->assertForbidden();
        $this->getJson(self::URL.'/matriz?exportar=1')->assertForbidden();
        $this->getJson(self::URL.'/otro')->assertNotFound();
    }
}
