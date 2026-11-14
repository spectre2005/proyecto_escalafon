<?php

namespace Tests\Unit\Support;

use App\Models\Plataforma\Institucion;
use App\Models\RequerimientoDocumento;
use App\Support\MensajeRequerimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Partes públicas y aisladas de MensajeRequerimiento: la referencia del asunto y los datos de la institución.
 * El armado completo del correo ya lo cubre Tests\Feature\MensajeRequerimientoTest.
 */
class MensajeRequerimientoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->forgetInstance(Institucion::class);
        parent::tearDown();
    }

    private function configuracion(array $settings): void
    {
        DB::table('institution_settings')->insert(['id' => 1, 'settings' => json_encode($settings), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function institucionDelPanel(string $nombre): void
    {
        app()->instance(Institucion::class, (new Institucion)->forceFill(['nombre' => $nombre]));
    }

    public function test_la_referencia_lleva_el_identificador_del_requerimiento(): void
    {
        $r = (new RequerimientoDocumento)->forceFill(['id' => '01a1d769-a13e-7271-9cc6-a18cd6453286']);

        $this->assertSame('[REQ-01a1d769-a13e-7271-9cc6-a18cd6453286]', MensajeRequerimiento::referencia($r));
    }

    public function test_sin_configuracion_guardada_no_inventa_nombre_ni_oficina_y_usa_el_color_por_defecto(): void
    {
        $this->assertSame(['nombre' => '', 'oficina' => '', 'color' => '#0f766e'], MensajeRequerimiento::institucion());
    }

    public function test_usa_el_nombre_la_oficina_y_el_color_configurados(): void
    {
        $this->configuracion(['name' => 'Universidad Ficticia', 'area' => 'Oficina de Recursos Humanos', 'reportColor' => '#1A2b3C']);

        $this->assertSame(['nombre' => 'Universidad Ficticia', 'oficina' => 'Oficina de Recursos Humanos', 'color' => '#1A2b3C'], MensajeRequerimiento::institucion());
    }

    public function test_un_color_con_formato_invalido_se_reemplaza_por_el_de_defecto(): void
    {
        foreach (['#fff', 'red', '#12345G', '#1234567', '0f766e;background:url(x)'] as $color) {
            DB::table('institution_settings')->delete();
            $this->configuracion(['name' => 'Universidad Ficticia', 'reportColor' => $color]);
            $this->assertSame('#0f766e', MensajeRequerimiento::institucion()['color'], $color);
        }
    }

    public function test_los_saltos_de_linea_y_espacios_del_nombre_se_reducen_a_una_linea(): void
    {
        $this->configuracion(['name' => "  Universidad\r\nFicticia\t del  Sur ", 'area' => "Oficina\nde personal"]);

        $datos = MensajeRequerimiento::institucion();
        $this->assertSame('Universidad Ficticia del Sur', $datos['nombre']);
        $this->assertSame('Oficina de personal', $datos['oficina']);
    }

    public function test_los_valores_por_defecto_del_formulario_no_se_presentan_como_confirmados(): void
    {
        $this->configuracion(['name' => 'Mi institución', 'area' => 'Gestión de personas']);

        $this->assertSame(['nombre' => '', 'oficina' => '', 'color' => '#0f766e'], MensajeRequerimiento::institucion());
    }

    public function test_sin_nombre_configurado_usa_el_nombre_registrado_en_el_panel(): void
    {
        $this->institucionDelPanel('Universidad del Panel');
        $this->configuracion(['name' => 'Mi institución']);

        $this->assertSame('Universidad del Panel', MensajeRequerimiento::institucion()['nombre']);
    }

    public function test_el_nombre_configurado_tiene_prioridad_sobre_el_del_panel(): void
    {
        $this->institucionDelPanel('Universidad del Panel');
        $this->configuracion(['name' => 'Universidad Ficticia']);

        $this->assertSame('Universidad Ficticia', MensajeRequerimiento::institucion()['nombre']);
    }

    public function test_valores_que_no_son_texto_o_json_corrupto_se_ignoran(): void
    {
        $this->configuracion(['name' => ['no', 'texto'], 'area' => 42, 'reportColor' => null]);
        $this->assertSame(['nombre' => '', 'oficina' => '', 'color' => '#0f766e'], MensajeRequerimiento::institucion());

        DB::table('institution_settings')->where('id', 1)->update(['settings' => '{no es json']);
        $this->assertSame(['nombre' => '', 'oficina' => '', 'color' => '#0f766e'], MensajeRequerimiento::institucion());
    }
}
