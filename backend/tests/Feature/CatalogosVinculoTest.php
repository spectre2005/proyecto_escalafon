<?php

namespace Tests\Feature;

use App\Models\ModalidadVinculo;
use App\Models\Puesto;
use App\Models\RegimenNormativo;
use App\Models\TipoProcesoSeleccion;
use App\Models\TipoVinculo;
use App\Models\UnidadOrganica;
use App\Models\VinculoLaboral;
use Database\Seeders\ModalidadVinculoSeeder;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catálogos del vínculo (unidades, puestos, modalidades, procesos de selección y tipos de vínculo): se crean con código
 * correlativo y auditoría, no se duplican, se desactivan en vez de borrarse y el vínculo los usa de forma coherente con
 * su tipo. El número de registro es el del legajo: un vínculo no tiene número propio.
 */
class CatalogosVinculoTest extends TestCase
{
    use RefreshDatabase;

    private const ALTA = '/api/v1/usuarios/con-vinculo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(12, 0));
        $this->seed([SeccionesLegajoCatalogoSeeder::class, ModalidadVinculoSeeder::class]);
    }

    private function modalidad(string $nombre): ModalidadVinculo
    {
        return ModalidadVinculo::query()->where('nombre', $nombre)->firstOrFail();
    }

    private function proceso(string $nombre): TipoProcesoSeleccion
    {
        return TipoProcesoSeleccion::query()->where('nombre', $nombre)->firstOrFail();
    }

    private function alta(array $vinculo = [], string $dni = '71000002'): array
    {
        return [
            'usuarios' => [[
                'numero_doc_identidad' => $dni, 'nombres' => 'Ana', 'apellido_paterno' => 'Catálogo', 'email_institucional' => "ana.$dni@example.test",
                'tipo_personal' => 'administrativo', 'regimen_normativo_id' => RegimenNormativo::query()->where('codigo', '1057')->value('id'),
            ]],
            'vinculo' => ['regimen_laboral' => 'plazo_determinado', 'nombre_puesto' => 'Texto escrito', 'fecha_inicio' => '2026-03-01', ...$vinculo],
            'datos_puesto' => [],
        ];
    }

    public function test_crea_elementos_con_codigo_correlativo_y_auditoria(): void
    {
        $this->actuarComo();
        UnidadOrganica::factory()->create(['codigo' => 'UO-007']);
        $padre = $this->postJson('/api/v1/catalogos-vinculo/unidades', ['nombre' => 'Vicerrectorado Académico', 'tipo' => 'alta_direccion'])
            ->assertCreated()->assertJsonPath('data.codigo', 'UO-008')->json('data.id');
        $this->postJson('/api/v1/catalogos-vinculo/unidades', ['nombre' => 'Unidad de Posgrado', 'tipo' => 'unidad', 'unidad_padre_id' => $padre])
            ->assertCreated()->assertJsonPath('data.codigo', 'UO-009');
        $this->postJson('/api/v1/catalogos-vinculo/puestos', ['nombre' => 'Analista en Presupuesto', 'tipo_personal' => 'administrativo', 'exige_colegiatura' => true])
            ->assertCreated()->assertJsonPath('data.codigo', 'PU-001');
        // Los tres procesos de partida ocupan PS-001 a PS-003.
        $this->postJson('/api/v1/catalogos-vinculo/procesos', ['nombre' => 'Concurso interno'])->assertCreated()->assertJsonPath('data.codigo', 'PS-004');
        $this->assertDatabaseHas('auditoria_eventos', ['accion' => 'catalogo.crear', 'entidad' => 'puestos']);
    }

    public function test_no_duplica_por_mayusculas_y_evita_ciclos_en_la_jerarquia(): void
    {
        $this->actuarComo();
        $this->postJson('/api/v1/catalogos-vinculo/puestos', ['nombre' => 'Analista', 'tipo_personal' => 'administrativo'])->assertCreated();
        $this->postJson('/api/v1/catalogos-vinculo/puestos', ['nombre' => '  ANALISTA ', 'tipo_personal' => 'administrativo'])->assertJsonValidationErrors('nombre');
        $this->postJson('/api/v1/catalogos-vinculo/modalidades', ['nombre' => 'cas (d.l. 1057)', 'regimen_laboral' => 'plazo_determinado'])->assertJsonValidationErrors('nombre');
        $this->postJson('/api/v1/catalogos-vinculo/procesos', ['nombre' => 'proceso cas'])->assertJsonValidationErrors('nombre');
        $a = UnidadOrganica::factory()->create();
        $b = UnidadOrganica::factory()->create(['unidad_padre_id' => $a->id]);
        $this->patchJson('/api/v1/catalogos-vinculo/unidades/'.$a->id, ['unidad_padre_id' => $b->id])->assertJsonValidationErrors('unidad_padre_id');
    }

    public function test_desactivar_no_borra_y_requiere_permiso(): void
    {
        $this->actuarComo();
        $puesto = Puesto::query()->create(['codigo' => 'PU-001', 'nombre' => 'Analista', 'tipo_personal' => 'administrativo']);
        $this->patchJson('/api/v1/catalogos-vinculo/puestos/'.$puesto->id, ['activo' => false])->assertOk()->assertJsonPath('data.activo', false);
        $this->assertDatabaseHas('auditoria_eventos', ['accion' => 'catalogo.desactivar', 'entidad_id' => $puesto->id]);
        $this->postJson('/api/v1/catalogos-vinculo/emisores', ['nombre' => 'x'])->assertNotFound();
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->postJson('/api/v1/catalogos-vinculo/puestos', ['nombre' => 'Otro', 'tipo_personal' => 'administrativo'])->assertForbidden();
        $this->getJson('/api/v1/catalogos-vinculo')->assertOk()->assertJsonPath('data.puestos.0.activo', false);
    }

    public function test_de_los_tipos_de_vinculo_solo_se_edita_la_descripcion(): void
    {
        $this->actuarComo();
        $carrera = TipoVinculo::query()->where('codigo', 'carrera')->firstOrFail();
        $this->patchJson('/api/v1/catalogos-vinculo/tipos_vinculo/'.$carrera->id, ['descripcion' => 'Nombrados y docentes ordinarios.'])->assertOk()
            ->assertJsonPath('data.nombre', 'Carrera')->assertJsonPath('data.descripcion', 'Nombrados y docentes ordinarios.');
        $this->patchJson('/api/v1/catalogos-vinculo/tipos_vinculo/'.$carrera->id, ['nombre' => 'Otro nombre', 'activo' => false])->assertStatus(422);
        $this->postJson('/api/v1/catalogos-vinculo/tipos_vinculo', ['descripcion' => 'x'])->assertStatus(422);
        $this->getJson('/api/v1/catalogos-vinculo')->assertOk()->assertJsonCount(3, 'data.tipos_vinculo');
    }

    public function test_el_alta_usa_el_catalogo_y_el_numero_es_el_del_legajo(): void
    {
        $this->actuarComo();
        $puesto = Puesto::query()->create(['codigo' => 'PU-001', 'nombre' => 'Analista en Presupuesto', 'tipo_personal' => 'administrativo']);
        $siguiente = $this->getJson('/api/v1/expediente-opciones')->assertOk()->json('data.siguiente_legajo');
        $alta = $this->postJson(self::ALTA, $this->alta(['modalidad_vinculo_id' => $this->modalidad('CAS (D.L. 1057)')->id, 'puesto_id' => $puesto->id,
            'tipo_proceso_seleccion_id' => $this->proceso('Proceso CAS')->id, 'fecha_resultado_seleccion' => '2026-02-20', 'documento_vinculo_fecha' => '2026-02-25']))
            ->assertCreated()->assertJsonPath('data.legajo.numero_legajo', $siguiente);
        $vinculo = VinculoLaboral::query()->findOrFail($alta->json('data.vinculo.id'));
        // El vínculo no recibe un número propio: el número de registro es el del legajo.
        $this->assertSame(['Analista en Presupuesto', 'Proceso CAS', null, '2026-02-25'],
            [$vinculo->nombre_puesto, $vinculo->proceso_seleccion, $vinculo->numero_documento_vinculo, $vinculo->documento_vinculo_fecha->toDateString()]);
        // Otro vínculo de la misma persona en la sección 02 sigue en el mismo legajo, sin otro número.
        $this->postJson('/api/v1/expedientes/'.$alta->json('data.usuario.id').'/vinculos-laborales', ['regimen_laboral' => 'designado', 'nombre_puesto' => 'JEFE', 'fecha_inicio' => '2026-04-01'])
            ->assertCreated();
        $this->assertSame([$alta->json('data.legajo.id')], VinculoLaboral::query()->where('usuario_id', $alta->json('data.usuario.id'))->distinct()->pluck('legajo_id')->all());
        $this->assertNull(VinculoLaboral::query()->where('nombre_puesto', 'JEFE')->value('numero_documento_vinculo'));
        // Renombrar el puesto en el catálogo no cambia el legajo.
        $this->patchJson('/api/v1/catalogos-vinculo/puestos/'.$puesto->id, ['nombre' => 'Analista de Presupuesto Público'])->assertOk();
        $this->assertSame('Analista en Presupuesto', $vinculo->fresh()->nombre_puesto);
        $this->getJson('/api/v1/catalogos-vinculo')->assertOk()->assertJsonFragment(['id' => $puesto->id, 'personas' => 1]);
    }

    public function test_el_alta_rechaza_elementos_que_no_corresponden(): void
    {
        $this->actuarComo();
        $docente = Puesto::query()->create(['codigo' => 'PU-001', 'nombre' => 'Profesor', 'tipo_personal' => 'docente']);
        $this->postJson(self::ALTA, $this->alta(['modalidad_vinculo_id' => $this->modalidad('Cargo de confianza')->id]))->assertJsonValidationErrors('vinculo.modalidad_vinculo_id');
        $this->postJson(self::ALTA, $this->alta(['puesto_id' => $docente->id]))->assertJsonValidationErrors('vinculo.puesto_id');
        // Un designado no pasa por proceso de selección.
        $this->postJson(self::ALTA, $this->alta(['regimen_laboral' => 'designado', 'tipo_proceso_seleccion_id' => $this->proceso('Proceso CAS')->id]))
            ->assertJsonValidationErrors('vinculo.tipo_proceso_seleccion_id');
        $cas = $this->modalidad('CAS (D.L. 1057)');
        $cas->update(['exige_fin_previsto' => true]);
        $this->postJson(self::ALTA, $this->alta(['modalidad_vinculo_id' => $cas->id]))->assertJsonValidationErrors('vinculo.fecha_fin_prevista');
        $cas->update(['exige_fin_previsto' => false, 'activo' => false]);
        $this->postJson(self::ALTA, $this->alta(['modalidad_vinculo_id' => $cas->id]))->assertJsonValidationErrors('vinculo.modalidad_vinculo_id');
        $this->assertSame(0, VinculoLaboral::query()->count());
    }

    public function test_la_seccion_02_corrige_con_las_mismas_reglas_y_conserva_lo_desactivado(): void
    {
        $this->actuarComo();
        $cas = $this->modalidad('CAS (D.L. 1057)');
        $alta = $this->postJson(self::ALTA, $this->alta(['modalidad_vinculo_id' => $cas->id]))->assertCreated();
        $url = '/api/v1/expedientes/'.$alta->json('data.usuario.id').'/vinculos-laborales/'.$alta->json('data.vinculo.id');
        // Cambiar a designado deja la modalidad CAS fuera de su tipo.
        $this->patchJson($url, ['regimen_laboral' => 'designado'])->assertJsonValidationErrors('modalidad_vinculo_id');
        // Una modalidad desactivada después de guardarse no impide corregir otros datos del vínculo.
        $cas->update(['activo' => false]);
        $this->patchJson($url, ['documento_vinculo_fecha' => '2026-03-02'])->assertOk()->assertJsonPath('data.documento_vinculo_fecha', '2026-03-02T00:00:00.000000Z');
        $this->patchJson($url, ['tipo_proceso_seleccion_id' => $this->proceso('Concurso público de méritos')->id])->assertOk()
            ->assertJsonPath('data.proceso_seleccion', 'Concurso público de méritos');
    }
}
