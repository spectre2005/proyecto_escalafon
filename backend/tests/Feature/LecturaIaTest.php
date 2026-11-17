<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\LecturaIa;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\EscanerSecciones;
use Database\Seeders\Seccion06CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/** Aviso de documento de otra persona y medición del acierto de la lectura con IA. */
class LecturaIaTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private TipoDocumento $tipo;

    protected function setUp(): void
    {
        parent::setUp();
        config(['escaner.activo' => true, 'escaner.modelo' => 'modelo-prueba']);
        $this->seed(Seccion06CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create(['numero_doc_identidad' => '45678912', 'nombres' => 'MARIA', 'apellido_paterno' => 'QUISPE', 'apellido_materno' => 'HUAMAN']);
        Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo']);
        $this->tipo = TipoDocumento::query()->where('codigo', 'COMP_RECONOCIMIENTO_TIEMPO')->firstOrFail();
    }

    private function escanear(string $texto, array $propuestos = ['emisor' => 'UNIVERSIDAD DE EJEMPLO']): TestResponse
    {
        $this->mock(EscanerSecciones::class, function ($mock) use ($texto, $propuestos): void {
            $mock->shouldReceive('leer')->once()->andReturn($texto);
            $mock->shouldReceive('interpretar')->once()->andReturnUsing(function () use ($mock, $propuestos): array {
                $mock->sugerencias = ['fecha_emision' => ['valor' => '2026-01-02', 'evidencia' => '2 de enero']];

                return array_map(fn (string $valor): array => ['valor' => $valor, 'evidencia' => $valor], $propuestos);
            });
        });

        return $this->post('/api/v1/documentos/escanear', [
            'usuario_id' => $this->persona->id, 'tipo_documento_id' => $this->tipo->id,
            'campos' => ['emisor', 'fecha_emision', 'num_resolucion'], 'pdf' => $this->pdf(),
        ], ['Accept' => 'application/json']);
    }

    public function test_avisa_cuando_el_documento_parece_de_otra_persona(): void
    {
        $this->actuarComo();

        $this->escanear("UNIVERSIDAD DE EJEMPLO\nOtorgado a JUAN PEREZ ROJAS, identificado con DNI 40123456.")->assertOk()
            ->assertJsonPath('data.titular.estado', 'distinto')
            ->assertJsonPath('data.titular.dni_leidos', ['40123456']);

        // La auditoría guarda el estado, no el DNI leído.
        $evento = AuditoriaEvento::query()->where('accion', 'documento.lectura_ia')->firstOrFail();
        $this->assertStringContainsString('"titular":"distinto"', (string) $evento->datos_despues);
        $this->assertStringNotContainsString('40123456', (string) $evento->datos_despues);
    }

    public function test_confirma_cuando_el_documento_es_de_la_persona(): void
    {
        $this->actuarComo();

        $this->escanear('UNIVERSIDAD DE EJEMPLO. Titular: MARIA QUISPE HUAMAN, DNI 45678912.')->assertOk()
            ->assertJsonPath('data.titular.estado', 'coincide');
    }

    public function test_cada_lectura_queda_registrada_sin_valores_del_documento(): void
    {
        $this->actuarComo();

        $id = $this->escanear('UNIVERSIDAD DE EJEMPLO. DNI 45678912')->assertOk()->json('data.lectura_id');

        $lectura = LecturaIa::query()->findOrFail($id);
        $this->assertSame(['emisor', 'fecha_emision', 'num_resolucion'], $lectura->campos_pedidos);
        $this->assertSame(['emisor'], $lectura->campos_propuestos);
        $this->assertSame(['fecha_emision'], $lectura->campos_sugeridos);
        $this->assertSame('coincide', $lectura->titular);
        $this->assertSame('modelo-prueba', $lectura->modelo);
        $this->assertNull($lectura->resultado);
        $this->assertStringNotContainsString('UNIVERSIDAD DE EJEMPLO', json_encode($lectura->getAttributes()));
    }

    public function test_registra_el_resultado_por_campo_y_se_puede_volver_a_enviar(): void
    {
        $this->actuarComo();
        $id = $this->escanear('DNI 45678912')->json('data.lectura_id');
        $url = "/api/v1/documentos/escaner/lecturas/{$id}/resultado";

        $this->postJson($url, ['resultado' => ['emisor' => 'corregido']])->assertOk();
        $this->postJson($url, ['resultado' => ['emisor' => 'aceptado', 'fecha_emision' => 'sugerencia_usada', 'num_resolucion' => 'no_detectado']])->assertOk();

        $lectura = LecturaIa::query()->findOrFail($id);
        $this->assertSame(['emisor' => 'aceptado', 'fecha_emision' => 'sugerencia_usada', 'num_resolucion' => 'no_detectado'], $lectura->resultado);
        $this->assertNotNull($lectura->evaluado_en);
    }

    public function test_rechaza_campos_no_pedidos_estados_desconocidos_y_lecturas_ajenas(): void
    {
        $this->actuarComo();
        $id = $this->escanear('DNI 45678912')->json('data.lectura_id');
        $url = "/api/v1/documentos/escaner/lecturas/{$id}/resultado";

        $this->postJson($url, ['resultado' => ['horas' => 'aceptado']])->assertUnprocessable()->assertJsonValidationErrors('resultado');
        $this->postJson($url, ['resultado' => ['emisor' => 'adivinado']])->assertUnprocessable();

        $this->actuarComo();
        $this->postJson($url, ['resultado' => ['emisor' => 'aceptado']])->assertForbidden();
        $this->assertNull(LecturaIa::query()->findOrFail($id)->resultado);
    }

    public function test_resume_el_acierto_por_campo(): void
    {
        $admin = $this->actuarComo();
        $base = ['usuario_id' => $this->persona->id, 'tipo_documento_id' => $this->tipo->id, 'registrado_por' => $admin->id, 'modelo' => 'modelo-prueba',
            'segundos' => 10, 'campos_pedidos' => ['emisor', 'fecha_emision'], 'campos_propuestos' => ['emisor'], 'campos_sugeridos' => []];
        LecturaIa::query()->create([...$base, 'titular' => 'coincide', 'resultado' => ['emisor' => 'aceptado', 'fecha_emision' => 'aceptado']]);
        LecturaIa::query()->create([...$base, 'titular' => 'coincide', 'resultado' => ['emisor' => 'aceptado', 'fecha_emision' => 'corregido']]);
        LecturaIa::query()->create([...$base, 'titular' => 'distinto', 'segundos' => 20, 'resultado' => ['emisor' => 'borrado', 'fecha_emision' => 'no_detectado']]);
        LecturaIa::query()->create([...$base, 'titular' => 'coincide']); // sin evaluar
        $vieja = LecturaIa::query()->create([...$base, 'resultado' => ['emisor' => 'borrado']]);
        $vieja->forceFill(['created_at' => now()->subDays(200)])->save();

        $datos = $this->getJson('/api/v1/documentos/escaner/precision')->assertOk()->json('data');

        $this->assertSame(4, $datos['lecturas']);
        $this->assertSame(3, $datos['evaluadas']);
        $this->assertSame(['coincide' => 3, 'distinto' => 1], $datos['titular']);
        $emisor = collect($datos['por_campo'])->firstWhere('campo', 'emisor');
        $this->assertSame([2, 0, 1, 3, 67], [$emisor['aceptado'], $emisor['corregido'], $emisor['borrado'], $emisor['propuestos'], $emisor['acierto']]);
        $fecha = collect($datos['por_campo'])->firstWhere('campo', 'fecha_emision');
        $this->assertSame([1, 1, 1, 50], [$fecha['aceptado'], $fecha['corregido'], $fecha['no_detectado'], $fecha['acierto']]);
        $this->assertSame(60, $datos['acierto_general']); // 3 aceptados de 5 propuestos
        $this->assertSame(12.5, $datos['segundos_promedio']);
    }

    public function test_el_resumen_es_solo_para_administracion(): void
    {
        $this->actuarComo(codigo: 'asistente_legajo');

        $this->getJson('/api/v1/documentos/escaner/precision')->assertForbidden();
    }
}
