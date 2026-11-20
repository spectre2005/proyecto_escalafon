<?php

namespace Tests\Unit\Rules;

use App\Models\EscalaNivelCarrera;
use App\Models\RegimenNormativo;
use App\Rules\NivelCarrera;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Con las escalas por defecto que carga la migración (D.L. 276 y 728). */
class NivelCarreraTest extends TestCase
{
    use RefreshDatabase;

    private function falla(array $datos, ?string $ley = '276', ?string $anterior = null, ?string $grupoAnterior = null): ?string
    {
        $validador = Validator::make($datos, ['nivel_carrera' => ['nullable', new NivelCarrera($anterior, $ley, $grupoAnterior)]]);

        return $validador->fails() ? $validador->errors()->first('nivel_carrera') : null;
    }

    /** @return array<string, array{string, string, string}> */
    public static function coherentes(): array
    {
        return [
            '276 profesional' => ['276', 'profesional', 'SPA'], '276 técnico' => ['276', 'tecnico', 'STF'], '276 auxiliar' => ['276', 'auxiliar', 'SAC'],
            '276 en minúsculas' => ['276', 'profesional', 'spb'], '728 técnico' => ['728', 'tecnico', 'T-3'], '728 auxiliar' => ['728', 'auxiliar', 'A-1'],
        ];
    }

    #[DataProvider('coherentes')]
    public function test_acepta_un_nivel_de_su_ley_y_su_grupo(string $ley, string $grupo, string $nivel): void
    {
        $this->assertNull($this->falla(['grupo_ocupacional' => $grupo, 'nivel_carrera' => $nivel], $ley));
    }

    public function test_los_niveles_van_del_ingreso_al_maximo(): void
    {
        $this->assertSame(['STF', 'STE', 'STD', 'STC', 'STB', 'STA'], NivelCarrera::niveles('276', 'tecnico'));
        $this->assertSame('P-1', NivelCarrera::niveles('728', 'profesional')[0]);
        $this->assertSame([], NivelCarrera::niveles('1057', 'tecnico'));
    }

    public function test_rechaza_un_nivel_de_otro_grupo_o_de_otra_ley(): void
    {
        $this->assertSame('El nivel de carrera no corresponde al grupo auxiliar de esta ley: elige uno de SAF, SAE, SAD, SAC, SAB, SAA.',
            $this->falla(['grupo_ocupacional' => 'auxiliar', 'nivel_carrera' => 'SPA']));
        $this->assertNotNull($this->falla(['grupo_ocupacional' => 'tecnico', 'nivel_carrera' => 'STB'], '728'), 'STB es del 276, no del 728');
        $this->assertNotNull($this->falla(['grupo_ocupacional' => 'profesional', 'nivel_carrera' => 'HEROICO']));
    }

    public function test_en_cas_no_hay_nivel_y_una_ley_sin_escala_lo_deja_vacio(): void
    {
        $this->assertSame(NivelCarrera::SIN_NIVEL['1057'], $this->falla(['grupo_ocupacional' => 'tecnico', 'nivel_carrera' => 'STB'], '1057'));
        $this->assertStringContainsString('no tiene escala de niveles registrada', $this->falla(['grupo_ocupacional' => 'tecnico', 'nivel_carrera' => 'CA1'], '30057'));
        $this->assertNull($this->falla(['grupo_ocupacional' => 'tecnico', 'nivel_carrera' => ''], '1057'));
    }

    public function test_la_escala_que_registra_la_institucion_es_la_que_vale(): void
    {
        // La Ley 30057 recibe su escala desde Configuración: desde ese momento se puede elegir.
        $ley = RegimenNormativo::query()->where('codigo', '30057')->firstOrFail();
        EscalaNivelCarrera::factory()->create(['regimen_normativo_id' => $ley->id, 'grupo_ocupacional' => 'profesional', 'codigo' => 'CA2', 'orden' => 1]);
        $this->assertNull($this->falla(['grupo_ocupacional' => 'profesional', 'nivel_carrera' => 'CA2'], '30057'));
        $this->assertStringContainsString('no tiene niveles registrados para el grupo tecnico', $this->falla(['grupo_ocupacional' => 'tecnico', 'nivel_carrera' => 'CA2'], '30057'));

        // Un nivel desactivado deja de ofrecerse y de aceptarse en registros nuevos.
        EscalaNivelCarrera::query()->where('codigo', 'STA')->update(['activo' => false]);
        $this->assertNotContains('STA', NivelCarrera::niveles('276', 'tecnico'));
        $this->assertNotNull($this->falla(['grupo_ocupacional' => 'tecnico', 'nivel_carrera' => 'STA']));
    }

    public function test_sin_ley_o_sin_grupo_pide_definirlos_primero(): void
    {
        $this->assertStringContainsString('Define primero la ley', $this->falla(['grupo_ocupacional' => 'tecnico', 'nivel_carrera' => 'STB'], null));
        $this->assertSame('Elige primero el grupo ocupacional.', $this->falla(['nivel_carrera' => 'SPA']));
    }

    public function test_un_nivel_anterior_que_no_cambia_se_conserva(): void
    {
        $this->assertNull($this->falla(['grupo_ocupacional' => 'profesional', 'nivel_carrera' => 'superior'], '1057', 'SUPERIOR', 'profesional'));
        $this->assertNull($this->falla(['nivel_carrera' => 'SUPERIOR'], '276', 'SUPERIOR', 'profesional'), 'sin enviar el grupo, el grupo no cambia');
        $this->assertNotNull($this->falla(['grupo_ocupacional' => 'profesional', 'nivel_carrera' => 'OTRO'], '276', 'SUPERIOR', 'profesional'));
    }

    public function test_un_nivel_anterior_no_se_conserva_si_cambia_el_grupo(): void
    {
        // SPA es del grupo profesional: al pasar a técnico ya no vale aunque el nivel no cambie.
        $this->assertSame('El nivel de carrera no corresponde al grupo tecnico de esta ley: elige uno de STF, STE, STD, STC, STB, STA.',
            $this->falla(['grupo_ocupacional' => 'tecnico', 'nivel_carrera' => 'SPA'], '276', 'SPA', 'profesional'));
    }
}
