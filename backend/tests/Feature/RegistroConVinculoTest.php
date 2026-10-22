<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\PerfilAdministrativo;
use App\Models\PerfilDocente;
use App\Models\RegimenNormativo;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Registro manual: la persona con su primer vínculo (obligatorio), en una sola transacción (persona, legajo, vínculo y
 * datos del puesto), con las reglas del alta y de la sección 02 y sus mismos permisos. La importación no cambia.
 */
class RegistroConVinculoTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/usuarios/con-vinculo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
    }

    private function ley(string $codigo): string
    {
        return RegimenNormativo::query()->where('codigo', $codigo)->value('id');
    }

    private function datos(string $tipo = 'docente', array $persona = [], array $vinculo = [], ?array $puesto = null): array
    {
        return [
            'usuarios' => [[
                'numero_doc_identidad' => '71000001', 'nombres' => 'Rosa', 'apellido_paterno' => 'Alta', 'apellido_materno' => 'Vínculo',
                'email_institucional' => 'rosa.alta@example.test', 'tipo_personal' => $tipo, 'regimen_normativo_id' => $this->ley($tipo === 'docente' ? '30220' : '1057'),
                ...$persona,
            ]],
            'vinculo' => ['regimen_laboral' => $tipo === 'docente' ? 'carrera' : 'plazo_determinado', 'nombre_puesto' => $tipo === 'docente' ? 'Docente del departamento' : 'Asistente administrativo',
                'unidad_organica_id' => UnidadOrganica::factory()->create(['nombre' => 'Unidad de prueba'])->id, 'fecha_inicio' => '2026-03-01',
                'fecha_fin_prevista' => $tipo === 'docente' ? null : '2026-12-31', 'numero_documento_vinculo' => 'RES-123-2026', 'tiene_periodo_prueba' => true, ...$vinculo],
            'datos_puesto' => $puesto ?? ($tipo === 'docente'
                ? ['condicion_docente' => 'ordinario', 'categoria' => 'asociado', 'dedicacion' => 'tiempo_completo']
                : ['grupo_ocupacional' => 'tecnico', 'nivel_carrera' => 'STA', 'cargo_estructural' => 'Asistente', 'es_cargo_confianza' => false]),
        ];
    }

    private function nadaCreado(): void
    {
        $this->assertNull(Usuario::query()->where('numero_doc_identidad', '71000001')->first());
        $this->assertSame([0, 0, 0, 0, 0], [Legajo::query()->count(), LegajoSeccion::query()->count(), VinculoLaboral::query()->count(),
            PerfilDocente::query()->count(), PerfilAdministrativo::query()->count()]);
    }

    public function test_a_teacher_is_registered_with_open_file_link_and_job_data(): void
    {
        $actor = $this->actuarComo();
        $datos = $this->datos();
        $respuesta = $this->postJson(self::URL, $datos)->assertCreated()
            ->assertJsonPath('data.usuario.numero_doc_identidad', '71000001')->assertJsonPath('data.usuario.ley.codigo', '30220')
            ->assertJsonPath('data.legajo.estado_legajo', 'activo')->assertJsonPath('data.vinculo.regimen_laboral', 'carrera');

        $persona = Usuario::query()->where('numero_doc_identidad', '71000001')->sole();
        // La persona guarda la ley; el puesto y la unidad van en el vínculo, no en la persona.
        $this->assertSame([$this->ley('30220'), '30220', 'docente', null, null, false], [$persona->regimen_normativo_id, $persona->regimen_laboral,
            $persona->tipo_personal, $persona->nombre_puesto, $persona->unidad_organica_id, (bool) $persona->activo]);
        $legajo = Legajo::query()->where('usuario_id', $persona->id)->sole();
        $this->assertSame([$respuesta->json('data.legajo.id'), 'activo', 12], [$legajo->id, $legajo->estado_legajo, $legajo->legajoSecciones()->count()]);

        $vinculo = VinculoLaboral::query()->where('usuario_id', $persona->id)->sole();
        $this->assertSame(['docente', $this->ley('30220'), 'carrera', 'Docente del departamento', $datos['vinculo']['unidad_organica_id'], '2026-03-01', null, 'RES-123-2026', true, null, $actor->id],
            [$vinculo->tipo_personal, $vinculo->regimen_normativo_id, $vinculo->regimen_laboral, $vinculo->nombre_puesto, $vinculo->unidad_organica_id,
                $vinculo->fecha_inicio->format('Y-m-d'), $vinculo->fecha_cese, $vinculo->numero_documento_vinculo, (bool) $vinculo->tiene_periodo_prueba, $vinculo->obligado_djbr, $vinculo->registrado_por]);
        $perfil = PerfilDocente::query()->where('vinculo_laboral_id', $vinculo->id)->sole();
        $this->assertSame(['ordinario', 'asociado', 'tiempo_completo'], [$perfil->condicion_docente, $perfil->categoria, $perfil->dedicacion]);
        $this->assertSame(0, PerfilAdministrativo::query()->count());

        // La ficha y el directorio muestran el puesto y la unidad del vínculo vigente; 2.2 lo ve como vigente.
        $this->getJson('/api/v1/expedientes/'.$persona->id)->assertOk()->assertJsonPath('data.puesto_actual.origen', 'vinculo')
            ->assertJsonPath('data.puesto_actual.nombre_puesto', 'Docente del departamento')->assertJsonPath('data.puesto_actual.unidad_organica', 'Unidad de prueba')
            ->assertJsonPath('data.regimen.tipos_vinculo', ['carrera']);
        $fila = collect($this->getJson('/api/v1/expedientes?q=71000001')->assertOk()->json('data'))->sole();
        $this->assertSame(['vinculo', 'Docente del departamento'], [$fila['puesto_actual']['origen'], $fila['puesto_actual']['nombre_puesto']]);
        $this->assertArrayNotHasKey('vinculos_laborales', $fila);
        $this->getJson('/api/v1/expedientes/'.$persona->id.'/seccion-02')->assertOk()->assertJsonPath('data.vinculos.0.datos_puesto.0.values.categoria', 'asociado');
    }

    public function test_an_administrative_person_gets_the_administrative_job_data(): void
    {
        $this->actuarComo();
        $this->postJson(self::URL, $this->datos('administrativo'))->assertCreated()->assertJsonPath('data.usuario.ley.codigo', '1057');
        $vinculo = VinculoLaboral::query()->sole();
        $this->assertSame(['administrativo', 'plazo_determinado', '2026-12-31'], [$vinculo->tipo_personal, $vinculo->regimen_laboral, $vinculo->fecha_fin_prevista->format('Y-m-d')]);
        $perfil = PerfilAdministrativo::query()->where('vinculo_laboral_id', $vinculo->id)->sole();
        $this->assertSame(['tecnico', 'STA', 'Asistente', false], [$perfil->grupo_ocupacional, $perfil->nivel_carrera, $perfil->cargo_estructural, (bool) $perfil->es_cargo_confianza]);
        $this->assertSame(0, PerfilDocente::query()->count());
    }

    public function test_it_uses_the_same_rules_as_the_registration_and_section_02(): void
    {
        $this->actuarComo();
        // Persona (alta), vínculo y datos del puesto (sección 02): todos los errores a la vez, con su prefijo.
        $datos = $this->datos('docente', ['email_institucional' => 'no-es-correo', 'regimen_normativo_id' => $this->ley('1057')],
            ['nombre_puesto' => '', 'regimen_laboral' => '276', 'fecha_fin_prevista' => '2020-01-01'], ['condicion_docente' => 'contratado', 'categoria' => 'principal']);
        $this->postJson(self::URL, $datos)->assertUnprocessable()->assertJsonValidationErrors([
            'usuarios.0.email_institucional', 'usuarios.0.regimen_normativo_id', 'vinculo.nombre_puesto', 'vinculo.regimen_laboral', 'vinculo.fecha_fin_prevista', 'datos_puesto.categoria',
        ]);
        // El tipo de personal y la ley del vínculo salen de la persona; el primer vínculo es el vigente.
        $this->postJson(self::URL, $this->datos('docente', [], ['tipo_personal' => 'administrativo', 'regimen_normativo_id' => $this->ley('276'), 'fecha_cese' => '2026-05-01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['vinculo.tipo_personal', 'vinculo.regimen_normativo_id', 'vinculo.fecha_cese']);
        // Los datos del puesto son los del tipo de personal; vínculo y datos del puesto son obligatorios.
        $this->postJson(self::URL, $this->datos('docente', [], [], ['grupo_ocupacional' => 'tecnico']))->assertUnprocessable()
            ->assertJsonValidationErrors(['datos_puesto.grupo_ocupacional', 'datos_puesto.condicion_docente']);
        $this->postJson(self::URL, ['usuarios' => $this->datos()['usuarios']])->assertUnprocessable()->assertJsonValidationErrors(['vinculo', 'datos_puesto']);
        $this->postJson(self::URL, [...$this->datos(), 'legajo' => ['numero_legajo' => 'X']])->assertUnprocessable()->assertJsonValidationErrors('legajo');
        $dos = $this->datos();
        $dos['usuarios'][] = [...$dos['usuarios'][0], 'numero_doc_identidad' => '71000002', 'email_institucional' => 'otra@example.test'];
        $this->postJson(self::URL, $dos)->assertUnprocessable()->assertJsonValidationErrors('usuarios');
        $this->nadaCreado();

        // DNI y correo ya registrados: mismas reglas del alta.
        Usuario::factory()->create(['numero_doc_identidad' => '71000009', 'email_institucional' => 'ya@example.test']);
        $this->postJson(self::URL, $this->datos('docente', ['numero_doc_identidad' => '71000009']))->assertUnprocessable()->assertJsonValidationErrors('usuarios.0.numero_doc_identidad');
        $this->postJson(self::URL, $this->datos('docente', ['email_institucional' => 'YA@example.test']))->assertUnprocessable()->assertJsonValidationErrors('usuarios.0.email_institucional');
    }

    public function test_a_failure_in_any_step_leaves_nothing_behind(): void
    {
        $this->actuarComo();
        $auditoria = AuditoriaEvento::query()->count();
        VinculoLaboral::creating(fn () => throw new RuntimeException('falla simulada al crear el vínculo'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson(self::URL, $this->datos('docente', ['con_discapacidad' => true]));
            $this->fail('Debía fallar.');
        } catch (RuntimeException $error) {
            $this->assertSame('falla simulada al crear el vínculo', $error->getMessage());
        }
        $this->nadaCreado();
        $this->assertSame($auditoria, AuditoriaEvento::query()->count());
    }

    public function test_a_failure_saving_the_job_data_also_rolls_everything_back(): void
    {
        $this->actuarComo();
        PerfilDocente::creating(fn () => throw new RuntimeException('falla simulada en los datos del puesto'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson(self::URL, $this->datos());
            $this->fail('Debía fallar.');
        } catch (RuntimeException) {
        }
        $this->nadaCreado();
    }

    public function test_it_needs_the_permissions_of_every_step(): void
    {
        foreach (['SIN_ALTA' => ['personal.ver', 'legajo.ver', 'legajo.abrir', 'legajo.editar'],
            'SIN_APERTURA' => ['usuarios.gestionar', 'legajo.ver', 'legajo.editar'],
            'SIN_VINCULO' => ['usuarios.gestionar', 'legajo.ver', 'legajo.abrir']] as $rol => $permisos) {
            config(["permisos.roles.$rol" => $permisos]);
            $this->actuarComo(null, $rol);
            $this->postJson(self::URL, $this->datos())->assertForbidden();
        }
        // Discapacidad: mismo permiso que el alta (discapacidad.editar).
        config(['permisos.roles.SIN_DISCAPACIDAD' => ['usuarios.gestionar', 'legajo.ver', 'legajo.abrir', 'legajo.editar']]);
        $this->actuarComo(null, 'SIN_DISCAPACIDAD');
        $this->postJson(self::URL, $this->datos('docente', ['con_discapacidad' => true]))->assertForbidden();
        $this->postJson(self::URL, $this->datos())->assertCreated();
    }

    public function test_the_bulk_import_still_registers_people_without_a_link(): void
    {
        $this->actuarComo();
        $respuesta = $this->postJson('/api/v1/usuarios', ['usuarios' => [
            ['numero_doc_identidad' => '72000001', 'nombres' => 'Lote', 'apellido_paterno' => 'Uno', 'email_institucional' => 'lote1@example.test', 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'cas'],
            ['numero_doc_identidad' => '72000002', 'nombres' => 'Lote', 'apellido_paterno' => 'Dos', 'email_institucional' => 'lote2@example.test', 'tipo_personal' => 'docente', 'regimen_normativo_id' => $this->ley('30220')],
        ]])->assertCreated()->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'numero_doc_identidad', 'tipo_personal', 'regimen_laboral', 'regimen_normativo_id', 'ley', 'activo']]]);
        $this->assertSame(['72000001' => '1057', '72000002' => '30220'], collect($respuesta->json('data'))->sortBy('numero_doc_identidad')
            ->mapWithKeys(fn (array $fila): array => [$fila['numero_doc_identidad'] => $fila['ley']['codigo']])->all());
        $this->assertSame([2, 0, 0], [Usuario::query()->whereIn('numero_doc_identidad', ['72000001', '72000002'])->count(), Legajo::query()->count(), VinculoLaboral::query()->count()]);
        // Sigue sin aceptar los datos del vínculo.
        $this->postJson('/api/v1/usuarios', ['usuarios' => [['numero_doc_identidad' => '72000003', 'nombres' => 'Lote', 'apellido_paterno' => 'Tres', 'email_institucional' => 'lote3@example.test',
            'tipo_personal' => 'docente', 'regimen_normativo_id' => $this->ley('30220'), 'nombre_puesto' => 'Docente']]])->assertUnprocessable()->assertJsonValidationErrors('usuarios.0');
    }

    public function test_existing_people_keep_their_stored_job_as_history(): void
    {
        $this->actuarComo();
        $unidad = UnidadOrganica::factory()->create(['nombre' => 'Unidad anterior']);
        $persona = Usuario::factory()->create(['tipo_personal' => 'administrativo', 'nombre_puesto' => 'Puesto anterior', 'unidad_organica_id' => $unidad->id]);
        Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo']);
        $url = '/api/v1/expedientes/'.$persona->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.puesto_actual', ['nombre_puesto' => 'Puesto anterior', 'unidad_organica' => 'Unidad anterior', 'origen' => 'persona', 'vinculos_vigentes' => 0, 'situacion' => null]);

        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'nombre_puesto' => 'Puesto vigente', 'unidad_organica_id' => null, 'fecha_inicio' => '2026-01-01', 'fecha_cese' => null, 'anulado' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.puesto_actual.origen', 'vinculo')->assertJsonPath('data.puesto_actual.nombre_puesto', 'Puesto vigente')
            ->assertJsonPath('data.usuario.values.nombre_puesto', 'Puesto anterior');
        $this->assertSame(['Puesto anterior', $unidad->id], [$persona->refresh()->nombre_puesto, $persona->unidad_organica_id]);
    }

    public function test_an_existing_dni_points_to_that_person_and_creates_nothing(): void
    {
        $this->actuarComo();
        $existente = Usuario::factory()->create(['numero_doc_identidad' => '71000001', 'nombres' => 'Rosa', 'apellido_paterno' => 'Previa', 'apellido_materno' => 'Registro']);
        $usuarios = Usuario::query()->count();

        $this->postJson(self::URL, $this->datos(persona: ['email_institucional' => 'otra.rosa@example.test']))->assertUnprocessable()
            ->assertJsonValidationErrors('usuarios.0.numero_doc_identidad')
            ->assertJsonPath('persona_existente', ['id' => $existente->id, 'nombre' => 'Rosa Previa Registro']);
        // Sin duplicados ni nada a medias: ni persona, ni legajo, ni vínculo, ni datos del puesto.
        $this->assertSame($usuarios, Usuario::query()->count());
        $this->assertSame(1, Usuario::query()->where('numero_doc_identidad', '71000001')->count());
        $this->assertSame([0, 0, 0, 0], [Legajo::query()->count(), VinculoLaboral::query()->count(), PerfilDocente::query()->count(), PerfilAdministrativo::query()->count()]);

        // Otros errores no identifican a nadie.
        $this->postJson(self::URL, $this->datos(persona: ['numero_doc_identidad' => '71000002', 'email_institucional' => 'no-es-correo']))->assertUnprocessable()
            ->assertJsonMissingPath('persona_existente');
    }

    public function test_the_existing_person_is_only_identified_to_whom_can_see_files(): void
    {
        config()->set('permisos.roles.registro_sin_fichas', ['usuarios.gestionar', 'legajo.abrir', 'legajo.editar']);
        $this->actuarComo(null, 'registro_sin_fichas');
        Usuario::factory()->create(['numero_doc_identidad' => '71000001']);

        $this->postJson(self::URL, $this->datos(persona: ['email_institucional' => 'otra.rosa@example.test']))->assertUnprocessable()
            ->assertJsonValidationErrors('usuarios.0.numero_doc_identidad')->assertJsonMissingPath('persona_existente');
        $this->assertSame(1, Usuario::query()->where('numero_doc_identidad', '71000001')->count());
    }

    public function test_the_result_carries_the_file_number_and_the_labor_situation(): void
    {
        $this->actuarComo();
        $this->postJson(self::URL, $this->datos())->assertCreated()
            ->assertJsonPath('data.usuario.situacion', 'vinculo_vigente')->assertJsonPath('data.usuario.activo', false)
            ->assertJsonStructure(['data' => ['usuario' => ['id'], 'legajo' => ['id', 'numero_legajo', 'estado_legajo']]]);
    }
}
