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
use Database\Seeders\Seccion07CatalogoSeeder;
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

    /** Catálogo de prueba para ejercitar el mecanismo: el real no deja desactivar nada todavía. */
    private function catalogoDePrueba(array $base = []): void
    {
        $definicion = fn (string $nombre, array $extra = []): array => $extra + ['nombre' => $nombre, 'seccion' => 5, 'implementada' => true,
            'esencial' => null, 'depende_de' => [], 'bloqueo' => null, 'rutas_escritura' => []];
        config()->set('funcionalidades.funcionalidades', [
            'prueba.base' => $definicion('Base', $base),
            'prueba.uso' => $definicion('Uso de la base', ['depende_de' => ['prueba.base']]),
            'prueba.libre' => $definicion('Libre'),
        ]);
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
        $this->assertSame(range(1, 13), array_keys($secciones));
        $this->assertSame(array_keys(SeccionesLegajoCatalogoSeeder::SECCIONES), array_keys($secciones));
        foreach (range(1, 12) as $seccion) {
            $this->assertSame('normativa', $secciones[$seccion]['caracter']);
        }
        $this->assertSame('adicional', $secciones[13]['caracter']);
        $this->assertFalse($secciones[12]['implementada']);

        // Toda sección implementada tiene al menos una funcionalidad; la 12 no tiene ninguna.
        $porSeccion = array_count_values(array_column($catalogo, 'seccion'));
        $this->assertArrayNotHasKey(12, $porSeccion);
        foreach ($secciones as $id => $seccion) {
            $this->assertSame($seccion['implementada'], isset($porSeccion[$id]), "Sección {$id}");
        }

        // Cada ruta declarada existe y cada dependencia es una funcionalidad del catálogo.
        $rutas = collect(Route::getRoutes()->getRoutes())->flatMap(fn ($ruta) => array_map(fn (string $metodo): string => $metodo.' '.$ruta->uri(),
            array_diff($ruta->methods(), ['HEAD'])))->all();
        foreach ($catalogo as $codigo => $definicion) {
            $this->assertSame(['nombre', 'seccion', 'implementada', 'esencial', 'depende_de', 'bloqueo', 'rutas_escritura'],
                array_keys(array_diff_key($definicion, ['campos' => true])), $codigo);
            $this->assertTrue($definicion['implementada'], $codigo);
            foreach ($definicion['rutas_escritura'] as $ruta) {
                $this->assertContains($ruta, $rutas, "{$codigo}: {$ruta}");
            }
            // Una opción sobre campos no tiene rutas propias, depende de otra funcionalidad y sus columnas existen.
            if (isset($definicion['campos'])) {
                $this->assertSame([], $definicion['rutas_escritura'], $codigo);
                $this->assertNotSame([], $definicion['depende_de'], $codigo);
                foreach ($definicion['campos'] as $campo) {
                    [$tabla, $columna] = explode('.', $campo);
                    $this->assertTrue(Schema::hasColumn($tabla, $columna), "{$codigo}: {$campo}");
                }
            }
            foreach ($definicion['depende_de'] as $dependencia) {
                $this->assertArrayHasKey($dependencia, $catalogo, $codigo);
            }
            if ($definicion['bloqueo']) {
                $this->assertContains($definicion['bloqueo']['tipo'], ['sin_alternativa', 'pendiente_universidad', 'pendiente_equipo'], $codigo);
            }
        }

        // Esenciales (PROVISIONAL) y funcionalidades sin alternativa (la subida común rechaza sus documentos).
        $this->assertSame(['s01.identidad', 's02.vinculos'], array_keys(array_filter($catalogo, fn (array $d): bool => $d['esencial'] !== null)));
        $this->assertSame(['s04.experiencia', 's06.compensaciones', 's10.sst_bienestar'],
            array_keys(array_filter($catalogo, fn (array $d): bool => ($d['bloqueo']['tipo'] ?? null) === 'sin_alternativa')));

        // Solo la puntuación numérica de las evaluaciones se puede desactivar; el resto sigue bloqueado.
        $this->assertSame(['s07.evaluaciones.puntaje'], array_keys(array_filter($catalogo, fn (array $d): bool => isset($d['campos']))));
        foreach (array_keys($catalogo) as $codigo) {
            $desactivable = $codigo === 's07.evaluaciones.puntaje';
            $this->assertSame($desactivable, ConfiguracionFuncionalidades::configurable($codigo), $codigo);
            $this->assertSame($desactivable, ConfiguracionFuncionalidades::bloqueosParaDesactivar($codigo) === [], $codigo);
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
        // Una fila guardada no desactiva lo que el catálogo no permite desactivar.
        ConfiguracionFuncionalidad::query()->create(['codigo' => 's04.experiencia', 'activa' => false, 'motivo' => 'Fila antigua',
            'updated_by' => Usuario::factory()->create()->id]);
        $this->assertTrue(ConfiguracionFuncionalidades::activa('s04.experiencia'));
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

    public function test_essential_blocked_and_depended_on_features_cannot_be_deactivated(): void
    {
        $actor = $this->actuarComo();
        // Catálogo real: esenciales con dependencias permanentes, sin alternativa y pendientes.
        foreach (['s01.identidad', 's02.vinculos', 's04.experiencia', 's10.sst_bienestar', 's01.familiares', 's03.formacion', 'adicional.referencias_pasivas'] as $codigo) {
            $errores = $this->rechazo(fn () => ConfiguracionFuncionalidades::cambiar($codigo, false, 'Intento', $actor));
            $this->assertNotEmpty($errores['activa'], $codigo);
            $this->assertTrue(ConfiguracionFuncionalidades::activa($codigo), $codigo);
        }
        $this->assertStringContainsString('Es esencial', ConfiguracionFuncionalidades::bloqueosParaDesactivar('s02.vinculos')[0]);
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

    public function test_score_option_can_be_turned_off_without_touching_evaluations_or_stored_scores(): void
    {
        $this->seed(Seccion07CatalogoSeeder::class);
        $actor = $this->actuarComo();
        $evaluacion = EvaluacionDesempeno::factory()->create(['calificacion' => 'Buen rendimiento', 'puntaje' => 15.5]);
        $filaEvaluacion = fn (): array => EvaluacionDesempeno::query()->findOrFail($evaluacion->id)->toArray();
        $tipos71 = fn (): array => TipoDocumento::query()->whereIn('codigo', ['S07_71A_GESTION_RENDIMIENTO', 'S07_71B_ACTA_RETROALIMENTACION'])
            ->orderBy('codigo')->get(['codigo', 'obligatorio', 'activo'])->toArray();
        $antes = [$filaEvaluacion(), $tipos71()];
        $this->assertSame([true, true], array_column($antes[1], 'obligatorio'));
        $resto07 = ['s07.evaluaciones', 's07.progresiones', 's07.ratificaciones', 's07.desplazamientos'];

        // Por defecto activa; las evaluaciones no se pueden desactivar mientras la usen.
        $this->assertTrue(ConfiguracionFuncionalidades::activa('s07.evaluaciones.puntaje'));
        $this->assertContains('La usa «Puntuación numérica de las evaluaciones», que está activa.',
            ConfiguracionFuncionalidades::bloqueosParaDesactivar('s07.evaluaciones'));

        ConfiguracionFuncionalidades::cambiar('s07.evaluaciones.puntaje', false, 'La universidad no usa puntuación numérica', $actor);
        $this->assertFalse(ConfiguracionFuncionalidades::activa('s07.evaluaciones.puntaje'));
        foreach ($resto07 as $codigo) {
            $this->assertTrue(ConfiguracionFuncionalidades::activa($codigo), $codigo);
        }
        // Las evaluaciones siguen sin poder desactivarse por su propio bloqueo, ya no por la puntuación.
        $this->assertNotContains('La usa «Puntuación numérica de las evaluaciones», que está activa.',
            ConfiguracionFuncionalidades::bloqueosParaDesactivar('s07.evaluaciones'));
        $this->assertNotEmpty($this->rechazo(fn () => ConfiguracionFuncionalidades::cambiar('s07.evaluaciones', false, 'Intento', $actor))['activa']);
        // Puntajes, calificación y obligatoriedad de 7.1 a) y b) intactos.
        $this->assertSame($antes, [$filaEvaluacion(), $tipos71()]);
        $this->assertSame('15.50', $filaEvaluacion()['puntaje']);

        ConfiguracionFuncionalidades::cambiar('s07.evaluaciones.puntaje', true, 'Se incorpora la puntuación', $actor);
        $this->assertTrue(ConfiguracionFuncionalidades::activa('s07.evaluaciones.puntaje'));
        $this->assertSame($antes, [$filaEvaluacion(), $tipos71()]);
        $this->assertSame(['funcionalidad.desactivar', 'funcionalidad.activar'], AuditoriaEvento::query()
            ->where('entidad', 'configuracion_funcionalidades')->orderBy('id')->pluck('accion')->all());
    }

    public function test_score_option_cannot_be_reactivated_while_evaluations_are_inactive(): void
    {
        // Supuesto de prueba: las evaluaciones fueran desactivables (en el catálogo real no lo son).
        $catalogo = ConfiguracionFuncionalidades::catalogo();
        $catalogo['s07.evaluaciones']['bloqueo'] = null;
        config()->set('funcionalidades.funcionalidades', $catalogo);
        $actor = $this->actuarComo();

        ConfiguracionFuncionalidades::cambiar('s07.evaluaciones.puntaje', false, 'Sin puntuación', $actor);
        ConfiguracionFuncionalidades::cambiar('s07.evaluaciones', false, 'Supuesto de prueba', $actor);
        $this->assertFalse(ConfiguracionFuncionalidades::activa('s07.evaluaciones'));
        $this->assertSame(['Necesita «Evaluaciones de desempeño», que está inactiva.'],
            $this->rechazo(fn () => ConfiguracionFuncionalidades::cambiar('s07.evaluaciones.puntaje', true, 'Intento', $actor))['activa']);
        // Las demás funcionalidades de la 07 no dependen de las evaluaciones.
        foreach (['s07.progresiones', 's07.ratificaciones', 's07.desplazamientos'] as $codigo) {
            $this->assertTrue(ConfiguracionFuncionalidades::activa($codigo), $codigo);
        }
    }

    public function test_configuration_changes_keep_permissions_and_existing_data(): void
    {
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $actor = $this->actuarComo();
        $permisos = $actor->permisos();
        $legajo = Legajo::factory()->create();
        LegajoSeccion::factory()->create(['legajo_id' => $legajo->id, 'seccion_legajo_id' => 5, 'ultimo_folio' => 7]);
        $documento = DocumentoUsuario::factory()->create(['usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id,
            'tipo_documento_id' => TipoDocumento::factory()->create(['seccion_legajo_id' => 5])->id, 'seccion_legajo_id' => 5,
            'estado' => 'cargado', 'archivo_id' => null, 'folio_inicio' => 1, 'folio_fin' => 7, 'created_by' => $actor->id]);
        $referencia = ReferenciaLegajoPasivo::factory()->create(['legajo_id' => $legajo->id]);
        $foto = fn (): array => [
            LegajoSeccion::query()->orderBy('id')->get()->toArray(), DocumentoUsuario::query()->orderBy('id')->get()->toArray(),
            ReferenciaLegajoPasivo::query()->orderBy('id')->get()->toArray(), Legajo::query()->orderBy('id')->get()->toArray(),
        ];
        $antes = $foto();

        $this->catalogoDePrueba();
        ConfiguracionFuncionalidades::cambiar('prueba.libre', false, 'Prueba de conservación', $actor);
        ConfiguracionFuncionalidades::cambiar('prueba.libre', true, 'Prueba de conservación', $actor);

        $this->assertSame($antes, $foto());
        $this->assertDatabaseHas('documentos_usuario', ['id' => $documento->id, 'folio_inicio' => 1, 'folio_fin' => 7]);
        $this->assertDatabaseHas('referencias_legajo_pasivo', ['id' => $referencia->id]);
        $this->assertSame($permisos, Usuario::query()->findOrFail($actor->id)->permisos());
        $this->assertTrue($actor->fresh()->can('legajo.ver'));
    }
}
