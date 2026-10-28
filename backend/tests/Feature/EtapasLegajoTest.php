<?php

namespace Tests\Feature;

use App\Models\DeclaracionVerificacion;
use App\Models\Desvinculacion;
use App\Models\DocumentoUsuario;
use App\Models\Induccion;
use App\Models\Legajo;
use App\Models\PerfilDocente;
use App\Models\RatificacionDocente;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Database\Seeders\Seccion01CatalogoSeeder;
use Database\Seeders\Seccion02CatalogoSeeder;
use Database\Seeders\Seccion11CatalogoSeeder;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class EtapasLegajoTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private Legajo $anterior;

    private VinculoLaboral $vinculo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        $this->seed([SeccionesLegajoCatalogoSeeder::class, Seccion01CatalogoSeeder::class, Seccion02CatalogoSeeder::class, Seccion11CatalogoSeeder::class]);
        Storage::fake(config('legajo.disco_documentos'));
        $this->actuarComo();
        $this->persona = Usuario::factory()->create();
        $this->anterior = Legajo::factory()->create(['usuario_id' => $this->persona->id, 'fecha_apertura' => '2025-01-01']);
        $this->vinculo = VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'fecha_inicio' => '2025-01-01', 'fecha_cese' => '2025-10-01']);
    }

    private function url(): string
    {
        return '/api/v1/expedientes/'.$this->persona->id;
    }

    private function reingresar(): string
    {
        $this->postJson($this->url().'/seccion-11/desvinculaciones', ['vinculo_laboral_id' => $this->vinculo->id, 'motivo_cese' => 'otro', 'fecha_cese' => '2025-10-01'])->assertCreated();

        return $this->postJson($this->url().'/legajo')->assertCreated()->json('data.id');
    }

    public function test_nuevo_legajo_empieza_vacio_y_el_cese_anterior_se_muestra_solo_en_historial(): void
    {
        Induccion::factory()->create(['usuario_id' => $this->persona->id, 'vinculo_laboral_id' => $this->vinculo->id]);
        $tipo = TipoDocumento::query()->where('codigo', 'S11_C_TERMINO_VINCULO')->firstOrFail();
        DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $this->anterior->id, 'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => 11]);
        $nuevo = $this->reingresar();
        $this->getJson($this->url().'/seccion-02')->assertOk()->assertJsonCount(0, 'data.vinculos')->assertJsonCount(0, 'data.inducciones');
        $data = $this->getJson($this->url().'/seccion-11')->assertOk()->assertJsonCount(0, 'data.desvinculaciones')->assertJsonCount(0, 'data.vinculos')
            ->assertJsonPath('data.historial.0.legajo.id', $this->anterior->id)->json('data');
        $this->assertSame(0, collect($data['tipos'])->sum('documentos'));
        $this->assertDatabaseCount('vinculos_laborales', 1);
        $this->assertDatabaseCount('desvinculaciones', 1);
        $this->assertSame(0, DocumentoUsuario::query()->where('legajo_id', $nuevo)->count());
        $this->assertSame(0, (int) Legajo::query()->findOrFail($nuevo)->legajoSecciones()->max('ultimo_folio'));
        $this->getJson($this->url().'/vinculos-laborales?legajo_id='.$this->anterior->id)->assertOk()->assertJsonPath('total', 1);
        $this->getJson($this->url().'/vinculos-laborales?legajo_id='.$nuevo)->assertOk()->assertJsonPath('total', 0);
        $this->getJson($this->url().'/inducciones?legajo_id='.$this->anterior->id)->assertOk()->assertJsonPath('total', 1);
        $this->getJson($this->url().'/inducciones?legajo_id='.$nuevo)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_la_identidad_se_conserva_pero_el_dni_y_declaraciones_del_nuevo_legajo_no_se_heredan(): void
    {
        $tipo = TipoDocumento::query()->where('codigo', 'S01_B_DOCUMENTO_IDENTIDAD')->firstOrFail();
        DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $this->anterior->id, 'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => 1]);
        DeclaracionVerificacion::factory()->create(['usuario_id' => $this->persona->id]);
        $dni = $this->persona->numero_doc_identidad;
        $this->reingresar();
        $datos = $this->getJson($this->url().'/seccion-01')->assertOk()->assertJsonCount(0, 'data.declaraciones')->json('data');
        $this->assertSame(0, collect($datos['tipos'])->sum('documentos'));
        $this->assertSame($dni, $this->persona->fresh()->numero_doc_identidad);
        $this->getJson($this->url().'/declaraciones-verificaciones?legajo_id='.$this->anterior->id)->assertOk()->assertJsonPath('total', 1);
    }

    public function test_no_permite_modificar_ni_enlazar_registros_de_la_etapa_anterior(): void
    {
        $this->reingresar();
        $this->patchJson($this->url().'/vinculos-laborales/'.$this->vinculo->id, ['nombre_puesto' => 'Cambio'])->assertNotFound();
        $tipo = TipoDocumento::query()->where('codigo', 'S11_C_TERMINO_VINCULO')->firstOrFail();
        $cese = Desvinculacion::query()->where('vinculo_laboral_id', $this->vinculo->id)->firstOrFail();
        $this->subir($this->persona, $this->metadatos($tipo, 1, 2, ['enlace_id' => $cese->id]))->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->assertSame($this->anterior->id, $this->vinculo->fresh()->legajo_id);
        $this->assertNull($cese->fresh()->documento_id);
    }

    public function test_un_vinculo_abierto_de_un_legajo_anterior_no_recibe_adendas_nuevas(): void
    {
        $this->vinculo->update(['fecha_cese' => null]);
        $this->postJson($this->url().'/legajos/'.$this->anterior->id.'/cerrar')->assertOk();
        $this->postJson($this->url().'/legajo')->assertCreated();
        $this->postJson($this->url().'/vinculo-adendas', [
            'vinculo_laboral_id' => $this->vinculo->id, 'fecha_inicio' => '2026-10-28', 'fecha_fin' => '2026-12-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');
        $this->assertDatabaseCount('vinculo_adendas', 0);
        $this->getJson('/api/v1/usuarios/'.$this->persona->id)->assertOk()->assertJsonPath('data.situacion', 'sin_vinculo_vigente');
    }

    public function test_los_requerimientos_anteriores_no_son_pendientes_de_la_nueva_etapa(): void
    {
        $requerimiento = RequerimientoDocumento::factory()->create(['usuario_id' => $this->persona->id, 'clase' => 'actualizacion', 'estado' => 'abierto', 'tipo_documento_id' => null]);
        $nuevo = $this->reingresar();
        $actual = RequerimientoDocumento::factory()->create(['usuario_id' => $this->persona->id, 'clase' => 'actualizacion', 'estado' => 'abierto', 'tipo_documento_id' => null]);
        $this->assertSame($this->anterior->id, $requerimiento->fresh()->legajo_id);
        $this->assertSame($nuevo, $actual->fresh()->legajo_id);
        $this->getJson('/api/v1/solicitudes-legajo')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $actual->id);
        $this->getJson('/api/v1/solicitudes-legajo?historial=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $requerimiento->id);
    }

    public function test_migracion_usa_el_documento_para_identificar_la_etapa_sin_copiarla_al_reingreso(): void
    {
        $nuevo = $this->reingresar();
        $tipo = TipoDocumento::query()->where('codigo', 'S11_C_TERMINO_VINCULO')->firstOrFail();
        DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $this->anterior->id, 'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => 11, 'vinculo_laboral_id' => $this->vinculo->id]);
        DB::table('vinculos_laborales')->where('id', $this->vinculo->id)->update(['legajo_id' => null]);
        $perfil = PerfilDocente::factory()->create(['vinculo_laboral_id' => $this->vinculo->id]);
        $ratificacion = RatificacionDocente::factory()->create(['usuario_id' => $this->persona->id, 'perfil_docente_id' => $perfil->id]);
        DB::table('ratificaciones_docentes')->where('id', $ratificacion->id)->update(['legajo_id' => null]);
        $migracion = require database_path('migrations/2026_10_28_223201_asociar_registros_de_etapa_a_legajos.php');
        $migracion->up();
        $this->assertSame($this->anterior->id, $ratificacion->fresh()->legajo_id);
        $this->assertSame($this->anterior->id, $this->vinculo->fresh()->legajo_id);
        $this->getJson($this->url().'/vinculos-laborales?legajo_id='.$nuevo)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_migracion_conserva_antecedentes_ambiguos_sin_asignarlos_al_nuevo_legajo(): void
    {
        $nuevo = $this->reingresar();
        DB::table('vinculos_laborales')->where('id', $this->vinculo->id)->update(['legajo_id' => null]);
        $migracion = require database_path('migrations/2026_10_28_223201_asociar_registros_de_etapa_a_legajos.php');
        $migracion->up();
        $this->assertNull($this->vinculo->fresh()->legajo_id);
        $this->getJson($this->url().'/seccion-02')->assertOk()->assertJsonCount(0, 'data.vinculos');
        $this->getJson($this->url().'/vinculos-laborales')->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('activo', Legajo::query()->findOrFail($nuevo)->estado_legajo);
    }
}
