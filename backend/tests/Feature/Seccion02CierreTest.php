<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\PerfilAdministrativo;
use App\Models\PerfilDocente;
use App\Models\RegimenNormativo;
use App\Models\SeccionLegajo;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class Seccion02CierreTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDemo();
    }

    private function url(Usuario $persona, string $ruta): string
    {
        return '/api/v1/expedientes/'.$persona->id.'/'.$ruta;
    }

    private function vinculoVigente(Usuario $persona): VinculoLaboral
    {
        return VinculoLaboral::query()->where('usuario_id', $persona->id)->whereNull('fecha_cese')->firstOrFail();
    }

    public function test_migration_adds_annulment_columns_without_breaking_existing_rows(): void
    {
        $this->assertTrue(Schema::hasColumns('vinculos_laborales', ['anulado', 'motivo_anulacion', 'registrado_por']));
        $this->assertTrue(Schema::hasColumns('vinculo_adendas', ['anulado', 'motivo_anulacion', 'registrado_por']));
        // Filas de la demo creadas antes de la anulación: no anuladas y sin autor.
        $vinculo = $this->vinculoVigente($this->personaDemo())->refresh();
        $this->assertFalse($vinculo->anulado);
        $this->assertNull($vinculo->registrado_por);
        $this->assertSame(0, VinculoAdenda::query()->where('anulado', true)->count());
    }

    public function test_new_link_stores_who_registered_it(): void
    {
        $autor = $this->actuarComo();
        $persona = $this->personaDemo();

        $id = $this->postJson($this->url($persona, 'vinculos-laborales'), ['tipo_personal' => 'administrativo', 'regimen_laboral' => 'designado',
            'regimen_normativo_id' => RegimenNormativo::factory()->create(['aplica_a' => 'administrativo'])->id, 'nombre_puesto' => 'Jefe de oficina', 'fecha_inicio' => today()->toDateString()])
            ->assertCreated()->assertJsonPath('data.registrado_por', $autor->id)->json('data.id');
        $fila = collect($this->getJson($this->url($persona, 'seccion-02'))->json('data.vinculos'))->firstWhere('id', $id);
        $this->assertSame(trim($autor->nombres.' '.$autor->apellido_paterno.' '.$autor->apellido_materno), $fila['registrado_por']);
        // registrado_por no se recibe del cliente.
        $this->postJson($this->url($persona, 'vinculos-laborales'), ['registrado_por' => $persona->id])->assertUnprocessable()->assertJsonValidationErrors('registrado_por');
    }

    public function test_addendum_is_annulled_with_reason_and_leaves_lists_and_validity(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        $vinculo = $this->vinculoVigente($persona);
        $ultima = VinculoAdenda::query()->where('vinculo_laboral_id', $vinculo->id)->orderByDesc('fecha_inicio')->firstOrFail();
        $anterior = VinculoAdenda::query()->where('vinculo_laboral_id', $vinculo->id)->whereKeyNot($ultima->id)->firstOrFail();
        $anular = $this->url($persona, 'seccion-02/vinculo-adendas/'.$ultima->id.'/anular');

        $this->postJson($anular, [])->assertUnprocessable()->assertJsonValidationErrors('motivo_anulacion');
        $this->postJson($anular, ['motivo_anulacion' => 'Adenda registrada por error'])->assertOk()->assertJsonPath('data.anulado', true);
        $this->postJson($anular, ['motivo_anulacion' => 'Segundo intento'])->assertConflict();
        $this->assertNotContains($ultima->id, array_column($this->getJson($this->url($persona, 'vinculo-adendas'))->assertOk()->json('data'), 'id'));
        $this->assertDatabaseHas('vinculo_adendas', ['id' => $ultima->id, 'anulado' => true, 'motivo_anulacion' => 'Adenda registrada por error']);

        $fila = collect($this->getJson($this->url($persona, 'seccion-02'))->json('data.vinculos'))->firstWhere('id', $vinculo->id);
        $this->assertSame([$anterior->id], array_column($fila['adendas'], 'id'));
        // El fin efectivo ya no usa la adenda anulada.
        $this->assertSame($anterior->fecha_fin->format('Y-m-d'), $fila['vencimiento']['fin_efectivo']);
        $this->getJson($this->url($persona, 'seccion-02/anulados'))->assertOk()
            ->assertJsonPath('data.vinculo_adendas.0.id', $ultima->id)->assertJsonPath('data.vinculo_adendas.0.values.motivo_anulacion', 'Adenda registrada por error');
        // Una adenda anulada no recibe documentos.
        $this->subir($persona, $this->metadatos($this->tipo02('g'), 1, 1, ['enlace_id' => $ultima->id]))->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
    }

    public function test_link_is_annulled_and_excluded_from_lists_counters_and_new_records(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo('99000202');
        $vinculo = $this->vinculoVigente($persona);
        $this->getJson($this->url($persona, 'seccion-02'))->assertJsonCount(1, 'data.vinculos')->assertJsonPath('data.subsecciones.1.datos', 'completo');

        $this->postJson($this->url($persona, 'seccion-02/vinculos-laborales/'.$vinculo->id.'/anular'), ['motivo_anulacion' => 'Vínculo duplicado'])->assertOk();

        $this->getJson($this->url($persona, 'seccion-02'))->assertOk()->assertJsonCount(0, 'data.vinculos')
            // Sin vínculos, el tipo de vínculo queda Sin definir: no se toma la ley de la persona.
            ->assertJsonPath('data.subsecciones.1.datos', 'ninguno')->assertJsonPath('data.perfil.regimenes', []);
        $this->getJson($this->url($persona, 'seccion-02/anulados'))->assertJsonPath('data.vinculos_laborales.0.id', $vinculo->id);
        // Tampoco en el listado genérico de la ficha.
        $this->getJson($this->url($persona, 'vinculos-laborales'))->assertOk()->assertJsonPath('total', 0);
        $this->patchJson($this->url($persona, 'vinculos-laborales/'.$vinculo->id), ['nombre_puesto' => 'Otro'])->assertConflict();
        $this->postJson($this->url($persona, 'inducciones'), ['vinculo_laboral_id' => $vinculo->id, 'tipo' => 'especifica', 'fecha' => today()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');
        $this->putJson($this->url($persona, 'vinculos-laborales/'.$vinculo->id.'/datos-puesto'), ['condicion_docente' => 'ordinario'])->assertConflict();
    }

    public function test_annulment_requires_administration_permission(): void
    {
        $persona = $this->personaDemo();
        $vinculo = $this->vinculoVigente($persona);
        $this->actuarComo(null, 'asistente_legajo');

        $this->postJson($this->url($persona, 'seccion-02/vinculos-laborales/'.$vinculo->id.'/anular'), ['motivo_anulacion' => 'Sin permiso'])->assertForbidden();
        $this->assertFalse($vinculo->refresh()->anulado);
    }

    public function test_job_data_is_registered_and_corrected_only_while_the_link_is_open(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        $vinculo = $this->vinculoVigente($persona);
        $url = $this->url($persona, 'vinculos-laborales/'.$vinculo->id.'/datos-puesto');
        $perfil = PerfilAdministrativo::query()->where('vinculo_laboral_id', $vinculo->id)->firstOrFail();

        $this->putJson($url, ['grupo_ocupacional' => 'gerencial'])->assertUnprocessable()->assertJsonValidationErrors('grupo_ocupacional');
        $this->putJson($url, ['condicion_docente' => 'ordinario'])->assertUnprocessable()->assertJsonValidationErrors('condicion_docente');
        // Corrige el mismo registro (no crea otro).
        $this->putJson($url, ['grupo_ocupacional' => 'tecnico', 'nivel_carrera' => 'SP-AP', 'cargo_estructural' => 'Técnico', 'es_cargo_confianza' => true])
            ->assertOk()->assertJsonPath('data.id', $perfil->id)->assertJsonPath('data.grupo_ocupacional', 'tecnico');
        $this->assertSame(1, PerfilAdministrativo::query()->where('vinculo_laboral_id', $vinculo->id)->count());

        $docente = $this->vinculoVigente($this->personaDemo('99000202'));
        $urlDocente = $this->url($this->personaDemo('99000202'), 'vinculos-laborales/'.$docente->id.'/datos-puesto');
        $this->putJson($urlDocente, ['condicion_docente' => 'contratado', 'categoria' => 'principal'])->assertUnprocessable()->assertJsonValidationErrors('categoria');
        $this->putJson($urlDocente, ['condicion_docente' => 'ordinario', 'subtipo_extraordinario' => 'emerito'])->assertUnprocessable()->assertJsonValidationErrors('subtipo_extraordinario');
        $this->putJson($urlDocente, ['condicion_docente' => 'ordinario', 'categoria' => 'asociado', 'dedicacion' => 'exclusiva', 'codigo_renacyt' => 'P0001'])->assertOk()->assertJsonPath('data.categoria', 'asociado');
        $this->assertSame(1, PerfilDocente::query()->where('vinculo_laboral_id', $docente->id)->count());

        // Vínculo cesado: sus datos del puesto ya no se corrigen.
        $cesado = VinculoLaboral::query()->where('usuario_id', $persona->id)->whereNotNull('fecha_cese')->firstOrFail();
        $this->putJson($this->url($persona, 'vinculos-laborales/'.$cesado->id.'/datos-puesto'), ['grupo_ocupacional' => 'auxiliar'])->assertConflict();
    }

    public function test_summary_counts_loaded_and_received_documents_separately_from_real_documents(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        $url = $this->url($persona, 'seccion-02');
        $this->getJson($url)->assertJsonPath('data.resumen.exigidos_cargados', 0)->assertJsonPath('data.resumen.exigidos_recepcionados', 0);

        $a = $this->subir($persona, $this->metadatos($this->tipo02('a'), 1, 1))->assertCreated()->json('data.id');
        $this->subir($persona, $this->metadatos($this->tipo02('b'), 2, 3))->assertCreated();
        $this->postJson($this->url($persona, 'documentos/'.$a.'/recepcionar'))->assertOk();

        $respuesta = $this->getJson($url)->assertOk()
            ->assertJsonPath('data.resumen.exigidos_cargados', 2)->assertJsonPath('data.resumen.exigidos_recepcionados', 1)
            ->assertJsonPath('data.subsecciones.0.exigidos_cargados', 2)->assertJsonPath('data.subsecciones.0.exigidos_recepcionados', 1)
            ->assertJsonPath('data.subsecciones.0.tipos.0.estado', 'recepcionado')->assertJsonPath('data.subsecciones.0.tipos.1.estado', 'cargado');
        // Cada documento en su tipo, con metadatos, folios, estado y archivo (sin rutas de almacenamiento).
        $documento = $respuesta->json('data.subsecciones.0.tipos.1.lista.0');
        $this->assertSame([2, 3, 'cargado', 'Oficina de Recursos Humanos', 2], [$documento['values']['folio_inicio'], $documento['values']['folio_fin'],
            $documento['estado_visible'], $documento['values']['emisor'], $documento['archivo']['num_paginas']]);
        $this->assertArrayNotHasKey('storage_path', $documento['archivo']);
        // Los datos enlazados viajan con el documento (vínculo de c a f).
        $vinculo = $this->vinculoVigente($persona);
        $this->subir($persona, $this->metadatos($this->tipo02('c'), 4, 4, ['enlace_id' => $vinculo->id]))->assertCreated();
        $this->getJson($url)->assertJsonPath('data.subsecciones.1.tipos.0.lista.0.values.vinculo_laboral_id', $vinculo->id);
    }

    public function test_open_legajo_creates_twelve_normative_sections_and_only_one_active(): void
    {
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $this->assertTrue(SeccionLegajo::query()->whereKey(13)->exists());
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $otra = Usuario::factory()->create();
        $anio = today()->format('Y');

        // La sección adicional 13 está en el catálogo, pero la apertura no la inicializa.
        $id = $this->postJson($this->url($persona, 'legajo'))->assertCreated()->assertJsonPath('data.estado_legajo', 'activo')
            ->assertJsonPath('data.numero_legajo', "LEG-{$anio}-000001")->assertJsonPath('data.secciones', 12)->json('data.id');
        $this->assertSame(range(1, 12), LegajoSeccion::query()->where('legajo_id', $id)->orderBy('seccion_legajo_id')->pluck('seccion_legajo_id')->all());
        $this->assertSame(0, (int) LegajoSeccion::query()->where('legajo_id', $id)->sum('ultimo_folio'));
        $this->postJson($this->url($persona, 'legajo'))->assertConflict();
        $this->assertSame(1, Legajo::query()->where('usuario_id', $persona->id)->count());
        $this->postJson($this->url($otra, 'legajo'))->assertCreated()->assertJsonPath('data.numero_legajo', "LEG-{$anio}-000002");
        $this->postJson($this->url($otra, 'legajo'), ['numero_legajo' => 'X'])->assertUnprocessable();

        // Con el legajo abierto ya se pueden subir documentos, con folios desde 1.
        $this->getJson($this->url($persona, 'documentos/folio-sugerido?seccion=2'))->assertJsonPath('data.folio_sugerido', 1);
        $this->subir($persona, $this->metadatos($this->tipo02('a'), 1, 1))->assertCreated();
        $this->assertSame(1, LegajoSeccion::query()->where('legajo_id', $id)->where('seccion_legajo_id', 2)->value('ultimo_folio'));
    }

    public function test_open_legajo_does_not_require_additional_passive_section_and_keeps_twelve_normative_sections(): void
    {
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        SeccionLegajo::query()->whereKey(13)->delete();

        $id = $this->postJson($this->url($persona, 'legajo'))->assertCreated()->assertJsonPath('data.estado_legajo', 'activo')
            ->assertJsonPath('data.secciones', 12)->json('data.id');
        $this->assertSame(range(1, 12), LegajoSeccion::query()->where('legajo_id', $id)->orderBy('seccion_legajo_id')->pluck('seccion_legajo_id')->all());
        $this->assertSame(0, (int) LegajoSeccion::query()->where('legajo_id', $id)->sum('ultimo_folio'));

        // Sin la fila 13, la carga en las secciones normativas funciona con folios desde 1.
        $this->subir($persona, $this->metadatos($this->tipo02('a'), 1, 1))->assertCreated();
        $this->assertSame(1, LegajoSeccion::query()->where('legajo_id', $id)->where('seccion_legajo_id', 2)->value('ultimo_folio'));
    }

    public function test_opening_does_not_touch_existing_legajos_and_passive_references_do_not_need_section_13_row(): void
    {
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $admin = $this->actuarComo();
        // Legajo anterior con su fila 13 y folios: la apertura de otro legajo no lo toca.
        $anterior = Legajo::factory()->create(['estado_legajo' => 'pasivo']);
        foreach (range(1, 13) as $seccion) {
            LegajoSeccion::factory()->create(['legajo_id' => $anterior->id, 'seccion_legajo_id' => $seccion, 'ultimo_folio' => $seccion === 13 ? 5 : 0]);
        }
        $filasAnteriores = LegajoSeccion::query()->where('legajo_id', $anterior->id)->orderBy('seccion_legajo_id')->get(['id', 'seccion_legajo_id', 'ultimo_folio'])->toArray();

        $persona = Usuario::factory()->create();
        $id = $this->postJson($this->url($persona, 'legajo'))->assertCreated()->assertJsonPath('data.secciones', 12)->json('data.id');
        $this->assertSame($filasAnteriores, LegajoSeccion::query()->where('legajo_id', $anterior->id)->orderBy('seccion_legajo_id')->get(['id', 'seccion_legajo_id', 'ultimo_folio'])->toArray());

        // Una referencia a un legajo pasivo se registra sin que exista la fila 13 del legajo nuevo, y no la crea.
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id]);
        $this->postJson('/api/v1/referencias-legajo-pasivo', ['legajo_id' => $id, 'vinculo_laboral_id' => $vinculo->id,
            'tipo_documento_id' => $this->tipo02('a')->id, 'folio_inicio_pasivo' => 1, 'folio_fin_pasivo' => 3,
            'responsable_legajo_id' => $admin->id])->assertCreated();
        $this->assertFalse(LegajoSeccion::query()->where('legajo_id', $id)->where('seccion_legajo_id', 13)->exists());
        $this->assertSame(range(1, 12), LegajoSeccion::query()->where('legajo_id', $id)->orderBy('seccion_legajo_id')->pluck('seccion_legajo_id')->all());
    }

    public function test_open_legajo_requires_permission_and_complete_catalog(): void
    {
        $persona = Usuario::factory()->create();
        $this->actuarComo(null, 'asistente_legajo');
        $this->postJson($this->url($persona, 'legajo'))->assertForbidden();

        $this->actuarComo();
        SeccionLegajo::query()->whereKey(12)->delete();
        $this->postJson($this->url($persona, 'legajo'))->assertConflict();
        $this->assertSame(0, Legajo::query()->where('usuario_id', $persona->id)->count());
    }
}
