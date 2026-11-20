<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\EscanerSeccionesController;
use App\Models\AuditoriaEvento;
use App\Models\LecturaIa;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\EscanerSecciones;
use App\Support\Seccion01;
use Database\Seeders\Seccion01CatalogoSeeder;
use Database\Seeders\Seccion06CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
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

        return $this->leerYEsperar();
    }

    /** Envía el PDF (responde 202 al instante) y consulta la lectura, que en las pruebas termina con la petición. */
    private function leerYEsperar(): TestResponse
    {
        $id = $this->post('/api/v1/documentos/escanear', [
            'usuario_id' => $this->persona->id, 'tipo_documento_id' => $this->tipo->id,
            'campos' => ['emisor', 'fecha_emision', 'num_resolucion'], 'pdf' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertAccepted()->assertJsonPath('data.estado', 'en_espera')->json('data.lectura_id');

        return $this->getJson('/api/v1/documentos/escaner/lecturas/'.$id);
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

    public function test_la_lectura_corre_despues_de_responder_e_informa_su_avance(): void
    {
        $this->actuarComo();
        $avance = [];
        $this->mock(EscanerSecciones::class, function ($mock) use (&$avance): void {
            $mock->shouldReceive('leer')->once()->andReturnUsing(function ($pdf, ?callable $progreso) use ($mock, &$avance): string {
                $this->assertFileExists($pdf->getRealPath());
                foreach ([1, 2, 3] as $pagina) {
                    $progreso($pagina, 3);
                    $avance[] = LecturaIa::query()->latest()->firstOrFail()->only(['estado', 'pagina_actual', 'paginas_leer']);
                }
                $mock->paginasDocumento = 5;

                return 'DNI 45678912';
            });
            $mock->shouldReceive('interpretar')->once()->andReturn([]);
        });

        $datos = $this->leerYEsperar()->assertOk()->json('data');

        $this->assertSame(['estado' => 'leyendo', 'pagina_actual' => 3, 'paginas_leer' => 3], $avance[2]);
        $this->assertSame(['lista', 3, 3, 5], [$datos['estado'], $datos['pagina_actual'], $datos['paginas_leer'], $datos['paginas_documento']]);
        $this->assertStringContainsString('las primeras 3 de 5 páginas', $datos['aviso']);
        // La copia temporal del PDF se borra al terminar.
        $this->assertSame([], glob(sys_get_temp_dir().'/escalafon-lectura-*.pdf'));
    }

    public function test_un_corte_por_tiempo_se_informa_como_tal_y_no_como_falta_de_conexion(): void
    {
        $this->actuarComo();
        config(['escaner.timeout' => 150]);
        $this->mock(EscanerSecciones::class, function ($mock): void {
            $mock->shouldReceive('leer')->once()->andReturn('texto');
            $mock->shouldReceive('interpretar')->once()->andThrow(new ConnectionException('cURL error 28: Operation timed out after 150001 milliseconds'));
        });

        $datos = $this->leerYEsperar()->assertOk()->json('data');

        $this->assertSame('error', $datos['estado']);
        $this->assertStringContainsString('tardó más de 150 s', $datos['error']);
        $this->assertArrayNotHasKey('campos', $datos);
        $this->assertStringContainsString('"resultado":"error"', (string) AuditoriaEvento::query()->where('accion', 'documento.lectura_ia')->value('datos_despues'));
    }

    public function test_sin_ollama_dice_que_no_hay_conexion(): void
    {
        $this->actuarComo();
        $this->mock(EscanerSecciones::class, function ($mock): void {
            $mock->shouldReceive('leer')->once()->andReturn('texto');
            $mock->shouldReceive('interpretar')->once()->andThrow(new ConnectionException('cURL error 7: Failed to connect to ollama port 11434'));
        });

        $this->assertStringContainsString('No se pudo conectar con Ollama', $this->leerYEsperar()->json('data.error'));
    }

    public function test_un_pdf_ilegible_muestra_el_motivo(): void
    {
        $this->actuarComo();
        $this->mock(EscanerSecciones::class, function ($mock): void {
            $mock->shouldReceive('leer')->once()->andThrow(ValidationException::withMessages(['pdf' => 'El PDF tiene contraseña. Adjunta una copia sin protección.']));
        });

        $this->assertSame('El PDF tiene contraseña. Adjunta una copia sin protección.', $this->leerYEsperar()->json('data.error'));
    }

    public function test_solo_quien_escaneo_ve_el_resultado(): void
    {
        $this->actuarComo();
        $id = $this->escanear('DNI 45678912')->json('data.lectura_id');

        $this->actuarComo();
        $this->getJson('/api/v1/documentos/escaner/lecturas/'.$id)->assertForbidden();
    }

    public function test_el_resultado_no_queda_en_la_base_y_caduca(): void
    {
        $this->actuarComo();
        $id = $this->escanear('UNIVERSIDAD DE EJEMPLO. DNI 45678912')->assertOk()->json('data.lectura_id');
        $this->assertStringNotContainsString('UNIVERSIDAD DE EJEMPLO', json_encode(LecturaIa::query()->findOrFail($id)->getAttributes()));
        // En la caché va cifrado.
        $this->assertStringNotContainsString('UNIVERSIDAD DE EJEMPLO', (string) Cache::get('lectura-ia:'.$id));

        Cache::forget('lectura-ia:'.$id);
        $this->getJson('/api/v1/documentos/escaner/lecturas/'.$id)->assertOk()
            ->assertJsonPath('data.estado', 'error')->assertJsonPath('data.campos', null);
    }

    public function test_una_lectura_sin_avances_se_da_por_interrumpida(): void
    {
        $admin = $this->actuarComo();
        $lectura = LecturaIa::factory()->create(['registrado_por' => $admin->id, 'estado' => 'leyendo']);
        $lectura->forceFill(['updated_at' => now()->subMinutes(LecturaIa::MINUTOS_SIN_AVANCE + 1)])->saveQuietly();

        $this->getJson('/api/v1/documentos/escaner/lecturas/'.$lectura->id)->assertOk()
            ->assertJsonPath('data.estado', 'error')->assertJsonPath('data.error', 'La lectura se interrumpió (p. ej. se reinició el servidor). Vuelve a intentarlo.');
    }

    public function test_una_persona_no_puede_lanzar_otra_lectura_mientras_tiene_una_en_curso(): void
    {
        $usuario = $this->actuarComo();
        LecturaIa::factory()->create(['registrado_por' => $usuario->id, 'estado' => 'leyendo']);
        $this->mock(EscanerSecciones::class, fn ($mock) => $mock->shouldNotReceive('leer'));

        $this->post('/api/v1/documentos/escanear', [
            'usuario_id' => $this->persona->id, 'tipo_documento_id' => $this->tipo->id, 'campos' => ['emisor'], 'pdf' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(429)->assertJsonPath('message', 'Ya tienes una lectura en curso: espera a que termine.');
    }

    public function test_hay_un_tope_de_lecturas_en_curso_en_la_institucion(): void
    {
        $this->actuarComo();
        LecturaIa::factory()->count(EscanerSeccionesController::MAXIMO_EN_CURSO)->create(['estado' => 'en_espera']);
        $this->mock(EscanerSecciones::class, fn ($mock) => $mock->shouldNotReceive('leer'));

        $this->post('/api/v1/documentos/escanear', [
            'usuario_id' => $this->persona->id, 'tipo_documento_id' => $this->tipo->id, 'campos' => ['emisor'], 'pdf' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(429);
    }

    public function test_una_lectura_colgada_no_cuenta_para_el_tope(): void
    {
        $usuario = $this->actuarComo();
        $colgada = LecturaIa::factory()->create(['registrado_por' => $usuario->id, 'estado' => 'leyendo']);
        $colgada->forceFill(['updated_at' => now()->subMinutes(LecturaIa::MINUTOS_SIN_AVANCE + 1)])->saveQuietly();

        $this->escanear('DNI 45678912')->assertOk()->assertJsonPath('data.estado', 'lista');
    }

    public function test_se_borran_las_copias_temporales_de_lecturas_interrumpidas(): void
    {
        $vieja = sys_get_temp_dir().'/'.EscanerSeccionesController::PREFIJO_TEMPORAL.'prueba-vieja.pdf';
        $reciente = sys_get_temp_dir().'/'.EscanerSeccionesController::PREFIJO_TEMPORAL.'prueba-reciente.pdf';
        file_put_contents($vieja, '%PDF-1.4');
        file_put_contents($reciente, '%PDF-1.4');
        touch($vieja, time() - 7200);

        EscanerSeccionesController::limpiarTemporales();

        $this->assertFileDoesNotExist($vieja);
        $this->assertFileExists($reciente);
        @unlink($reciente);
    }

    public function test_en_documentos_de_familiares_no_se_compara_el_titular(): void
    {
        $this->actuarComo();
        $this->seed(Seccion01CatalogoSeeder::class);
        $this->tipo = TipoDocumento::query()->where('codigo', Seccion01::DNI_HIJOS)->firstOrFail();

        $datos = $this->escanear('DNI del hijo: JUAN QUISPE ROJAS, DNI 79123456')->assertOk()->json('data');

        $this->assertSame('lista', $datos['estado']);
        $this->assertNull($datos['titular']);
    }
}
