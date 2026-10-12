<?php

namespace Tests\Feature;

use App\Models\ConfiguracionFuncionalidad;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\ConfiguracionFuncionalidades;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Etapa A de la configuración genérica: lo desactivado no admite creación ni modificación (por su ruta o por el tipo de
 * documento que se sube), la lectura sigue disponible y cualquier sesión sabe qué ocultar en la navegación.
 */
class ConfiguracionBloqueoTest extends TestCase
{
    use RefreshDatabase;

    private function mensaje(string $codigo): string
    {
        return '«'.ConfiguracionFuncionalidades::definicion($codigo)['nombre'].'» está desactivada en la configuración de la institución.';
    }

    private function tipo(string $codigo): string
    {
        return TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->valueOrFail('id');
    }

    /** La respuesta no es el bloqueo de la configuración (puede fallar por otras reglas). */
    private function noBloqueada(TestResponse $respuesta): void
    {
        $this->assertStringNotContainsString('desactivada en la configuración', (string) $respuesta->json('message'));
    }

    private function desactivar(string ...$codigos): void
    {
        foreach ($codigos as $codigo) {
            ConfiguracionFuncionalidades::cambiar($codigo, false, 'Prueba de bloqueo', Usuario::query()->firstOrFail());
        }
    }

    public function test_every_write_route_of_an_inactive_feature_is_rejected(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $probadas = 0;
        foreach (ConfiguracionFuncionalidades::catalogo() as $codigo => $definicion) {
            if (! ConfiguracionFuncionalidades::configurable($codigo) || $definicion['rutas_escritura'] === []) {
                continue;
            }
            // Ella y lo que depende de ella, inactivos (filas directas: el orden de desactivación no importa aquí).
            ConfiguracionFuncionalidad::query()->delete();
            ConfiguracionFuncionalidades::olvidar();
            foreach ([$codigo, ...ConfiguracionFuncionalidades::dependientes($codigo)] as $inactiva) {
                ConfiguracionFuncionalidad::factory()->create(['codigo' => $inactiva, 'activa' => false]);
            }
            foreach ($definicion['rutas_escritura'] as $entrada) {
                [$ruta, $restricciones] = is_array($entrada) ? $entrada : [$entrada, []];
                [$metodo, $uri] = explode(' ', $ruta, 2);
                $url = '/'.preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => $restricciones[$m[1]][0]
                    ?? ($m[1] === 'usuario' ? $persona->id : (string) Str::uuid()), $uri);
                $this->json($metodo, $url, [])->assertStatus(409)->assertJsonPath('message', $this->mensaje($codigo));
                $probadas++;
            }
        }
        $this->assertGreaterThan(40, $probadas);

