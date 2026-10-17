<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\ConfiguracionFuncionalidad;
use App\Models\Desplazamiento;
use App\Models\EvaluacionDesempeno;
use App\Models\ProgresionCarrera;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\ConfiguracionFuncionalidades;
use Database\Seeders\Seccion07DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Primera funcionalidad configurable de principio a fin: s07.evaluaciones.puntaje. API de configuración con el permiso
 * explícito configuracion.modulos, auditoría, y efecto en las evaluaciones de la sección 07.
 */
class ConfiguracionPuntajeTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private const PUNTAJE = 's07.evaluaciones.puntaje';

    private const URL = '/api/v1/configuracion/funcionalidades';

    /** @var array<string, int> Siguiente folio por persona. */
    private array $folios = [];

    /** Persona con el rol explícito de configuración (no tiene ningún otro permiso). */
    private function configurador(): Usuario
    {
        return $this->actuarComo(null, 'configuracion_modulos');
    }

    private function cambiar(bool $activa, string $motivo = 'Decisión institucional'): TestResponse
    {
        return $this->patchJson(self::URL.'/'.self::PUNTAJE, ['activa' => $activa, 'motivo' => $motivo]);
    }

    private function url07(Usuario $persona, string $resto = ''): string
    {
        return '/api/v1/expedientes/'.$persona->id.'/seccion-07'.$resto;
    }

    /** Sube un documento de la sección 07 con folios correlativos y devuelve su id. */
    private function documento07(Usuario $persona, string $codigo): string
    {
        $tipo = TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->firstOrFail();
        $folio = $this->folios[$persona->id] ??= 1;
        $id = $this->subir($persona, $this->metadatos($tipo, $folio, $folio))->assertCreated()->json('data.id');
        $this->folios[$persona->id]++;

        return $id;
    }

    private function evaluacion(Usuario $persona, array $cambios = []): TestResponse
    {
        return $this->postJson($this->url07($persona, '/evaluaciones-desempeno'), [...['ciclo_anio' => 2025, 'fecha_evaluacion' => '2025-12-10',
            'calificacion' => 'Buen rendimiento', 'plan_mejora' => false, 'documento_id' => $this->documento07($persona, 'S07_71A_GESTION_RENDIMIENTO')], ...$cambios]);
    }

    public function test_configuration_permission_is_explicit_and_outside_the_wildcard(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        // Un rol con solo '*' no recibe configuracion.modulos.
        config(['permisos.roles.solo_comodin' => '*']);
        $comodin = $this->actuarComo(null, 'solo_comodin');
        $this->assertNotContains('configuracion.modulos', $comodin->permisos());
        $this->assertContains('sistema.administrar', $comodin->permisos());
        $this->getJson(self::URL)->assertForbidden();
        $this->cambiar(false)->assertForbidden();
        $this->assertNotContains('configuracion.modulos', $this->getJson('/api/v1/auth/me')->assertOk()->json('data.permisos'));

        // ADMIN lo nombra junto a '*': tiene todo el catálogo asignable y además configuracion.modulos.
        $admin = $this->actuarComo();
        $this->assertContains('configuracion.modulos', $admin->permisos());
        $this->assertSame([], array_diff([...$comodin->permisos(), 'configuracion.modulos'], $admin->permisos()));
        $this->assertContains('configuracion.modulos', $this->getJson('/api/v1/auth/me')->assertOk()->json('data.permisos'));
        $this->getJson(self::URL)->assertOk();

        $this->actuarComo(null, 'asistente_legajo');
        $this->getJson(self::URL)->assertForbidden();

        // Solo el rol que lo nombra de forma explícita.
        $configurador = $this->configurador();
        $this->assertSame(['configuracion.modulos'], $configurador->permisos());
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.permisos', ['configuracion.modulos']);
        $this->getJson(self::URL)->assertOk();
        $this->assertSame(0, ConfiguracionFuncionalidad::query()->count());
        $this->assertSame(0, AuditoriaEvento::query()->where('entidad', 'configuracion_funcionalidades')->count());
    }

    public function test_listing_shows_every_feature_with_core_and_warnings(): void
    {
        $this->configurador();
        $data = collect($this->getJson(self::URL)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data'));

        $this->assertSame(array_keys(ConfiguracionFuncionalidades::catalogo()), $data->pluck('codigo')->all());
        $this->assertSame(['s01.identidad', 's02.seleccion', 's02.vinculos'], $data->where('nucleo', true)->pluck('codigo')->values()->all());
        $this->assertSame(['s01.identidad', 's02.seleccion', 's02.vinculos', 'adicional.referencias_pasivas'], $data->where('configurable', false)->pluck('codigo')->values()->all());
        $puntaje = $data->firstWhere('codigo', self::PUNTAJE);
        $this->assertSame(['Puntuación numérica de las evaluaciones', true, null, [], ['s07.evaluaciones'], ['evaluaciones_desempeno.puntaje'], 0, false],
            [$puntaje['nombre'], $puntaje['activa'], $puntaje['ultimo_cambio'], $puntaje['bloqueos'], $puntaje['depende_de'], $puntaje['campos'],
                $puntaje['registros'], $puntaje['oculta_seccion']]);
        $this->assertSame(config('funcionalidades.advertencia_normativa'), $data->firstWhere('codigo', 's06.compensaciones')['advertencia']);
        $this->assertTrue($data->firstWhere('codigo', 's06.compensaciones')['oculta_seccion']);
        $this->assertTrue($data->every(fn (array $estado): bool => $estado['activa']));
    }

    public function test_change_requires_reason_respects_restrictions_and_is_audited(): void
    {
        $configurador = $this->configurador();

        $this->patchJson(self::URL.'/'.self::PUNTAJE, ['activa' => false])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->patchJson(self::URL.'/'.self::PUNTAJE, ['activa' => false, 'motivo' => '   '])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->patchJson(self::URL.'/'.self::PUNTAJE, ['motivo' => 'Sin estado'])->assertUnprocessable()->assertJsonValidationErrors('activa');
        $this->patchJson(self::URL.'/'.self::PUNTAJE, ['activa' => false, 'motivo' => 'x', 'updated_by' => $configurador->id])
            ->assertUnprocessable()->assertJsonValidationErrors('updated_by');
        $this->patchJson(self::URL.'/s99.inventada', ['activa' => false, 'motivo' => 'x'])->assertNotFound();
        $this->patchJson(self::URL.'/s07.evaluaciones', ['activa' => false, 'motivo' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('activa');
        $this->patchJson(self::URL.'/s01.identidad', ['activa' => false, 'motivo' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('activa');
        $this->cambiar(true)->assertUnprocessable()->assertJsonValidationErrors('activa');
        $this->assertSame(0, ConfiguracionFuncionalidad::query()->count());
        $this->assertSame(0, AuditoriaEvento::query()->where('entidad', 'configuracion_funcionalidades')->count());

        $nombre = trim($configurador->nombres.' '.$configurador->apellido_paterno.' '.$configurador->apellido_materno);
        $this->cambiar(false, 'La universidad no usa puntuación numérica')->assertOk()
            ->assertJsonPath('data.codigo', self::PUNTAJE)->assertJsonPath('data.activa', false)
            ->assertJsonPath('data.ultimo_cambio.motivo', 'La universidad no usa puntuación numérica')->assertJsonPath('data.ultimo_cambio.por', $nombre);
        $this->assertFalse(ConfiguracionFuncionalidades::activa(self::PUNTAJE));
        $this->assertTrue(ConfiguracionFuncionalidades::activa('s07.evaluaciones'));

        $evento = AuditoriaEvento::query()->where('entidad', 'configuracion_funcionalidades')->sole();
        $this->assertSame(['funcionalidad.desactivar', $configurador->id], [$evento->accion, $evento->actor_usuario_id]);
        $this->assertSame(['codigo' => self::PUNTAJE, 'activa' => true], json_decode($evento->datos_antes, true));
        $this->assertSame(['codigo' => self::PUNTAJE, 'activa' => false, 'motivo' => 'La universidad no usa puntuación numérica'],
            json_decode($evento->datos_despues, true));
        $this->assertNotNull($evento->ip_origen);

        $this->cambiar(true, 'Se incorpora la puntuación')->assertOk()->assertJsonPath('data.activa', true);
        $this->assertSame($evento->hash_registro, AuditoriaEvento::query()->where('accion', 'funcionalidad.activar')->sole()->hash_previo);
    }

    public function test_disabled_score_rejects_new_values_and_keeps_historical_scores(): void
    {
        Storage::fake((string) config('legajo.disco_documentos'));
        $this->seed(Seccion07DemoSeeder::class);
        $persona = Usuario::query()->where('numero_doc_identidad', '99000701')->firstOrFail();
        $admin = $this->actuarComo();
        $historica = $this->evaluacion($persona, ['puntaje' => 80])->assertCreated()->json('data.id');
        $this->assertSame('80.00', EvaluacionDesempeno::query()->findOrFail($historica)->puntaje);
        $this->getJson($this->url07($persona))->assertOk()->assertJsonPath('data.puntaje_activo', true);

        Sanctum::actingAs($this->configurador());
        $this->cambiar(false)->assertOk();
        Sanctum::actingAs($admin);

        // Las evaluaciones siguen visibles; la ficha y la pantalla Evaluaciones informan que la puntuación está desactivada.
        $ficha = $this->getJson($this->url07($persona))->assertOk()->assertJsonPath('data.puntaje_activo', false)->json('data.evaluaciones');
        $this->assertContains($historica, array_column($ficha, 'id'));
        $this->getJson('/api/v1/seccion07-opciones')->assertOk()->assertJsonPath('data.puntaje_activo', false);
        $this->assertContains($historica, array_column($this->getJson('/api/v1/seccion07/evaluaciones')->assertOk()->json('data'), 'id'));

        // Alta: un puntaje se rechaza; sin puntaje (o vacío) se registra.
        $this->evaluacion($persona, ['ciclo_anio' => 2024, 'puntaje' => 70])->assertUnprocessable()
            ->assertJsonValidationErrors(['puntaje' => 'La puntuación numérica de las evaluaciones está desactivada en la configuración.']);
        $nueva = $this->evaluacion($persona, ['ciclo_anio' => 2024, 'puntaje' => null])->assertCreated()->json('data.id');
        $this->assertNull(EvaluacionDesempeno::query()->findOrFail($nueva)->puntaje);

        // Corrección: un puntaje nuevo se rechaza; enviarlo vacío no borra el histórico; los demás campos se corrigen.
        $url = $this->url07($persona, '/evaluaciones-desempeno/'.$historica);
        $this->patchJson($url, ['puntaje' => 95])->assertUnprocessable()->assertJsonValidationErrors('puntaje');
        $this->patchJson($url, ['puntaje' => null, 'calificacion' => 'Rendimiento distinguido'])->assertOk();
        $this->patchJson($url, ['puntaje' => ''])->assertOk();
        $guardada = EvaluacionDesempeno::query()->findOrFail($historica);
        $this->assertSame(['80.00', 'Rendimiento distinguido'], [$guardada->puntaje, $guardada->calificacion]);
        // Las demás validaciones se mantienen.
        $this->patchJson($url, ['calificacion' => ''])->assertUnprocessable()->assertJsonValidationErrors('calificacion');

        // Al reactivarla se vuelve a aceptar y el histórico sigue intacto.
        Sanctum::actingAs($this->configurador());
        $this->cambiar(true)->assertOk();
        Sanctum::actingAs($admin);
        $this->patchJson($this->url07($persona, '/evaluaciones-desempeno/'.$nueva), ['puntaje' => 75])->assertOk();
        $this->assertSame('75.00', EvaluacionDesempeno::query()->findOrFail($nueva)->puntaje);
        $this->assertSame('80.00', EvaluacionDesempeno::query()->findOrFail($historica)->puntaje);
    }

    public function test_selection_result_cannot_be_disabled_but_section_07_stays_configurable(): void
    {
        $this->configurador();
        $s02a = TipoDocumento::query()->where('codigo', 'S02_A_RESULTADO_SELECCION')->where('activo', true)->firstOrFail();

        // La selección ocurre fuera del sistema, pero S02_A se conserva en el expediente: 2.1 es núcleo.
        $errores = $this->patchJson(self::URL.'/s02.seleccion', ['activa' => false, 'motivo' => 'La selección se hace fuera del sistema'])
            ->assertUnprocessable()->assertJsonValidationErrors('activa')->json('errors.activa');
        $this->assertStringContainsString('S02_A', implode(' ', $errores));
        $this->assertSame(0, ConfiguracionFuncionalidad::query()->count());
        $this->assertSame(0, AuditoriaEvento::query()->where('entidad', 'configuracion_funcionalidades')->count());

        $seleccion = collect($this->getJson(self::URL)->assertOk()->json('data'))->firstWhere('codigo', 's02.seleccion');
        $this->assertSame([true, true, false], [$seleccion['activa'], $seleccion['nucleo'], $seleccion['configurable']]);
        $this->assertTrue(ConfiguracionFuncionalidades::activa('s02.seleccion'));
        $this->assertTrue(ConfiguracionFuncionalidades::tipoActivo($s02a->codigo, $s02a->seccion_legajo_id));
        $this->assertNull(ConfiguracionFuncionalidades::inactivaPorTipo($s02a->id));
        $publico = $this->getJson('/api/v1/funcionalidades')->assertOk()->json('data');
        $this->assertNotContains($s02a->codigo, $publico['tipos_inactivos']);
        $this->assertNotContains('s02-seleccion', $publico['menu_oculto']);

        // La sección 07, con su puntuación numérica, sigue siendo configurable.
        foreach (['s07.evaluaciones.puntaje', 's07.progresion', 's07.desplazamientos', 's07.evaluaciones'] as $codigo) {
            $this->assertTrue(ConfiguracionFuncionalidades::configurable($codigo), $codigo);
            $this->patchJson(self::URL.'/'.$codigo, ['activa' => false, 'motivo' => 'No se usa'])->assertOk()->assertJsonPath('data.activa', false);
        }
        $this->assertTrue(ConfiguracionFuncionalidades::activa('s02.seleccion'));
    }

    public function test_other_section_07_features_keep_working_with_the_score_disabled(): void
    {
        Storage::fake((string) config('legajo.disco_documentos'));
        $this->seed(Seccion07DemoSeeder::class);
        $persona = Usuario::query()->where('numero_doc_identidad', '99000701')->firstOrFail();
        $configurador = $this->configurador();
        ConfiguracionFuncionalidades::cambiar(self::PUNTAJE, false, 'Sin puntuación numérica', $configurador);
        $this->actuarComo();

        $vinculo = ProgresionCarrera::query()->where('usuario_id', $persona->id)->value('vinculo_laboral_id');
        $this->postJson($this->url07($persona, '/progresiones-carrera'), ['vinculo_laboral_id' => $vinculo, 'nivel_nuevo' => 'SP-DI',
            'fecha_efectiva' => '2026-01-01', 'numero_resolucion' => 'R-1', 'documento_id' => $this->documento07($persona, 'S07_72A_RESOLUCION_PROGRESION')])
            ->assertCreated();
        $id = $this->postJson($this->url07($persona, '/desplazamientos'), ['tipo' => 'rotacion', 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-06-30',
            'puesto_destino' => 'Asistente', 'documento_id' => $this->documento07($persona, 'S07_73C_ROTACION')])->assertCreated()->json('data.id');
        $this->patchJson($this->url07($persona, '/desplazamientos/'.$id), ['puesto_destino' => 'Analista'])->assertOk();
        $this->assertSame('Analista', Desplazamiento::query()->findOrFail($id)->puesto_destino);
        $this->evaluacion($persona)->assertCreated();

        $data = $this->getJson($this->url07($persona))->assertOk()->json('data');
        $this->assertNotEmpty($data['evaluaciones']);
        $this->assertNotEmpty($data['progresiones']);
        $this->assertNotEmpty($data['desplazamientos']);
        foreach (['evaluaciones', 'progresiones', 'ratificaciones', 'desplazamientos'] as $tipo) {
            $this->getJson('/api/v1/seccion07/'.$tipo)->assertOk();
        }
    }
}
