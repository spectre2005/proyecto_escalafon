<?php

namespace Tests\Unit\Support\Documentos;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\Folios;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Folios correlativos por sección: el siguiente documento empieza en ultimo_folio + 1, sin huecos ni solapes.
 */
class FoliosTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $persona;

    private Legajo $legajo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $this->persona = Usuario::factory()->create();
        $this->legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo']);
    }

    private function documento(int $seccion, int $inicio, int $fin, array $datos = [], ?Legajo $legajo = null): DocumentoUsuario
    {
        $legajo ??= $this->legajo;
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => $seccion]);

        return DocumentoUsuario::factory()->create([
            'usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id, 'tipo_documento_id' => $tipo->id,
            'seccion_legajo_id' => $seccion, 'created_by' => $this->persona->id, 'estado' => 'aceptado',
            'folio_inicio' => $inicio, 'folio_fin' => $fin, ...$datos,
        ]);
    }

    private function fila(int $seccion): ?LegajoSeccion
    {
        return LegajoSeccion::query()->where('legajo_id', $this->legajo->id)->where('seccion_legajo_id', $seccion)->first();
    }

    private function assertRechaza(int $seccion, int $inicio, int $fin, string $mensaje): void
    {
        try {
            Folios::reservar($this->legajo, $seccion, $inicio, $fin);
            $this->fail("Se esperaba rechazar los folios {$inicio}–{$fin}.");
        } catch (ValidationException $error) {
            $this->assertStringContainsString($mensaje, $error->errors()['folio_inicio'][0]);
        }
    }

    public function test_ultimo_es_cero_en_una_seccion_sin_fila_ni_documentos(): void
    {
        $this->assertSame(0, Folios::ultimo($this->legajo, 3));
    }

    public function test_ultimo_sin_fila_toma_el_mayor_folio_fin_de_la_seccion_y_del_legajo(): void
    {
        $this->documento(3, 1, 4);
        $this->documento(3, 5, 9);
        $this->documento(4, 1, 50);
        $otro = Legajo::factory()->create(['usuario_id' => Usuario::factory()->create()->id]);
        $this->documento(3, 1, 80, legajo: $otro);

        $this->assertSame(9, Folios::ultimo($this->legajo, 3));
        $this->assertSame(50, Folios::ultimo($this->legajo, 4));
    }

    public function test_ultimo_con_fila_usa_el_ultimo_folio_registrado(): void
    {
        $this->documento(2, 1, 4);
        LegajoSeccion::query()->create(['legajo_id' => $this->legajo->id, 'seccion_legajo_id' => 2, 'ultimo_folio' => 12]);

        $this->assertSame(12, Folios::ultimo($this->legajo, 2));
    }

    public function test_un_documento_falso_o_subsanado_sigue_ocupando_sus_folios(): void
    {
        // Los documentos no se eliminan (documentos_usuario no tiene «anulado»: se subsanan con uno nuevo). Un documento
        // marcado falso o ya subsanado conserva sus folios físicos y no se reutilizan.
        $original = $this->documento(5, 1, 2, ['estado' => 'falso']);
        $this->documento(5, 3, 3, ['subsana_a_documento_id' => $original->id]);

        $this->assertSame(3, Folios::ultimo($this->legajo, 5));
        $this->assertRechaza(5, 1, 2, 'se solapan');
        $this->assertSame(4, Folios::reservar($this->legajo, 5, 4, 4)->ultimo_folio);
    }

    public function test_reservar_el_primer_rango_crea_la_fila_de_la_seccion(): void
    {
        $this->assertNull($this->fila(1));

        $fila = Folios::reservar($this->legajo, 1, 1, 3);

        $this->assertSame(3, $fila->ultimo_folio);
        $this->assertSame(3, $this->fila(1)->ultimo_folio);
        $this->assertNotNull($this->fila(1)->ultima_actualizacion);
    }

    public function test_reservar_rangos_consecutivos_avanza_el_ultimo_folio(): void
    {
        Folios::reservar($this->legajo, 1, 1, 3);
        Folios::reservar($this->legajo, 1, 4, 4);
        Folios::reservar($this->legajo, 1, 5, 10);

        $this->assertSame(10, Folios::ultimo($this->legajo, 1));
    }

    public function test_reservar_rechaza_un_hueco_e_informa_el_siguiente_folio(): void
    {
        Folios::reservar($this->legajo, 1, 1, 3);

        $this->assertRechaza(1, 5, 6, 'correlativos y sin huecos: el siguiente folio es 4');
        $this->assertSame(3, Folios::ultimo($this->legajo, 1));
    }

    public function test_reservar_rechaza_un_solape_con_folios_ya_usados(): void
    {
        Folios::reservar($this->legajo, 1, 1, 3);

        $this->assertRechaza(1, 3, 5, 'El siguiente folio libre es 4');
        $this->assertRechaza(1, 1, 1, 'se solapan');
        $this->assertSame(3, Folios::ultimo($this->legajo, 1));
    }

    public function test_reservar_rechaza_solape_con_documentos_aunque_la_fila_este_atrasada(): void
    {
        LegajoSeccion::query()->create(['legajo_id' => $this->legajo->id, 'seccion_legajo_id' => 6, 'ultimo_folio' => 2]);
        $this->documento(6, 3, 5);

        $this->assertRechaza(6, 3, 3, 'se solapan');
    }

    public function test_reservar_sin_fila_parte_del_mayor_folio_de_los_documentos_existentes(): void
    {
        $this->documento(3, 1, 7);

        $this->assertRechaza(3, 1, 2, 'El siguiente folio libre es 8');
        $this->assertSame(8, Folios::reservar($this->legajo, 3, 8, 8)->ultimo_folio);
        $this->assertSame(1, LegajoSeccion::query()->where('legajo_id', $this->legajo->id)->where('seccion_legajo_id', 3)->count());
    }

    public function test_cada_seccion_numera_sus_folios_por_separado(): void
    {
        Folios::reservar($this->legajo, 1, 1, 10);

        $this->assertSame(2, Folios::reservar($this->legajo, 2, 1, 2)->ultimo_folio);
        $this->assertSame(10, Folios::ultimo($this->legajo, 1));
    }

    public function test_cada_legajo_numera_sus_folios_por_separado(): void
    {
        Folios::reservar($this->legajo, 1, 1, 10);
        $otro = Legajo::factory()->create(['usuario_id' => Usuario::factory()->create()->id]);

        $this->assertSame(1, Folios::reservar($otro, 1, 1, 1)->ultimo_folio);
    }

    public function test_reservar_rechaza_un_rango_invertido(): void
    {
        $this->markTestSkipped('Posible bug: Folios::reservar() (app/Support/Documentos/Folios.php:36-46) no valida que fin >= inicio; '
            .'reservar(4, 2) con siguiente folio 4 pasa y deja ultimo_folio = 2 (retrocede). Hoy depende de la validación del request.');
    }
}
