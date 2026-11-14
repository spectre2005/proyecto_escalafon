<?php

namespace Tests\Unit\Support;

use App\Models\Legajo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\CondicionesRegistro;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Llamadas directas a CondicionesRegistro (tests/Feature/CondicionesRegistroTest las prueba por HTTP). */
class CondicionesRegistroTest extends TestCase
{
    use RefreshDatabase;

    private function persona(array $banderas = []): Usuario
    {
        $persona = Usuario::factory()->create(['tiene_derechohabientes' => null, 'con_discapacidad' => null, 'obligado_djbr' => null,
            'exige_colegiatura' => null, 'exige_estudios_basicos' => null, ...$banderas]);
        Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo']);

        return $persona;
    }

    private function vinculo(Usuario $persona, array $datos): VinculoLaboral
    {
        return VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'anulado' => false, 'fecha_cese' => null, 'fecha_inicio' => '2026-01-01', ...$datos]);
    }

    /** Mensaje de error del campo, o null si la llamada no lanza. */
    private function error(callable $llamada, string $campo): ?string
    {
        try {
            $llamada();
        } catch (ValidationException $e) {
            $this->assertSame([$campo], array_keys($e->errors()));

            return $e->errors()[$campo][0];
        }

        return null;
    }

    public function test_familiar_derechohabiente_con_la_bandera_en_no_se_rechaza(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => false]);
        $this->assertSame('No se puede marcar un familiar como derechohabiente: «Tiene derechohabientes» está en No en la ficha de datos personales. '
            .'Si corresponde, cámbialo a Sí y vuelve a intentarlo. Lo ya registrado se conserva.',
            $this->error(fn () => CondicionesRegistro::familiar($persona->id, true), 'es_derechohabiente'));
    }

    public function test_familiar_no_derechohabiente_siempre_se_permite(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => false]);
        $this->assertNull($this->error(fn () => CondicionesRegistro::familiar($persona->id, false), 'es_derechohabiente'));
        // Ni siquiera busca a la persona.
        $this->assertNull($this->error(fn () => CondicionesRegistro::familiar('01890000-0000-7000-8000-000000000000', false), 'es_derechohabiente'));
    }

    public function test_sin_responder_o_en_si_se_permite(): void
    {
        $this->assertNull($this->error(fn () => CondicionesRegistro::familiar($this->persona()->id, true), 'es_derechohabiente'));
        $this->assertNull($this->error(fn () => CondicionesRegistro::familiar($this->persona(['tiene_derechohabientes' => true])->id, true), 'es_derechohabiente'));
        $this->assertNull($this->error(fn () => CondicionesRegistro::certificado($this->persona()->id), 'persona'));
    }

    public function test_el_campo_del_error_se_puede_elegir(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => false]);
        $this->assertNotNull($this->error(fn () => CondicionesRegistro::familiar($persona->id, true, 'familiares.0.es_derechohabiente'), 'familiares.0.es_derechohabiente'));
    }

    public function test_certificado_de_discapacidad_sigue_la_bandera_de_la_persona(): void
    {
        $persona = $this->persona(['con_discapacidad' => false]);
        $mensaje = $this->error(fn () => CondicionesRegistro::certificado($persona->id), 'persona');
        $this->assertStringStartsWith('No se puede registrar un certificado de discapacidad: «Con discapacidad (el servidor o alguno de sus hijos)» está en No en la ficha de datos personales.', $mensaje);
    }

    public function test_declaracion_solo_exige_la_bandera_para_la_djbr(): void
    {
        $persona = $this->persona();
        $this->vinculo($persona, ['obligado_djbr' => false]);
        $this->assertNull($this->error(fn () => CondicionesRegistro::declaracion($persona->id, 'otra'), 'tipo'));
        $this->assertNull($this->error(fn () => CondicionesRegistro::declaracion($persona->id, null), 'tipo'));
        $this->assertNull($this->error(fn () => CondicionesRegistro::declaracion($persona->id, 'DJBR'), 'tipo'));
        $mensaje = $this->error(fn () => CondicionesRegistro::declaracion($persona->id, 'djbr'), 'tipo');
        $this->assertStringContainsString('«Obligado a presentar declaración jurada de bienes y rentas» está en No en el vínculo laboral (sección 02).', $mensaje);
    }

    public function test_para_ofrecer_basta_un_vinculo_no_anulado_que_diga_si_aunque_haya_cesado(): void
    {
        $persona = $this->persona();
        $this->vinculo($persona, ['exige_colegiatura' => false]);
        $this->vinculo($persona, ['exige_colegiatura' => true, 'fecha_cese' => '2026-03-31']);
        $this->assertNull($this->error(fn () => CondicionesRegistro::colegiatura($persona->id), 'numero_colegiatura'));
    }

    public function test_un_vinculo_anulado_que_dice_si_no_cuenta(): void
    {
        $persona = $this->persona();
        $this->vinculo($persona, ['exige_colegiatura' => false]);
        $this->vinculo($persona, ['exige_colegiatura' => true, 'anulado' => true]);
        $this->assertNotNull($this->error(fn () => CondicionesRegistro::colegiatura($persona->id), 'numero_colegiatura'));
    }

    public function test_varios_vinculos_vigentes_uno_sin_responder_no_bloquea(): void
    {
        $persona = $this->persona();
        $this->vinculo($persona, ['exige_colegiatura' => false]);
        $this->vinculo($persona, ['exige_colegiatura' => null]);
        $this->assertNull($this->error(fn () => CondicionesRegistro::colegiatura($persona->id), 'numero_colegiatura'));
    }

    public function test_sin_vinculos_la_bandera_del_puesto_sale_de_la_persona(): void
    {
        $persona = $this->persona(['exige_colegiatura' => false]);
        $this->assertStringStartsWith('No se puede registrar una colegiatura: «El puesto exige colegiatura» está en No en el vínculo laboral (sección 02).',
            $this->error(fn () => CondicionesRegistro::colegiatura($persona->id), 'numero_colegiatura'));
    }

    public function test_formacion_solo_exige_la_bandera_para_educacion_basica(): void
    {
        $persona = $this->persona();
        $this->vinculo($persona, ['exige_estudios_basicos' => false]);
        $this->assertNull($this->error(fn () => CondicionesRegistro::formacion($persona->id, 'bachiller'), 'nivel'));
        $this->assertNull($this->error(fn () => CondicionesRegistro::formacion($persona->id, null), 'nivel'));
        $this->assertStringStartsWith('No se puede registrar estudios básicos: «El puesto exige estudios básicos» está en No',
            $this->error(fn () => CondicionesRegistro::formacion($persona->id, 'educacion_basica'), 'nivel'));
    }

    public function test_persona_inexistente_lanza_model_not_found_al_exigir(): void
    {
        $this->expectException(ModelNotFoundException::class);
        CondicionesRegistro::colegiatura('01890000-0000-7000-8000-000000000000');
    }
}
