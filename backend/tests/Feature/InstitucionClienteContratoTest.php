<?php

namespace Tests\Feature;

use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use App\Rules\Ruc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/** Datos del cliente y del contrato de una institución, desde el panel de plataforma. */
class InstitucionClienteContratoTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    /** RUC ficticio con dígito verificador correcto (no es de ninguna entidad). */
    private const RUC = '20100000009';

    private Institucion $uno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
        $this->uno = $this->institucion(['nombre' => 'Uno', 'slug' => 'uno', 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now()]);
    }

    private function editar(array $datos)
    {
        return $this->patchJson("/api/plataforma/instituciones/{$this->uno->id}", $datos);
    }

    public function test_the_ruc_is_checked_with_the_sunat_check_digit(): void
    {
        foreach (['20100000009', '10000000006', '20600000005'] as $valido) {
            $this->assertTrue(Ruc::valido($valido), $valido);
        }
        foreach (['20100000008', '30100000003', '2010000000', '201000000091', '2010000000A', ''] as $invalido) {
            $this->assertFalse(Ruc::valido($invalido), $invalido);
        }
    }

    public function test_client_and_contract_data_are_saved_audited_and_shown(): void
    {
        $this->editar(['razon_social' => '  Universidad de Prueba Uno  ', 'ruc' => self::RUC, 'contacto_nombre' => 'Jefatura de la ORH',
            'contacto_email' => ' ORH@Uno.Example.test ', 'contacto_telefono' => '+51 (084) 123-456', 'contrato_numero' => 'C-2026-001',
            'contrato_inicio' => '2026-03-01'])->assertOk()
            ->assertJsonPath('data.razon_social', 'Universidad de Prueba Uno')->assertJsonPath('data.contacto_email', 'orh@uno.example.test')
            ->assertJsonPath('data.contrato_inicio', '2026-03-01');

        // Ya aprovisionada: su ubicación no se cambia, pero sus datos de cliente sí.
        $this->getJson("/api/plataforma/instituciones/{$this->uno->id}")->assertOk()->assertJsonPath('data.ruc', self::RUC)
            ->assertJsonPath('data.contrato_numero', 'C-2026-001');
        $evento = AuditoriaPlataforma::query()->where('accion', 'institucion.editada')->sole();
        $this->assertNull($evento->datos_antes['ruc']);
        $this->assertSame(self::RUC, $evento->datos_despues['ruc']);
        $this->assertSame('2026-03-01', $evento->datos_despues['contrato_inicio']);

        // Vaciar un campo lo deja sin dato.
        $this->editar(['contacto_telefono' => '  '])->assertOk()->assertJsonPath('data.contacto_telefono', null);
    }

    public function test_invalid_client_data_is_rejected(): void
    {
        foreach ([
            ['ruc' => '20100000008'], ['ruc' => '123'], ['contacto_email' => 'no-es-correo'], ['contacto_telefono' => 'llamar mañana'],
            ['contrato_inicio' => '01/03/2026'], ['contrato_inicio' => '1990-01-01'], ['razon_social' => 'ab'], ['contrato_numero' => str_repeat('x', 61)],
            ['plan' => 'premium'],
        ] as $datos) {
            $this->editar($datos)->assertUnprocessable()->assertJsonValidationErrors(array_keys($datos));
        }
        $this->assertNull($this->uno->refresh()->ruc);
        $this->assertSame(0, AuditoriaPlataforma::query()->where('accion', 'institucion.editada')->count());
    }
}
