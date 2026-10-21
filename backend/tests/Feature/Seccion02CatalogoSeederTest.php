<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use Database\Seeders\Seccion02CatalogoSeeder;
use Database\Seeders\Seccion02DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Seccion02CatalogoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_four_subsections_directive_types_and_the_teacher_resolution_repeatably(): void
    {
        $this->seed(Seccion02CatalogoSeeder::class);
        $this->seed(Seccion02CatalogoSeeder::class);

        $this->assertSame(['2.1', '2.2', '2.3', '2.4'], SubseccionLegajo::query()->where('seccion_legajo_id', 2)->orderBy('orden')->pluck('codigo')->all());
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 2)->with('subseccionLegajo')->orderBy('orden')->get();
        $this->assertCount(14, $tipos);
        $this->assertSame([
            'S02_A_RESULTADO_SELECCION' => ['2.1', null], 'S02_B_INFORME_REQUISITOS_PUESTO' => ['2.1', null],
            'S02_C_RESOLUCION_DESIGNACION' => ['2.2', 'vinculos_laborales'], 'S02_D_RESOLUCION_RENOVACION' => ['2.2', 'vinculos_laborales'],
            'S02_E_RESOLUCION_CARRERA' => ['2.2', 'vinculos_laborales'], 'S02_F_CONTRATO' => ['2.2', 'vinculos_laborales'],
            'S02_G_ADENDA' => ['2.2', 'vinculo_adendas'], 'S02_H_REGISTRO_INDUCCION' => ['2.3', 'inducciones'],
            'S02_I_CARGO_PERFIL_PUESTO' => ['2.3', null], 'S02_J_CARGO_REGLAMENTO_INTERNO' => ['2.3', null],
            'S02_K_CARGO_CODIGO_ETICA' => ['2.3', null], 'S02_L_OTROS_INDUCCION' => ['2.3', null],
            'S02_M_EVALUACION_PERIODO_PRUEBA' => ['2.4', 'periodos_prueba'],
            'S02_RESOLUCION_DOCENTE' => ['2.2', 'vinculos_laborales'],
        ], $tipos->mapWithKeys(fn (TipoDocumento $tipo): array => [$tipo->codigo => [$tipo->subseccionLegajo->codigo, $tipo->formulario_datos]])->all());
        $this->assertSame(['S02_D_RESOLUCION_RENOVACION', 'S02_G_ADENDA', 'S02_H_REGISTRO_INDUCCION', 'S02_L_OTROS_INDUCCION'], $tipos->where('permite_multiples', true)->pluck('codigo')->values()->all());
        $this->assertSame(['S02_M_EVALUACION_PERIODO_PRUEBA'], $tipos->where('requiere_periodo_prueba', true)->pluck('codigo')->values()->all());
    }

    public function test_it_keeps_existing_data_forms_and_types_with_documents(): void
    {
        $this->seed(Seccion02CatalogoSeeder::class);
        $contrato = TipoDocumento::query()->where('codigo', 'S02_F_CONTRATO')->firstOrFail();
        $contrato->update(['formulario_datos' => 'otra_tabla']);
        // Un tipo con documentos, mal puesto en 2.1: se desactiva y se crea bien, sin alterar el original.
        $adenda = TipoDocumento::query()->where('codigo', 'S02_G_ADENDA')->firstOrFail();
        $adenda->update(['subseccion_legajo_id' => SubseccionLegajo::query()->where('codigo', '2.1')->value('id')]);
        $legajo = Legajo::factory()->create();
        DocumentoUsuario::factory()->create(['tipo_documento_id' => $adenda->id, 'legajo_id' => $legajo->id, 'usuario_id' => $legajo->usuario_id, 'seccion_legajo_id' => 2]);
        $extra = TipoDocumento::factory()->create(['seccion_legajo_id' => 2, 'codigo' => 'NO_DIRECTIVA']);

        $this->seed(Seccion02CatalogoSeeder::class);
        $this->seed(Seccion02CatalogoSeeder::class);

        $this->assertSame('otra_tabla', $contrato->refresh()->formulario_datos);
        $this->assertFalse($adenda->refresh()->activo);
        $this->assertSame('2.1', $adenda->subseccionLegajo->codigo);
        $nueva = TipoDocumento::query()->where('codigo', 'S02_G_ADENDA')->where('activo', true)->sole();
        $this->assertSame('2.2', $nueva->subseccionLegajo->codigo);
        $this->assertFalse($extra->refresh()->activo);
        $this->assertSame(14, TipoDocumento::query()->where('seccion_legajo_id', 2)->where('activo', true)->count());
    }

    public function test_demo_seeder_is_repeatable(): void
    {
        $this->seed(Seccion02DemoSeeder::class);
        $this->seed(Seccion02DemoSeeder::class);

        $this->assertSame(2, Legajo::query()->where('numero_legajo', 'like', 'DEMO-S02-%')->count());
        $this->assertDatabaseCount('vinculos_laborales', 3);
        $this->assertDatabaseCount('vinculo_adendas', 2);
    }
}
