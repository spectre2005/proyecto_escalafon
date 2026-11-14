<?php

namespace Tests\Unit\Support;

use App\Models\PerfilAdministrativo;
use App\Models\PerfilDocente;
use App\Models\RegimenNormativo;
use App\Models\VinculoLaboral;
use App\Support\RegimenesLaborales;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegimenesLaboralesTest extends TestCase
{
    use RefreshDatabase;

    private function ley(string $codigo, array $datos = []): RegimenNormativo
    {
        return (new RegimenNormativo)->forceFill(['codigo' => $codigo, 'nombre' => "Ley {$codigo}", 'aplica_a' => 'administrativo', 'activo' => true, ...$datos]);
    }

    /** Vínculo en memoria con perfil y ley ya cargados. */
    private function vinculo(?string $tipo, string $tipoPersonal = 'administrativo', ?string $condicionDocente = null, ?bool $confianza = null, ?string $ley = null): VinculoLaboral
    {
        $vinculo = (new VinculoLaboral)->forceFill(['regimen_laboral' => $tipo, 'tipo_personal' => $tipoPersonal, 'fecha_inicio' => '2027-03-18']);
        $vinculo->setRelation('perfilDocente', new EloquentCollection($condicionDocente ? [(new PerfilDocente)->forceFill(['condicion_docente' => $condicionDocente])] : []));
        $vinculo->setRelation('perfilAdministrativo', new EloquentCollection($confianza === null ? [] : [(new PerfilAdministrativo)->forceFill(['es_cargo_confianza' => $confianza])]));
        $vinculo->setRelation('regimenNormativo', $ley ? $this->ley($ley) : null);

        return $vinculo;
    }

    private function catalogo(string $codigo): RegimenNormativo
    {
        return RegimenNormativo::query()->where('codigo', $codigo)->firstOrFail();
    }

    public function test_por_iniciar_dice_el_tipo_y_la_fecha(): void
    {
        $this->assertSame('Por iniciar: Carrera desde el 18/03/2027.', RegimenesLaborales::porIniciar($this->vinculo('carrera')));
        $this->assertSame('Por iniciar: Designado (confianza) desde el 18/03/2027.', RegimenesLaborales::porIniciar($this->vinculo('designado')));
    }

    public function test_por_iniciar_sin_tipo_o_con_valor_antiguo_dice_tipo_sin_definir(): void
    {
        $this->assertSame('Por iniciar: tipo sin definir desde el 18/03/2027.', RegimenesLaborales::porIniciar($this->vinculo(null)));
        $this->assertSame('Por iniciar: tipo sin definir desde el 18/03/2027.', RegimenesLaborales::porIniciar($this->vinculo('276')));
    }

    public function test_sugerencia_es_nula_si_el_vinculo_ya_tiene_tipo(): void
    {
        $this->assertNull(RegimenesLaborales::sugerencia($this->vinculo('designado', 'docente', 'ordinario')));
    }

    public function test_sugerencia_para_docentes_segun_su_condicion(): void
    {
        $this->assertSame(['valor' => 'carrera', 'motivo' => 'Docente ordinario.'], RegimenesLaborales::sugerencia($this->vinculo(null, 'docente', 'ordinario')));
        $this->assertSame(['valor' => 'plazo_determinado', 'motivo' => 'Docente contratado.'], RegimenesLaborales::sugerencia($this->vinculo(null, 'docente', 'contratado')));
        // Extraordinario o sin perfil: los datos no alcanzan.
        $this->assertNull(RegimenesLaborales::sugerencia($this->vinculo(null, 'docente', 'extraordinario'))['valor']);
        $this->assertNull(RegimenesLaborales::sugerencia($this->vinculo(null, 'docente'))['valor']);
    }

    public function test_sugerencia_no_usa_la_condicion_docente_en_un_vinculo_administrativo(): void
    {
        $this->assertNull(RegimenesLaborales::sugerencia($this->vinculo(null, 'administrativo', 'ordinario'))['valor']);
    }

    public function test_sugerencia_cargo_de_confianza_es_designado(): void
    {
        $this->assertSame(['valor' => 'designado', 'motivo' => 'Cargo de confianza.'], RegimenesLaborales::sugerencia($this->vinculo(null, confianza: true)));
        $this->assertNull(RegimenesLaborales::sugerencia($this->vinculo(null, confianza: false))['valor']);
    }

    public function test_sugerencia_con_276_no_sugiere_aunque_sea_cargo_de_confianza(): void
    {
        $this->assertSame(['valor' => null, 'motivo' => 'Confirma si es carrera o designado.'], RegimenesLaborales::sugerencia($this->vinculo(null, confianza: true, ley: '276')));
    }

    public function test_sugerencia_cas_es_plazo_determinado_salvo_cargo_de_confianza(): void
    {
        $this->assertSame(['valor' => 'plazo_determinado', 'motivo' => 'Contrato CAS (D.L. 1057).'], RegimenesLaborales::sugerencia($this->vinculo(null, ley: '1057')));
        $this->assertSame('designado', RegimenesLaborales::sugerencia($this->vinculo(null, confianza: true, ley: '1057'))['valor']);
    }

    public function test_sugerencia_sin_datos_suficientes(): void
    {
        $this->assertSame(['valor' => null, 'motivo' => 'Los datos del puesto no alcanzan: confirma si es carrera, plazo determinado o designado.'],
            RegimenesLaborales::sugerencia($this->vinculo(null, ley: '728')));
    }

    public function test_codigo_respaldo_usa_el_alias_cas(): void
    {
        $this->assertSame('CAS', RegimenesLaborales::codigoRespaldo($this->ley('1057')));
        $this->assertSame('276', RegimenesLaborales::codigoRespaldo($this->ley('276')));
    }

    public function test_error_es_nulo_si_la_ley_vale_para_el_tipo_de_personal(): void
    {
        $this->assertNull(RegimenesLaborales::error($this->ley('276'), 'administrativo'));
        // Sin tipo de personal conocido no se compara aplica_a.
        $this->assertNull(RegimenesLaborales::error($this->ley('276'), null));
    }

    public function test_error_si_la_ley_esta_inactiva_o_es_de_otro_tipo_de_personal(): void
    {
        $this->assertSame('La ley Ley 276 está inactiva en el catálogo.', RegimenesLaborales::error($this->ley('276', ['activo' => false]), 'administrativo'));
        $this->assertSame('La ley Ley 276 corresponde al personal administrativo, no al docente.', RegimenesLaborales::error($this->ley('276'), 'docente'));
    }

    public function test_error_sin_ley_lista_los_codigos_aceptados(): void
    {
        $mensaje = RegimenesLaborales::error(null, 'docente');
        $this->assertStringStartsWith('Indica la ley (régimen normativo) de la persona: ', $mensaje);
        $this->assertStringContainsString('30220', $mensaje);
        $this->assertSame('Indica la ley (régimen normativo) de la persona.', RegimenesLaborales::error(null, null));
        $this->assertSame('Indica la ley (régimen normativo) de la persona.', RegimenesLaborales::error(null, 'otro'));
    }

    public function test_codigos_devuelve_los_activos_del_tipo_con_alias_y_ordenados(): void
    {
        $this->catalogo('728')->update(['activo' => false]);
        $codigos = RegimenesLaborales::codigos('administrativo');
        $this->assertContains('CAS', $codigos);
        $this->assertContains('276', $codigos);
        $this->assertNotContains('1057', $codigos);
        $this->assertNotContains('728', $codigos);
        $this->assertNotContains('30220', $codigos);
        $this->assertSame(array_keys($codigos), range(0, count($codigos) - 1));
        $this->assertSame([], RegimenesLaborales::codigos(null));
        $this->assertSame([], RegimenesLaborales::codigos(['administrativo']));
    }

    public function test_ley_por_id_por_codigo_y_por_alias(): void
    {
        $cas = $this->catalogo('1057');
        $this->assertTrue($cas->is(RegimenesLaborales::ley($cas->id)));
        $this->assertTrue($cas->is(RegimenesLaborales::ley(null, '1057')));
        $this->assertTrue($cas->is(RegimenesLaborales::ley(null, 'CAS')));
        $this->assertTrue($cas->is(RegimenesLaborales::ley(null, 'cas')));
    }

    public function test_ley_el_id_tiene_prioridad_sobre_el_codigo(): void
    {
        $this->assertTrue($this->catalogo('276')->is(RegimenesLaborales::ley($this->catalogo('276')->id, 'CAS')));
    }

    public function test_ley_inexistente_o_sin_datos_es_nula(): void
    {
        $this->assertNull(RegimenesLaborales::ley('01890000-0000-7000-8000-000000000000'));
        $this->assertNull(RegimenesLaborales::ley(null, '9999'));
        $this->assertNull(RegimenesLaborales::ley(null));
        $this->assertNull(RegimenesLaborales::ley('', ''));
    }
}