        // Activa, la misma ruta no la bloquea la configuración.
        ConfiguracionFuncionalidad::query()->delete();
        ConfiguracionFuncionalidades::olvidar();
        $this->noBloqueada($this->postJson('/api/v1/expedientes/'.$persona->id.'/seccion-09/controversias', []));
        $this->noBloqueada($this->postJson('/api/v1/compensaciones-beneficios', []));
    }

    public function test_shared_routes_are_blocked_only_for_the_inactive_type(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $url = '/api/v1/expedientes/'.$persona->id;
        $this->desactivar('s07.progresion', 's09.controversias', 's08.sanciones', 's06.compensaciones');

        // Sección 07: la ruta {tipo} se bloquea solo para 7.2.
        $this->postJson($url.'/seccion-07/progresiones-carrera', [])->assertStatus(409)->assertJsonPath('message', $this->mensaje('s07.progresion'));
        $this->postJson($url.'/seccion-07/ratificaciones-docentes', [])->assertStatus(409);
        $this->noBloqueada($this->postJson($url.'/seccion-07/desplazamientos', []));
        $this->noBloqueada($this->postJson($url.'/seccion-07/evaluaciones-desempeno', []));

        // Subida común y subida de la 08: según el tipo de documento.
        $this->postJson($url.'/documentos', ['tipo_documento_id' => $this->tipo('S09_A_CONTROVERSIAS_INDIVIDUALES'), 'seccion_legajo_id' => 9])
            ->assertStatus(409)->assertJsonPath('message', $this->mensaje('s09.controversias'));
        $this->noBloqueada($this->postJson($url.'/documentos', ['tipo_documento_id' => $this->tipo('S09_C_AFILIACION_SINDICATO'), 'seccion_legajo_id' => 9]));
        $this->noBloqueada($this->postJson($url.'/documentos', ['tipo_documento_id' => $this->tipo('S09_OTROS_UNIVERSIDAD'), 'seccion_legajo_id' => 9]));
        $this->postJson($url.'/documentos', ['tipo_documento_id' => $this->tipo('S07_72A_RESOLUCION_PROGRESION'), 'seccion_legajo_id' => 7])->assertStatus(409);
        $this->postJson($url.'/documentos', ['tipo_documento_id' => $this->tipo('COMP_OTROS'), 'seccion_legajo_id' => 6])
            ->assertStatus(409)->assertJsonPath('message', $this->mensaje('s06.compensaciones'));
        $this->postJson($url.'/seccion-08/documentos', ['tipo_documento_id' => $this->tipo('SAN_RESOLUCION_SANCION')])
            ->assertStatus(409)->assertJsonPath('message', $this->mensaje('s08.sanciones'));
        $this->noBloqueada($this->postJson($url.'/seccion-08/documentos', ['tipo_documento_id' => $this->tipo('REC_CARTA_FELICITACION')]));

        // El CRUD genérico de administración que recibe un tipo de documento no muta el legajo: no se bloquea por el tipo.
        $controversia = $this->tipo('S09_A_CONTROVERSIAS_INDIVIDUALES');
        foreach (['requerimientos-documentos', 'compromiso-documentos', 'referencias-legajo-pasivo'] as $recurso) {
            $this->noBloqueada($this->postJson('/api/v1/'.$recurso, ['tipo_documento_id' => $controversia]));
        }

        // Lectura, y anular (pendiente U8), no se bloquean.
        $this->getJson($url.'/seccion-09')->assertOk();
        $this->getJson($url.'/seccion-06')->assertOk();
        $this->getJson($url.'/seccion-07')->assertOk();
        $this->noBloqueada($this->postJson($url.'/seccion-09/controversias/'.Str::uuid().'/anular', ['motivo_anulacion' => 'Prueba']));
    }

    public function test_any_session_gets_what_to_hide_but_only_configuracion_modulos_sees_the_detail(): void
    {
        $this->getJson('/api/v1/funcionalidades')->assertUnauthorized();
        $this->actuarComo(null, 'asistente_legajo');
        $activas = $this->getJson('/api/v1/funcionalidades')->assertOk()->assertJsonPath('data.secciones_ocultas', [])
            ->assertJsonPath('data.menu_oculto', [])->json('data.activas');
        $this->assertTrue($activas['s06.compensaciones']);
        $this->getJson('/api/v1/configuracion/funcionalidades')->assertForbidden();

        $this->actuarComo();
        $this->desactivar('s06.compensaciones', 's09.controversias');
        $this->actuarComo(null, 'asistente_legajo');
        $data = $this->getJson('/api/v1/funcionalidades')->assertOk()->json('data');
        $this->assertSame([6], $data['secciones_ocultas']);
        $this->assertContains('seccion-06', $data['menu_oculto']);
        $this->assertContains('s09-individuales', $data['menu_oculto']);
        $this->assertNotContains('s09-afiliacion', $data['menu_oculto']);
        $this->assertContains('S09_B_CONTROVERSIAS_COLECTIVAS', $data['tipos_inactivos']);
        $this->assertNotContains('S09_OTROS_UNIVERSIDAD', $data['tipos_inactivos']);
        $this->assertFalse($data['activas']['s06.compensaciones']);
    }

    public function test_admin_wildcard_does_not_grant_configuracion_modulos(): void
    {
        // configuracion.modulos es de asignación explícita: el '*' de ADMIN no lo incluye.
        $this->actuarComo();
        $this->getJson('/api/v1/configuracion/funcionalidades')->assertForbidden();
        $this->patchJson('/api/v1/configuracion/funcionalidades/s06.compensaciones', ['activa' => false, 'motivo' => 'Prueba'])->assertForbidden();
        $this->assertDatabaseCount('configuracion_funcionalidades', 0);
    }

    public function test_changing_a_feature_without_configuracion_modulos_is_rejected(): void
    {
        $this->actuarComo(null, 'asistente_legajo');
        $this->patchJson('/api/v1/configuracion/funcionalidades/s06.compensaciones', ['activa' => false, 'motivo' => 'Prueba'])->assertForbidden();
        $this->assertDatabaseCount('configuracion_funcionalidades', 0);
        $this->assertTrue(ConfiguracionFuncionalidades::activa('s06.compensaciones'));
    }
}
