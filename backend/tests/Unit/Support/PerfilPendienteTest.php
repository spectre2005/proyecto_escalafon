<?php

namespace Tests\Unit\Support;

use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\AplicacionDocumental;
use App\Support\PerfilPendiente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Condiciones del perfil sin responder (null), de la persona y de cada vínculo vigente.
 */
class PerfilPendienteTest extends TestCase
{
    use RefreshDatabase;

    private const PUESTO_SIN_RESPONDER = ['obligado_djbr' => null, 'exige_colegiatura' => null, 'exige_estudios_basicos' => null, 'tiene_periodo_prueba' => null];

    private const PUESTO_RESPONDIDO = ['obligado_djbr' => false, 'exige_colegiatura' => true, 'exige_estudios_basicos' => false, 'tiene_periodo_prueba' => false];

    private function persona(array $datos = []): Usuario
    {
        return Usuario::factory()->create(['tiene_derechohabientes' => null, 'con_discapacidad' => null, ...self::PUESTO_SIN_RESPONDER, ...$datos]);
    }

    private function vinculo(Usuario $persona, array $datos = []): VinculoLaboral
    {
        return VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_cese' => null, 'anulado' => false,
            ...self::PUESTO_SIN_RESPONDER, ...$datos]);
    }

    private function pendientes(Usuario $persona, bool $veSensibles = true): array
    {
        return PerfilPendiente::de($persona, AplicacionDocumental::para($persona), $veSensibles);
    }

    public function test_sin_vinculo_vigente_solo_se_preguntan_las_condiciones_de_la_persona(): void
    {
        $persona = $this->persona();

        $this->assertSame([
            ['bandera' => 'tiene_derechohabientes', 'ambito' => 'persona'],
            ['bandera' => 'con_discapacidad', 'ambito' => 'persona'],
        ], $this->pendientes($persona));
    }

    public function test_sin_permiso_de_datos_sensibles_no_se_informan_las_condiciones_de_la_persona(): void
    {
        $persona = $this->persona();
        $this->vinculo($persona, self::PUESTO_RESPONDIDO);

        $this->assertSame([], $this->pendientes($persona, veSensibles: false));
    }

    public function test_no_es_una_respuesta_y_no_queda_pendiente(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => false, 'con_discapacidad' => true]);
        $this->vinculo($persona, self::PUESTO_RESPONDIDO);

        $this->assertSame([], $this->pendientes($persona));
    }

    public function test_cada_condicion_del_puesto_sin_responder_se_pide_en_su_vinculo(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => true, 'con_discapacidad' => false]);
        $vinculo = $this->vinculo($persona, ['nombre_puesto' => 'Analista', ...self::PUESTO_RESPONDIDO, 'exige_estudios_basicos' => null]);

        $this->assertSame([['bandera' => 'exige_estudios_basicos', 'ambito' => 'vinculo', 'vinculo_id' => $vinculo->id, 'puesto' => 'Analista']],
            $this->pendientes($persona));
    }

    public function test_con_varios_vinculos_vigentes_se_pregunta_en_cada_uno_que_tenga_null(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => true, 'con_discapacidad' => false]);
        $antiguo = $this->vinculo($persona, [...self::PUESTO_RESPONDIDO, 'obligado_djbr' => null, 'nombre_puesto' => 'Antiguo', 'fecha_inicio' => '2024-01-01']);
        $reciente = $this->vinculo($persona, [...self::PUESTO_RESPONDIDO, 'obligado_djbr' => null, 'nombre_puesto' => 'Reciente', 'fecha_inicio' => '2026-01-01']);

        $this->assertSame([[$reciente->id, 'Reciente'], [$antiguo->id, 'Antiguo']],
            array_map(fn (array $item): array => [$item['vinculo_id'], $item['puesto']], $this->pendientes($persona)));
    }

    public function test_si_un_vinculo_vigente_dice_si_la_condicion_ya_esta_respondida(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => true, 'con_discapacidad' => false]);
        $this->vinculo($persona, [...self::PUESTO_RESPONDIDO, 'obligado_djbr' => true]);
        $this->vinculo($persona, [...self::PUESTO_RESPONDIDO, 'obligado_djbr' => null]);

        $this->assertSame([], $this->pendientes($persona));
    }

    public function test_vinculos_cesados_y_anulados_no_se_preguntan(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => true, 'con_discapacidad' => false]);
        $vigente = $this->vinculo($persona, [...self::PUESTO_RESPONDIDO, 'tiene_periodo_prueba' => null]);
        $this->vinculo($persona, ['fecha_cese' => '2025-01-01']);
        $this->vinculo($persona, ['anulado' => true]);

        $this->assertSame([['tiene_periodo_prueba', $vigente->id]],
            array_map(fn (array $item): array => [$item['bandera'], $item['vinculo_id']], $this->pendientes($persona)));
    }

    public function test_con_vinculo_vigente_la_copia_de_la_persona_no_se_pregunta(): void
    {
        // La persona tiene las condiciones del puesto en null, pero se guardan en el vínculo: ahí se preguntan.
        $persona = $this->persona(['tiene_derechohabientes' => true, 'con_discapacidad' => false]);
        $this->vinculo($persona, self::PUESTO_RESPONDIDO);

        $this->assertSame([], $this->pendientes($persona));
    }

    public function test_todo_sin_responder_lista_las_seis_condiciones(): void
    {
        $persona = $this->persona();
        $this->vinculo($persona);

        $this->assertSame(['tiene_derechohabientes', 'con_discapacidad', ...array_keys(self::PUESTO_SIN_RESPONDER)],
            array_column($this->pendientes($persona), 'bandera'));
    }
}
