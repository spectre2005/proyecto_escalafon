<?php

namespace Tests\Feature;

use App\Models\Capacitacion;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Idioma;
use App\Models\Legajo;
use App\Models\ProduccionIntelectual;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\EmisionesPersonales;
use App\Support\ExigenciasSeccion01;
use Database\Seeders\Seccion03CatalogoSeeder;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/** Ficha (contacto de emergencia, régimen pensionario), familiares y sección 03 (literal h y capacitaciones). Datos ficticios. */
class FichaFamiliaresFormacionTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private Legajo $legajo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('legajo.disco_documentos'));
        $this->seed([SeccionesLegajoCatalogoSeeder::class, Seccion03CatalogoSeeder::class]);
        $this->persona = Usuario::factory()->create();
        $this->legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id]);
        $this->actuarComo();
    }

    public function test_general_record_keeps_emergency_contact_and_pension_scheme_as_sensitive_data(): void
    {
        $url = '/api/v1/expedientes/'.$this->persona->id;
        $this->patchJson($url, ['contacto_emergencia_telefono' => '12345', 'cuspp' => 'ABC', 'regimen_pensionario' => 'banco'])
            ->assertUnprocessable()->assertJsonValidationErrors(['contacto_emergencia_telefono', 'cuspp', 'regimen_pensionario']);
        $this->patchJson($url, ['contacto_emergencia_nombre' => 'CONTACTO FICTICIO', 'contacto_emergencia_parentesco' => 'Hermana', 'contacto_emergencia_telefono' => '987654321',
            'regimen_pensionario' => 'afp', 'afp_nombre' => 'AFP FICTICIA', 'cuspp' => '123456ABCDE7', 'fecha_afiliacion_pension' => '2015-03-01'])
            ->assertOk()->assertJsonPath('data.usuario.values.regimen_pensionario', 'afp')->assertJsonPath('data.usuario.values.cuspp', '123456ABCDE7');

        config(['permisos.roles.SIN_SENSIBLES' => ['legajo.ver', 'personal.ver']]);
        $this->actuarComo(null, 'SIN_SENSIBLES');
        $this->getJson($url)->assertOk()->assertJsonMissingPath('data.usuario.values.cuspp')
            ->assertJsonMissingPath('data.usuario.values.contacto_emergencia_telefono')->assertJsonMissingPath('data.usuario.values.regimen_pensionario');
    }

    public function test_deceased_relative_is_kept_and_no_longer_requires_an_id_document(): void
    {
        $url = '/api/v1/expedientes/'.$this->persona->id.'/familiares';
        $id = $this->postJson($url, ['apellidos_nombres' => 'HIJO FICTICIO', 'parentesco' => 'hijo', 'fecha_nacimiento' => '2015-01-01', 'es_derechohabiente' => false])
            ->assertCreated()->json('data.id');
        $familiar = Familiar::query()->findOrFail($id);
        $this->assertTrue(ExigenciasSeccion01::documentoDeFamiliar($familiar, today())['exigido']);
        $this->patchJson($url.'/'.$id, ['fecha_fallecimiento' => today()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('fecha_fallecimiento');
        $this->patchJson($url.'/'.$id, ['fecha_fallecimiento' => '2024-05-10'])->assertOk()->assertJsonPath('data.anulado', false);
        $exigencia = ExigenciasSeccion01::documentoDeFamiliar($familiar->refresh(), today());
        $this->assertFalse($exigencia['exigido']);
        $this->assertStringContainsString('fallecido el 10/05/2024', $exigencia['motivo']);
    }

    public function test_training_keeps_credits_and_delivery_mode(): void
    {
        $this->cargar('FORM_CAPACITACION', ['tipo_capacitacion' => 'curso', 'horas' => 120, 'creditos' => 4.5, 'modalidad_dictado' => 'distancia'])
            ->assertUnprocessable()->assertJsonValidationErrors('modalidad_dictado');
        $this->cargar('FORM_CAPACITACION', ['tipo_capacitacion' => 'curso', 'horas' => 120, 'creditos' => 4.5, 'modalidad_dictado' => 'virtual'])->assertCreated();
        $capacitacion = Capacitacion::query()->firstOrFail();
        $this->assertSame(['4.5', 'virtual'], [$capacitacion->creditos, $capacitacion->modalidad_dictado]);
        $informe = EmisionesPersonales::emitir(auth()->user(), $this->persona, $this->legajo, 'informe-escalafonario');
        $this->assertSame(['4.5', 'Virtual'], [$informe['capacitaciones'][0]['creditos'], $informe['capacitaciones'][0]['dictado']]);
    }

    public function test_other_training_documents_can_prove_a_language_or_an_intellectual_work(): void
    {
        $this->cargar('FORM_OTROS', ['clase_registro' => 'idioma', 'idioma' => 'Inglés'])->assertUnprocessable()->assertJsonValidationErrors(['tipo_idioma', 'nivel_idioma']);
        $this->cargar('FORM_OTROS', ['clase_registro' => 'idioma', 'idioma' => 'Inglés', 'tipo_idioma' => 'extranjero', 'nivel_idioma' => 'avanzado'])->assertCreated();
        $this->cargar('FORM_OTROS', ['nombre' => 'Libro ficticio de prueba', 'clase_registro' => 'produccion', 'tipo_produccion' => 'libro', 'identificador' => '978-0-00-000000-0'])->assertCreated();
        // Sin clase: sigue como antes (un registro de capacitación «otro»).
        $this->cargar('FORM_OTROS')->assertCreated();
        // Los campos de idioma no se aceptan en otra clase.
        $this->cargar('FORM_OTROS', ['clase_registro' => 'otro', 'idioma' => 'Inglés'])->assertUnprocessable();

        $this->assertDatabaseHas('idiomas', ['usuario_id' => $this->persona->id, 'idioma' => 'Inglés', 'nivel' => 'avanzado', 'institucion' => 'Universidad de ejemplo']);
        $this->assertDatabaseHas('producciones_intelectuales', ['usuario_id' => $this->persona->id, 'tipo' => 'libro', 'titulo' => 'Libro ficticio de prueba', 'identificador' => '978-0-00-000000-0']);
        $this->assertSame(1, Capacitacion::query()->count());
        $items = collect($this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-03')->assertOk()->json('data.items'))->firstWhere('codigo', 'FORM_OTROS');
        $registros = collect($items['documentos'])->pluck('registros');
        $this->assertSame('Inglés', $registros->pluck('idiomas')->flatten(1)->first()['idioma']);
        $this->assertSame('libro', $registros->pluck('producciones')->flatten(1)->first()['tipo']);

        $informe = EmisionesPersonales::emitir(auth()->user(), $this->persona, $this->legajo, 'informe-escalafonario');
        $this->assertSame(['Inglés', 'Avanzado', 'Documento aceptado'], [$informe['idiomas'][0]['idioma'], $informe['idiomas'][0]['nivel'], $informe['idiomas'][0]['sustento']]);
        $this->assertSame('Libro ficticio de prueba', $informe['produccion'][0]['titulo']);
        $this->assertInstanceOf(Idioma::class, Idioma::query()->first());
        $this->assertInstanceOf(ProduccionIntelectual::class, ProduccionIntelectual::query()->first());
    }

    private function cargar(string $codigo, array $cambios = [])
    {
        $tipo = TipoDocumento::query()->where('codigo', $codigo)->firstOrFail();
        $folio = (int) DocumentoUsuario::query()->where('legajo_id', $this->legajo->id)->where('seccion_legajo_id', 3)->max('folio_fin') + 1;

        return $this->post('/api/v1/expedientes/'.$this->persona->id.'/seccion-03/documentos', [
            'tipo_documento_id' => $tipo->id, 'nombre' => 'Documento de formación de prueba', 'emisor' => 'Universidad de ejemplo',
            'fecha_emision' => today()->subDay()->toDateString(), 'folio_inicio' => $folio, 'folio_fin' => $folio,
            'modalidad_presentacion' => 'copia_simple', 'pdf' => $this->pdf(), ...$cambios,
        ], ['Accept' => 'application/json']);
    }
}
