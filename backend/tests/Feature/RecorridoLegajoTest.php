<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\SeccionLegajo;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class RecorridoLegajoTest extends TestCase
{
    use RefreshDatabase, SubeDocumentos;

    private Usuario $persona;

    private Usuario $actor;

    private Legajo $legajo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('legajo.disco_documentos'));
        TipoDocumento::query()->update(['activo' => false]);
        $this->persona = Usuario::factory()->create(['regimen_laboral' => 'carrera', 'tipo_personal' => 'administrativo']);
        $this->actor = $this->actuarComo();
        $this->legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo']);
    }

    private function seccion(int $id, int $orden, array $cambios = []): TipoDocumento
    {
        $seccion = SeccionLegajo::query()->find($id) ?? SeccionLegajo::factory()->create(['id' => $id]);
        $seccion->update(['orden' => $orden, 'nombre' => 'Sección '.$id]);
        LegajoSeccion::factory()->create(['legajo_id' => $this->legajo->id, 'seccion_legajo_id' => $id]);

        return TipoDocumento::factory()->create($cambios + ['seccion_legajo_id' => $id, 'codigo' => 'GUIA_'.$id, 'nombre' => 'Evidencia '.$id]);
    }

    private function accion(string $accion, ?int $actual = null, array $otros = []): TestResponse
    {
        return $this->postJson('/api/v1/expedientes/'.$this->persona->id.'/recorrido', ['accion' => $accion, 'seccion_actual_id' => $actual, ...$otros]);
    }

    private function estado(): TestResponse
    {
        return $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/recorrido');
    }

    private function documento(TipoDocumento $tipo, string $estado = 'pendiente'): DocumentoUsuario
    {
        $contenido = '%PDF-1.4 evidencia';
        $path = $this->legajo->id.'/'.$tipo->id.'.pdf';
        Storage::disk(config('legajo.disco_documentos'))->put($path, $contenido);
        $archivo = ArchivoDocumento::factory()->create(['almacenamiento' => config('legajo.disco_documentos'), 'storage_path' => $path,
            'hash_sha256' => hash('sha256', $contenido), 'estado_antivirus' => 'pendiente', 'subido_por' => $this->actor->id]);

        return DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $this->legajo->id,
            'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => $tipo->seccion_legajo_id, 'archivo_id' => $archivo->id,
            'folio_inicio' => 1, 'folio_fin' => 1, 'estado' => $estado, 'created_by' => $this->actor->id]);
    }

    public function test_el_orden_y_las_secciones_salen_del_catalogo_y_la_configuracion(): void
    {
        $this->seccion(2, 50);
        $this->seccion(4, 10);
        $this->seccion(12, 1);
        $this->assertSame([4, 2], array_column($this->estado()->assertOk()->json('data.secciones'), 'id'));
        $this->accion('iniciar')->assertOk()->assertJsonPath('data.seccion_actual_id', 4);
        config(['legajo.secciones_registro_guiado' => [2]]);
        $this->assertSame([2], array_column($this->estado()->assertOk()->json('data.secciones'), 'id'));
        $this->accion('iniciar')->assertOk()->assertJsonPath('data.seccion_actual_id', 2);
    }

    public function test_obligatorios_pendientes_aceptados_y_observados_permiten_avanzar_sin_recepcion(): void
    {
        $tipo = $this->seccion(2, 1);
        $this->seccion(4, 2, ['obligatorio' => false]);
        $this->accion('iniciar')->assertOk();
        $this->accion('continuar', 2)->assertUnprocessable()->assertJsonValidationErrors('seccion_actual_id');
        $doc = $this->documento($tipo);
        foreach (['pendiente', 'aceptado', 'observado', 'cargado'] as $estado) {
            $doc->update(['estado' => $estado]);
            $this->estado()->assertJsonPath('data.resumen.obligatorios_completos', 1)->assertJsonPath('data.secciones.0.puede_continuar', true);
        }
        $this->accion('continuar', 2)->assertOk()->assertJsonPath('data.seccion_actual_id', 4);
        $this->assertNull($doc->refresh()->fecha_recepcion);
        $doc->update(['estado' => 'anulado']);
        $this->estado()->assertJsonPath('data.resumen.obligatorios_completos', 0);
    }

    public function test_opcionales_permiten_omitir_y_el_progreso_se_recupera_con_auditoria(): void
    {
        $this->seccion(2, 1, ['obligatorio' => false]);
        $this->seccion(4, 2);
        $this->accion('iniciar')->assertOk();
        $this->accion('continuar', 2)->assertUnprocessable()->assertJsonValidationErrors('accion');
        $this->accion('omitir', 2)->assertOk()->assertJsonPath('data.seccion_actual_id', 4)->assertJsonPath('data.secciones.0.estado', 'omitida');
        $this->estado()->assertOk()->assertJsonPath('data.seccion_actual_id', 4)->assertJsonPath('data.resumen.opcionales_omitidos', 1);
        $this->accion('iniciar')->assertOk()->assertJsonPath('data.seccion_actual_id', 4);
        $this->accion('anterior', 4)->assertOk()->assertJsonPath('data.seccion_actual_id', 2);
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertDatabaseCount('recorridos_legajo', 1);
        $eventos = AuditoriaEvento::query()->where('entidad', 'recorridos_legajo')->orderBy('id')->get();
        $this->assertSame(['recorrido.iniciar', 'recorrido.omitir', 'recorrido.iniciar', 'recorrido.anterior'], $eventos->pluck('accion')->all());
        $this->assertSame($eventos[0]->hash_registro, $eventos[1]->hash_previo);
    }

    public function test_se_salta_una_seccion_no_aplicable_y_no_salta_una_sin_catalogo(): void
    {
        $this->seccion(3, 1, ['requiere_estudios_basicos' => true]);
        $this->seccion(4, 2);
        $this->accion('iniciar')->assertOk()->assertJsonPath('data.seccion_actual_id', 4)->assertJsonCount(1, 'data.avisos')->assertJsonPath('data.secciones.0.estado', 'no_aplica');
        TipoDocumento::query()->where('seccion_legajo_id', 4)->update(['activo' => false]);
        $this->estado()->assertJsonPath('data.secciones.1.estado', 'sin_configurar');
        $this->accion('continuar', 4)->assertUnprocessable();
    }

    public function test_el_archivo_hash_antivirus_y_folio_se_verifican_antes_de_avanzar(): void
    {
        $tipo = $this->seccion(2, 1);
        $doc = $this->documento($tipo);
        $this->accion('iniciar')->assertOk();
        $this->accion('verificar', 2, ['documento_id' => $doc->id])->assertOk()->assertJsonPath('data.documento_verificado', $doc->id);
        foreach ([['hash_sha256' => ''], ['estado_antivirus' => ''], ['estado_antivirus' => 'infectado'], ['hash_sha256' => str_repeat('a', 64)]] as $cambio) {
            $original = $doc->archivo->getAttributes();
            $doc->archivo->update($cambio);
            $this->accion('verificar', 2, ['documento_id' => $doc->id])->assertUnprocessable()->assertJsonValidationErrors('documento_id');
            $this->accion('continuar', 2)->assertUnprocessable();
            $doc->archivo->update($original);
        }
        $doc->update(['folio_fin' => null]);
        $this->accion('verificar', 2, ['documento_id' => $doc->id])->assertUnprocessable();
        $doc->update(['folio_fin' => 1]);
        Storage::disk(config('legajo.disco_documentos'))->delete($doc->archivo->storage_path);
        $this->accion('continuar', 2)->assertUnprocessable();
        $this->estado()->assertJsonPath('data.seccion_actual_id', 2)->assertJsonPath('data.resumen.obligatorios_completos', 0);
    }

    public function test_el_progreso_se_recalcula_tras_anulacion_y_se_reabre_al_reanudar(): void
    {
        $tipo = $this->seccion(2, 1);
        $doc = $this->documento($tipo, 'observado');
        $this->accion('iniciar')->assertOk();
        $this->accion('continuar', 2)->assertOk()->assertJsonPath('data.seccion_actual_id', null);
        $this->estado()->assertJsonPath('data.resumen.documentos_observados', 1);
        $doc->update(['estado' => 'anulado']);
        $this->accion('iniciar')->assertOk()->assertJsonPath('data.seccion_actual_id', 2);
    }

    public function test_los_permisos_no_exponen_datos_sensibles_y_las_posiciones_son_por_actor(): void
    {
        $this->seccion(1, 1);
        $this->seccion(2, 2, ['obligatorio' => false]);
        $this->accion('iniciar')->assertOk();
        $this->actuarComo(null, 'asistente_legajo');
        $this->estado()->assertOk()->assertJsonPath('data.iniciado', false)->assertJsonPath('data.secciones.0.estado', 'restringida')->assertJsonPath('data.secciones.0.items', []);
        $this->accion('iniciar')->assertOk()->assertJsonPath('data.seccion_actual_id', 1);
        $this->accion('continuar', 1)->assertUnprocessable();
        $this->actuarComo(null, 'LECTOR_SIN_PERMISOS');
        $this->estado()->assertForbidden();
        $this->accion('iniciar')->assertForbidden();
    }

    public function test_rechaza_posiciones_desactualizadas_y_documentos_de_otro_legajo(): void
    {
        $tipo = $this->seccion(2, 1, ['obligatorio' => false]);
        $this->seccion(4, 2);
        $this->accion('continuar', 2)->assertConflict();
        $this->accion('iniciar')->assertOk();
        $this->accion('omitir', 2)->assertOk();
        $this->accion('omitir', 2)->assertConflict();
        $doc = $this->documento($tipo);
        $doc->update(['legajo_id' => Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'cerrado'])->id]);
        $this->accion('verificar', 4, ['documento_id' => $doc->id])->assertNotFound();
    }

    public function test_cada_familiar_requiere_su_evidencia_y_las_subsanaciones_cuentan(): void
    {
        $tipo = $this->seccion(1, 1, ['codigo' => 'S01_I_DNI_CONYUGE_CONCUBINO']);
        $a = Familiar::factory()->create(['usuario_id' => $this->persona->id, 'parentesco' => 'conyuge']);
        Familiar::factory()->create(['usuario_id' => $this->persona->id, 'parentesco' => 'concubino']);
        $doc = $this->documento($tipo);
        $a->update(['documento_id' => $doc->id]);
        $this->estado()->assertOk()->assertJsonPath('data.resumen.obligatorios', 2)->assertJsonPath('data.resumen.obligatorios_completos', 1);
        $nuevo = $this->documento($tipo, 'aceptado');
        $nuevo->update(['subsana_a_documento_id' => $doc->id]);
        $doc->update(['estado' => 'observado']);
        $this->estado()->assertJsonPath('data.resumen.obligatorios_completos', 1);
    }

    public function test_las_cargas_existentes_siguen_guardando_y_el_recorrido_no_las_modifica(): void
    {
        $this->prepararDemo();
        $this->persona = $this->personaDemo();
        $this->legajo = Legajo::query()->where('usuario_id', $this->persona->id)->firstOrFail();
        $docId = $this->subir($this->persona, $this->metadatos($this->tipo02('a'), 1, 2))->assertCreated()->json('data.id');
        $doc = DocumentoUsuario::query()->findOrFail($docId);
        $antes = $doc->getAttributes();
        $this->accion('iniciar')->assertOk();
        $actual = $this->estado()->json('data.seccion_actual_id');
        $this->accion('ir', $actual, ['seccion_destino_id' => 2])->assertOk();
        $this->accion('verificar', 2, ['documento_id' => $docId])->assertOk();
        $this->assertSame($antes, $doc->refresh()->getAttributes());
        $this->assertDatabaseCount('documentos_usuario', 1);
    }

    public function test_las_migraciones_del_recorrido_son_reversibles(): void
    {
        $this->seccion(2, 1);
        $this->accion('iniciar')->assertOk();
        $migration = require database_path('migrations/2026_10_07_072316_create_recorrido_legajos_table.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('recorridos_legajo', 0);
        $this->assertDatabaseHas('legajos', ['id' => $this->legajo->id]);
    }

    public function test_las_banderas_y_un_regimen_desconocido_se_recalculan_sin_inventar_requisitos(): void
    {
        $tipo = $this->seccion(2, 1, ['requiere_periodo_prueba' => true]);
        $this->persona->update(['tiene_periodo_prueba' => false]);
        $this->estado()->assertOk()->assertJsonPath('data.secciones.0.items.0.exigido', false);
        $this->persona->update(['tiene_periodo_prueba' => true]);
        $this->estado()->assertJsonPath('data.secciones.0.items.0.exigido', true)->assertJsonPath('data.secciones.0.puede_continuar', false);
        $tipo->update(['aplica_plazo_determinado' => false, 'aplica_designado' => false]);
        $this->persona->update(['regimen_laboral' => 'DL1057']);
        $this->estado()->assertJsonPath('data.secciones.0.items.0.exigido', null)->assertJsonPath('data.resumen.por_determinar', 1);
        $this->accion('iniciar')->assertOk();
        $this->accion('omitir', 2)->assertUnprocessable();
    }

    public function test_reutiliza_la_condicion_de_la_subseccion_de_periodo_de_prueba(): void
    {
        $tipo = $this->seccion(2, 1);
        $subseccion = SubseccionLegajo::query()->where('seccion_legajo_id', 2)->where('codigo', '2.4')->first()
            ?? SubseccionLegajo::factory()->create(['seccion_legajo_id' => 2, 'codigo' => '2.4']);
        $tipo->update(['subseccion_legajo_id' => $subseccion->id]);
        $this->persona->update(['tiene_periodo_prueba' => false]);
        $this->accion('iniciar')->assertOk()->assertJsonPath('data.seccion_actual_id', null)->assertJsonPath('data.secciones.0.estado', 'no_aplica');
        $this->persona->update(['tiene_periodo_prueba' => true]);
        $this->accion('iniciar')->assertOk()->assertJsonPath('data.seccion_actual_id', 2)->assertJsonPath('data.secciones.0.items.0.exigido', true);
    }

    public function test_omitir_no_oculta_un_obligatorio_que_el_catalogo_exija_despues(): void
    {
        $tipo = $this->seccion(2, 1, ['obligatorio' => false]);
        $this->accion('iniciar')->assertOk();
        $this->accion('omitir', 2)->assertOk()->assertJsonPath('data.seccion_actual_id', null);
        $tipo->update(['obligatorio' => true]);
        $this->estado()->assertJsonPath('data.secciones.0.estado', 'pendiente')->assertJsonPath('data.resumen.obligatorios_pendientes', 1);
        $this->accion('iniciar')->assertOk()->assertJsonPath('data.seccion_actual_id', 2);
    }

    public function test_los_permisos_de_evaluacion_y_sanciones_tambien_se_exigen_en_el_recorrido(): void
    {
        $this->seccion(7, 1);
        $this->seccion(8, 2, ['codigo' => 'SAN_RESOLUCION_SANCION', 'obligatorio' => false]);
        $this->actuarComo(null, 'asistente_legajo');
        $this->estado()->assertOk()->assertJsonPath('data.secciones.0.estado', 'restringida')->assertJsonPath('data.secciones.0.items', [])
            ->assertJsonPath('data.secciones.1.items.0.estado', 'restringido')->assertJsonPath('data.secciones.1.items.0.documentos', []);
        $this->accion('iniciar')->assertOk();
        $this->accion('ir', 7, ['seccion_destino_id' => 8])->assertOk();
        $this->accion('omitir', 8)->assertUnprocessable();
    }
}
