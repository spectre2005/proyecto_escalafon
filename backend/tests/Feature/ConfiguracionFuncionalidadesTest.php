<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\ConfiguracionFuncionalidad;
use App\Models\DocumentoUsuario;
use App\Models\EvaluacionDesempeno;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\ReferenciaLegajoPasivo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\ConfiguracionFuncionalidades;
use App\Support\PerfilDocumental;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class ConfiguracionFuncionalidadesTest extends TestCase
{
    use RefreshDatabase;

    /** Tipos «Otros documentos» de secciones con varias funcionalidades: no pertenecen a ninguna. */
    private const SIN_FUNCIONALIDAD = ['S01_OTROS_UNIVERSIDAD', 'S05_OTROS_UNIVERSIDAD', 'S09_OTROS_UNIVERSIDAD'];

    /** Catálogo de prueba para ejercitar el mecanismo con dependencias controladas. */
    private function catalogoDePrueba(array $base = []): void
    {
        $definicion = fn (string $nombre, array $extra = []): array => $extra + ['nombre' => $nombre, 'seccion' => 5, 'implementada' => true,
            'esencial' => null, 'depende_de' => [], 'bloqueo' => null, 'menu' => [], 'documentos' => [], 'rutas_escritura' => [], 'tablas' => []];
        config()->set('funcionalidades.funcionalidades', [
            'prueba.base' => $definicion('Base', $base),
            'prueba.uso' => $definicion('Uso de la base', ['depende_de' => ['prueba.base']]),
            'prueba.libre' => $definicion('Libre'),
        ]);
    }

    /** Desactiva directamente (sin el servicio), para preparar escenarios. */
    private function inactiva(string ...$codigos): void
    {
        foreach ($codigos as $codigo) {
            ConfiguracionFuncionalidad::factory()->create(['codigo' => $codigo, 'activa' => false]);
        }
    }

    /** @return array<string, mixed> Mensajes de la validación que lanza el cambio. */
    private function rechazo(callable $cambio): array
    {
        try {
            $cambio();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Se esperaba que el cambio se rechazara.');
    }

    public function test_catalog_declares_sections_and_only_existing_features(): void
    {
        $secciones = ConfiguracionFuncionalidades::secciones();
        $catalogo = ConfiguracionFuncionalidades::catalogo();
        $this->assertSame(array_keys(SeccionesLegajoCatalogoSeeder::SECCIONES), array_keys($secciones));
        foreach (range(1, 12) as $seccion) {
            $this->assertSame('normativa', $secciones[$seccion]['caracter']);
        }
        $this->assertSame('adicional', $secciones[13]['caracter']);
        $this->assertFalse($secciones[12]['implementada']);

        // Toda sección implementada tiene al menos una funcionalidad; la 12 no tiene ninguna.
        $porSeccion = array_count_values(array_column($catalogo, 'seccion'));
        foreach ($secciones as $id => $seccion) {
            $this->assertSame($seccion['implementada'], isset($porSeccion[$id]), "Sección {$id}");
        }

        $rutas = collect(Route::getRoutes()->getRoutes())->flatMap(fn ($ruta) => array_map(fn (string $metodo): string => $metodo.' '.$ruta->uri(),
            array_diff($ruta->methods(), ['HEAD'])))->all();
        $menus = [];
        foreach ($catalogo as $codigo => $definicion) {
            $this->assertSame(['nombre', 'seccion', 'implementada', 'esencial', 'depende_de', 'bloqueo', 'menu', 'documentos', 'rutas_escritura', 'tablas'],
                array_keys(array_diff_key($definicion, ['campos' => true])), $codigo);
            foreach ($definicion['rutas_escritura'] as $entrada) {
                [$ruta, $restricciones] = is_array($entrada) ? $entrada : [$entrada, []];
                $this->assertContains($ruta, $rutas, "{$codigo}: {$ruta}");
                $this->assertDoesNotMatchRegularExpression('/^(GET|HEAD) /', $ruta, $codigo);
                foreach (array_keys($restricciones) as $parametro) {
                    $this->assertStringContainsString('{'.$parametro.'}', $ruta, $codigo);
                }
            }
            foreach ($definicion['depende_de'] as $dependencia) {
                $this->assertArrayHasKey($dependencia, $catalogo, $codigo);
            }
            foreach ($definicion['tablas'] as $tabla) {
                $this->assertTrue(Schema::hasTable($tabla), "{$codigo}: {$tabla}");
            }
            foreach ($definicion['campos'] ?? [] as $campo) {
                [$tabla, $columna] = explode('.', $campo);
                $this->assertTrue(Schema::hasColumn($tabla, $columna), "{$codigo}: {$campo}");
                $this->assertSame([[], [], []], [$definicion['rutas_escritura'], $definicion['menu'], $definicion['documentos']], $codigo);
            }
            // Cada código de tipo declarado existe y está activo en su sección.
            foreach (array_diff($definicion['documentos'], ['*']) as $tipo) {
                $this->assertTrue(TipoDocumento::query()->where('seccion_legajo_id', $definicion['seccion'])->where('codigo', $tipo)->where('activo', true)->exists(), "{$codigo}: {$tipo}");
            }
            $menus = [...$menus, ...$definicion['menu']];
        }
        // Una entrada de menú pertenece a una sola funcionalidad.
        $this->assertSame(array_unique($menus), $menus);

        // Todo tipo activo de las secciones implementadas pertenece a una funcionalidad, salvo «Otros documentos».
        foreach (TipoDocumento::query()->where('activo', true)->whereIn('seccion_legajo_id', range(1, 11))->get() as $tipo) {
            $de = ConfiguracionFuncionalidades::deTipo($tipo->codigo, $tipo->seccion_legajo_id);
            $this->assertSame(in_array($tipo->codigo, self::SIN_FUNCIONALIDAD, true), $de === [], $tipo->codigo);
            $this->assertLessThanOrEqual(1, count($de), $tipo->codigo);
        }

        // Núcleo fijo: identidad, 2.1 (S02_A se conserva) y vínculos. Bloqueada: la sección adicional (folios pendientes).
        // El resto, configurable.
        $this->assertSame(['s01.identidad', 's02.seleccion', 's02.vinculos'], array_values(array_filter(array_keys($catalogo), ConfiguracionFuncionalidades::nucleo(...))));
        $this->assertSame(['adicional.referencias_pasivas'], array_keys(array_filter($catalogo, fn (array $d): bool => $d['bloqueo'] !== null)));
        foreach (array_keys($catalogo) as $codigo) {
            $esperado = ! in_array($codigo, ['s01.identidad', 's02.seleccion', 's02.vinculos', 'adicional.referencias_pasivas'], true);
            $this->assertSame($esperado, ConfiguracionFuncionalidades::configurable($codigo), $codigo);
            $this->assertSame($codigo === 'adicional.referencias_pasivas' ? null : config('funcionalidades.advertencia_normativa'),
                ConfiguracionFuncionalidades::advertencia($codigo), $codigo);
        }
        $this->expectException(InvalidArgumentException::class);
        ConfiguracionFuncionalidades::activa('s12.inventada');
    }

    public function test_existing_features_are_active_by_default_without_stored_rows(): void
    {
        $this->assertSame(0, ConfiguracionFuncionalidad::query()->count());
        foreach (ConfiguracionFuncionalidades::estados() as $codigo => $estado) {
            $this->assertTrue($estado['activa'], $codigo);
            $this->assertTrue($estado['disponible'], $codigo);
            $this->assertNull($estado['guardada'], $codigo);
        }
        $this->assertSame([], ConfiguracionFuncionalidades::estadoPublico()['menu_oculto']);
        $this->assertSame([], ConfiguracionFuncionalidades::estadoPublico()['secciones_ocultas']);
        $this->assertSame([], ConfiguracionFuncionalidades::estadoPublico()['tipos_inactivos']);
        // Una fila guardada no desactiva el núcleo.
        $this->inactiva('s01.identidad');
        $this->assertTrue(ConfiguracionFuncionalidades::activa('s01.identidad'));
        $this->assertFalse(ConfiguracionFuncionalidades::guardada('s01.identidad'));
    }

    public function test_saved_states_can_be_queried_and_every_change_is_audited(): void
    {
        $this->catalogoDePrueba();
        $actor = $this->actuarComo();

        $registro = ConfiguracionFuncionalidades::cambiar('prueba.libre', false, '  La institución no la usa  ', $actor);
        $this->assertFalse(ConfiguracionFuncionalidades::activa('prueba.libre'));
        $this->assertFalse(ConfiguracionFuncionalidades::guardada('prueba.libre'));
        $this->assertNull(ConfiguracionFuncionalidades::guardada('prueba.base'));
        $estados = ConfiguracionFuncionalidades::estados();
        $this->assertFalse($estados['prueba.libre']['activa']);
        $this->assertTrue($estados['prueba.base']['activa']);
        $this->assertDatabaseHas('configuracion_funcionalidades', ['id' => $registro->id, 'codigo' => 'prueba.libre', 'activa' => false,
            'motivo' => 'La institución no la usa', 'updated_by' => $actor->id]);

        $primero = AuditoriaEvento::query()->where('entidad', 'configuracion_funcionalidades')->sole();
        $this->assertSame(['funcionalidad.desactivar', $actor->id, $registro->id, null], [$primero->accion, $primero->actor_usuario_id,
            $primero->entidad_id, $primero->usuario_titular_id]);
        $this->assertNotNull($primero->fecha_evento);
        $this->assertSame(['codigo' => 'prueba.libre', 'activa' => true], json_decode($primero->datos_antes, true));
        $this->assertSame(['codigo' => 'prueba.libre', 'activa' => false, 'motivo' => 'La institución no la usa'], json_decode($primero->datos_despues, true));
        $this->assertNull($primero->hash_previo);

        // Reactivar actualiza la misma fila y encadena el evento.
        $this->assertSame($registro->id, ConfiguracionFuncionalidades::cambiar('prueba.libre', true, 'Vuelve a usarse', $actor)->id);
        $this->assertTrue(ConfiguracionFuncionalidades::activa('prueba.libre'));
        $this->assertSame(1, ConfiguracionFuncionalidad::query()->count());
        $segundo = AuditoriaEvento::query()->where('entidad', 'configuracion_funcionalidades')->where('accion', 'funcionalidad.activar')->sole();
        $this->assertSame($primero->hash_registro, $segundo->hash_previo);
        $this->assertSame(['codigo' => 'prueba.libre', 'activa' => false], json_decode($segundo->datos_antes, true));
    }

    public function test_reason_is_required_and_unchanged_state_is_rejected(): void
    {
        $this->catalogoDePrueba();
        $actor = $this->actuarComo();
        $this->assertArrayHasKey('motivo', $this->rechazo(fn () => ConfiguracionFuncionalidades::cambiar('prueba.libre', false, '   ', $actor)));
        $this->assertArrayHasKey('motivo', $this->rechazo(fn () => ConfiguracionFuncionalidades::cambiar('prueba.libre', false, str_repeat('x', 501), $actor)));
        $this->assertArrayHasKey('activa', $this->rechazo(fn () => ConfiguracionFuncionalidades::cambiar('prueba.libre', true, 'Ya activa', $actor)));
        $this->assertSame(0, ConfiguracionFuncionalidad::query()->count());
        $this->assertSame(0, AuditoriaEvento::query()->count());
    }

    public function test_core_blocked_and_depended_on_features_cannot_be_deactivated(): void
    {
        $actor = $this->actuarComo();
        // Núcleo y sección adicional (bloqueada): nunca.
        foreach (['s01.identidad', 's02.seleccion', 's02.vinculos', 'adicional.referencias_pasivas'] as $codigo) {
            $this->assertNotEmpty($this->rechazo(fn () => ConfiguracionFuncionalidades::cambiar($codigo, false, 'Intento', $actor))['activa'], $codigo);
            $this->assertTrue(ConfiguracionFuncionalidades::activa($codigo), $codigo);
        }
        $this->assertStringContainsString('Es esencial', ConfiguracionFuncionalidades::bloqueosParaDesactivar('s02.vinculos')[0]);
        // Dependencia real: 7.1 no se desactiva mientras la puntuación (que depende de ella) esté activa.
        $this->assertSame(['La usa «Puntuación numérica de las evaluaciones», que está activa.'],
            $this->rechazo(fn () => ConfiguracionFuncionalidades::cambiar('s07.evaluaciones', false, 'Intento', $actor))['activa']);
        $this->assertSame(0, ConfiguracionFuncionalidad::query()->count());
        $this->assertSame(0, AuditoriaEvento::query()->count());

        // Esencial con dependencia permanente: nunca se desactiva.
        $this->catalogoDePrueba(['esencial' => ['motivo' => 'Prueba', 'permanente' => ['Siempre vigente']]]);
        $this->assertFalse(ConfiguracionFuncionalidades::configurable('prueba.base'));
        $this->assertSame(['Es esencial (Prueba) y sigue vigente: Siempre vigente', 'Es esencial (Prueba) y la usa «Uso de la base», que está activa.'],
            ConfiguracionFuncionalidades::bloqueosParaDesactivar('prueba.base'));

        // Esencial solo por otra funcionalidad: se desactiva cuando esa dependencia deja de estar vigente.
        $this->catalogoDePrueba(['esencial' => ['motivo' => 'Prueba', 'permanente' => []]]);
        $errores = $this->rechazo(fn () => ConfiguracionFuncionalidades::cambiar('prueba.base', false, 'Intento', $actor));
        $this->assertSame(['Es esencial (Prueba) y la usa «Uso de la base», que está activa.'], $errores['activa']);
        ConfiguracionFuncionalidades::cambiar('prueba.uso', false, 'Ya no se usa', $actor);
        ConfiguracionFuncionalidades::cambiar('prueba.base', false, 'Ya nada la usa', $actor);
        $this->assertFalse(ConfiguracionFuncionalidades::activa('prueba.base'));

        // No se activa lo que depende de algo inactivo.
        $errores = $this->rechazo(fn () => ConfiguracionFuncionalidades::cambiar('prueba.uso', true, 'Intento', $actor));
        $this->assertSame(['Necesita «Base», que está inactiva.'], $errores['activa']);
        ConfiguracionFuncionalidades::cambiar('prueba.base', true, 'Se retoma', $actor);
        ConfiguracionFuncionalidades::cambiar('prueba.uso', true, 'Se retoma', $actor);
        $this->assertTrue(ConfiguracionFuncionalidades::activa('prueba.uso'));
        $this->assertSame(4, AuditoriaEvento::query()->where('entidad', 'configuracion_funcionalidades')->count());
    }

    public function test_sections_hide_only_when_no_active_feature_with_menu_remains(): void
    {
        $actor = $this->actuarComo();
        // 06: una sola funcionalidad; al desactivarla se oculta la sección entera.
        $this->assertTrue(ConfiguracionFuncionalidades::estados()['s06.compensaciones']['oculta_seccion']);
        ConfiguracionFuncionalidades::cambiar('s06.compensaciones', false, 'La universidad no la usa', $actor);
        $this->assertTrue(ConfiguracionFuncionalidades::seccionOculta(6));

        // 09: sin controversias queda la afiliación (y «Otros documentos»).
        $this->assertFalse(ConfiguracionFuncionalidades::estados()['s09.controversias']['oculta_seccion']);
        ConfiguracionFuncionalidades::cambiar('s09.controversias', false, 'Solo afiliación sindical', $actor);
        $this->assertFalse(ConfiguracionFuncionalidades::seccionOculta(9));

        // 07: sin 7.2 ni puntuación, la sección sigue con 7.1 y 7.3.
        ConfiguracionFuncionalidades::cambiar('s07.progresion', false, 'No se usa', $actor);
        ConfiguracionFuncionalidades::cambiar('s07.evaluaciones.puntaje', false, 'Sin puntuación numérica', $actor);
        $this->assertFalse(ConfiguracionFuncionalidades::seccionOculta(7));

        $publico = ConfiguracionFuncionalidades::estadoPublico();
        $this->assertSame([6], $publico['secciones_ocultas']);
        $this->assertEqualsCanonicalizing(['seccion-06', 'compensaciones-beneficios', 'documentos-06', 's09-individuales', 's09-colectivas', 's07-progresion'],
            $publico['menu_oculto']);
        $tipos06 = TipoDocumento::query()->where('seccion_legajo_id', 6)->where('activo', true)->pluck('codigo')->all();
        $this->assertEqualsCanonicalizing([...$tipos06, 'S09_A_CONTROVERSIAS_INDIVIDUALES', 'S09_B_CONTROVERSIAS_COLECTIVAS', 'S07_72A_RESOLUCION_PROGRESION'],
            $publico['tipos_inactivos']);
        $this->assertFalse($publico['activas']['s07.evaluaciones.puntaje']);
        $this->assertTrue($publico['activas']['s09.afiliaciones']);
        // «Otros documentos» de la 09 sigue activo.
        $this->assertTrue(ConfiguracionFuncionalidades::tipoActivo('S09_OTROS_UNIVERSIDAD', 9));
    }

    public function test_inactive_document_types_are_not_required(): void
    {
        $actor = $this->actuarComo();
        $tipo = TipoDocumento::query()->where('codigo', 'S02_H_REGISTRO_INDUCCION')->where('activo', true)->firstOrFail();
        $this->assertTrue($tipo->obligatorio);
        $perfil = PerfilDocumental::de(Usuario::factory()->create(), collect());
        $this->assertNotSame('No se exige: desactivado en la configuración de la institución.', $perfil->exigencia($tipo)['motivo']);

        ConfiguracionFuncionalidades::cambiar('s02.inducciones', false, 'La institución no registra inducciones', $actor);
        $this->assertSame(['exigido' => false, 'motivo' => 'No se exige: desactivado en la configuración de la institución.'], $perfil->exigencia($tipo));
        // Otros tipos de la misma sección se siguen exigiendo con sus reglas.
        $seleccion = TipoDocumento::query()->where('codigo', 'S02_A_RESULTADO_SELECCION')->where('activo', true)->firstOrFail();
        $this->assertNotSame('No se exige: desactivado en la configuración de la institución.', $perfil->exigencia($seleccion)['motivo']);
    }

    public function test_score_option_can_be_turned_off_without_touching_evaluations_or_stored_scores(): void
    {
        $actor = $this->actuarComo();
        $evaluacion = EvaluacionDesempeno::factory()->create(['calificacion' => 'Buen rendimiento', 'puntaje' => 15.5]);
        $filaEvaluacion = fn (): array => EvaluacionDesempeno::query()->findOrFail($evaluacion->id)->toArray();
        $tipos71 = fn (): array => TipoDocumento::query()->whereIn('codigo', ['S07_71A_GESTION_RENDIMIENTO', 'S07_71B_ACTA_RETROALIMENTACION'])
            ->where('activo', true)->orderBy('codigo')->get(['codigo', 'obligatorio', 'activo'])->toArray();
        $antes = [$filaEvaluacion(), $tipos71()];
        $this->assertSame([true, true], array_column($antes[1], 'obligatorio'));

        $this->assertTrue(ConfiguracionFuncionalidades::activa('s07.evaluaciones.puntaje'));
        ConfiguracionFuncionalidades::cambiar('s07.evaluaciones.puntaje', false, 'La universidad no usa puntuación numérica', $actor);
        $this->assertFalse(ConfiguracionFuncionalidades::activa('s07.evaluaciones.puntaje'));
        foreach (['s07.evaluaciones', 's07.progresion', 's07.desplazamientos'] as $codigo) {
            $this->assertTrue(ConfiguracionFuncionalidades::activa($codigo), $codigo);
        }
        // Puntajes, calificación y obligatoriedad de 7.1 a) y b) intactos; la opción no tiene menú ni tipos propios.
        $this->assertSame($antes, [$filaEvaluacion(), $tipos71()]);
        $this->assertSame('15.50', $filaEvaluacion()['puntaje']);
        $this->assertSame([], ConfiguracionFuncionalidades::estadoPublico()['menu_oculto']);
        $this->assertSame(1, ConfiguracionFuncionalidades::registros('s07.evaluaciones.puntaje'));

        ConfiguracionFuncionalidades::cambiar('s07.evaluaciones.puntaje', true, 'Se incorpora la puntuación', $actor);
        $this->assertTrue(ConfiguracionFuncionalidades::activa('s07.evaluaciones.puntaje'));
        $this->assertSame($antes, [$filaEvaluacion(), $tipos71()]);
    }

    public function test_score_option_cannot_be_reactivated_while_evaluations_are_inactive(): void
    {
        $actor = $this->actuarComo();
        ConfiguracionFuncionalidades::cambiar('s07.evaluaciones.puntaje', false, 'Sin puntuación', $actor);
        ConfiguracionFuncionalidades::cambiar('s07.evaluaciones', false, 'Sin evaluaciones', $actor);
        $this->assertFalse(ConfiguracionFuncionalidades::activa('s07.evaluaciones'));
        $this->assertSame(['Necesita «7.1 Evaluación de desempeño», que está inactiva.'],
            $this->rechazo(fn () => ConfiguracionFuncionalidades::cambiar('s07.evaluaciones.puntaje', true, 'Intento', $actor))['activa']);
        foreach (['s07.progresion', 's07.desplazamientos'] as $codigo) {
            $this->assertTrue(ConfiguracionFuncionalidades::activa($codigo), $codigo);
        }
    }

    public function test_configuration_changes_keep_permissions_and_existing_data(): void
    {
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $actor = $this->actuarComo();
        $permisos = $actor->permisos();
        $legajo = Legajo::factory()->create();
        LegajoSeccion::factory()->create(['legajo_id' => $legajo->id, 'seccion_legajo_id' => 6, 'ultimo_folio' => 7]);
        $documento = DocumentoUsuario::factory()->create(['usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id,
            'tipo_documento_id' => TipoDocumento::query()->where('seccion_legajo_id', 6)->where('activo', true)->value('id'), 'seccion_legajo_id' => 6,
            'estado' => 'cargado', 'archivo_id' => null, 'folio_inicio' => 1, 'folio_fin' => 7, 'created_by' => $actor->id]);
        $referencia = ReferenciaLegajoPasivo::factory()->create(['legajo_id' => $legajo->id]);
        $foto = fn (): array => [
            LegajoSeccion::query()->orderBy('id')->get()->toArray(), DocumentoUsuario::query()->orderBy('id')->get()->toArray(),
            ReferenciaLegajoPasivo::query()->orderBy('id')->get()->toArray(), Legajo::query()->orderBy('id')->get()->toArray(),
        ];
        $antes = $foto();

        // Desactivar la 06 entera no toca sus documentos ni sus folios; los cuenta para avisar.
        $this->assertSame(1, ConfiguracionFuncionalidades::registros('s06.compensaciones'));
        ConfiguracionFuncionalidades::cambiar('s06.compensaciones', false, 'Prueba de conservación', $actor);
        ConfiguracionFuncionalidades::cambiar('s06.compensaciones', true, 'Prueba de conservación', $actor);

        $this->assertSame($antes, $foto());
        $this->assertDatabaseHas('documentos_usuario', ['id' => $documento->id, 'folio_inicio' => 1, 'folio_fin' => 7]);
        $this->assertDatabaseHas('referencias_legajo_pasivo', ['id' => $referencia->id]);
        $this->assertSame($permisos, Usuario::query()->findOrFail($actor->id)->permisos());
        $this->assertTrue($actor->fresh()->can('legajo.ver'));
    }
}
