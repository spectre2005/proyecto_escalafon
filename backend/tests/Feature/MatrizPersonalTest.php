<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\PerfilDocente;
use App\Models\RegimenNormativo;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Matriz de pruebas del módulo Personal (registro manual con vínculo): un caso positivo, dos negativos y uno límite.
 * Cada test corresponde a una fila de la matriz (CP-01 a CP-04).
 */
class MatrizPersonalTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/usuarios/con-vinculo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $this->actuarComo();
    }

    /** Datos válidos de un docente con su primer vínculo; $persona reemplaza campos de la persona. */
    private function datos(array $persona = []): array
    {
        return [
            'usuarios' => [[
                'numero_doc_identidad' => '71000001',
                'nombres' => 'Rosa',
                'apellido_paterno' => 'Prueba',
                'apellido_materno' => 'Matriz',
                'email_institucional' => 'rosa.prueba@example.test',
                'tipo_personal' => 'docente',
                'regimen_normativo_id' => RegimenNormativo::query()->where('codigo', '30220')->value('id'),
                ...$persona,
            ]],
            'vinculo' => [
                'regimen_laboral' => 'carrera',
                'nombre_puesto' => 'Docente del departamento',
                'unidad_organica_id' => UnidadOrganica::factory()->create()->id,
                'fecha_inicio' => '2026-03-01',
                'numero_documento_vinculo' => 'RES-123-2026',
                'tiene_periodo_prueba' => true,
            ],
            'datos_puesto' => ['condicion_docente' => 'ordinario', 'categoria' => 'asociado', 'dedicacion' => 'tiempo_completo'],
        ];
    }

    /** No quedó nada creado del intento: ni persona, ni legajo, ni vínculo, ni datos del puesto. */
    private function assertNadaCreado(string $dni): void
    {
        $this->assertSame(0, Usuario::query()->where('numero_doc_identidad', $dni)->count());
        $this->assertSame([0, 0, 0], [Legajo::query()->count(), VinculoLaboral::query()->count(), PerfilDocente::query()->count()]);
    }

    /** CP-01 (positivo): datos personales, vínculo y puesto válidos → personal registrado con éxito. */
    public function test_cp01_registra_personal_con_datos_validos(): void
    {
        $this->postJson(self::URL, $this->datos())
            ->assertCreated()
            ->assertJsonPath('data.usuario.numero_doc_identidad', '71000001')
            ->assertJsonPath('data.legajo.estado_legajo', 'activo')
            ->assertJsonPath('data.vinculo.regimen_laboral', 'carrera');

        $persona = Usuario::query()->where('numero_doc_identidad', '71000001')->sole();
        $this->assertSame(1, Legajo::query()->where('usuario_id', $persona->id)->count());
        $this->assertSame(1, VinculoLaboral::query()->where('usuario_id', $persona->id)->count());
    }

    /** CP-02 (negativo): campo obligatorio vacío (DNI) → rechazado con mensaje de campo obligatorio. */
    public function test_cp02_rechaza_si_falta_un_campo_obligatorio(): void
    {
        $respuesta = $this->postJson(self::URL, $this->datos(['numero_doc_identidad' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('usuarios.0.numero_doc_identidad');

        $this->assertStringEndsWith('es obligatorio.', $respuesta->json('errors')['usuarios.0.numero_doc_identidad'][0]);
        $this->assertNadaCreado('');
    }

    /** CP-03 (negativo): DNI de una persona ya registrada → rechazado, sin duplicar a la persona. */
    public function test_cp03_rechaza_un_dni_ya_registrado(): void
    {
        Usuario::factory()->create(['numero_doc_identidad' => '71000001']);

        $this->postJson(self::URL, $this->datos())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['usuarios.0.numero_doc_identidad' => 'Esta persona ya está registrada con ese DNI.']);

        $this->assertSame(1, Usuario::query()->where('numero_doc_identidad', '71000001')->count());
        $this->assertSame([0, 0], [Legajo::query()->count(), VinculoLaboral::query()->count()]);
    }

    /** CP-04 (límite): el DNI tiene exactamente 8 dígitos → 7 y 9 se rechazan, 8 se acepta. */
    public function test_cp04_dni_en_el_limite_de_8_digitos(): void
    {
        foreach (['1234567', '123456789'] as $dni) {
            $this->postJson(self::URL, $this->datos(['numero_doc_identidad' => $dni]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('usuarios.0.numero_doc_identidad');
            $this->assertNadaCreado($dni);
        }

        $this->postJson(self::URL, $this->datos(['numero_doc_identidad' => '12345678']))
            ->assertCreated()
            ->assertJsonPath('data.usuario.numero_doc_identidad', '12345678');
    }
}
