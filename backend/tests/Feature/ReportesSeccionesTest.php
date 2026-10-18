<?php

namespace Tests\Feature;

use App\Models\Afiliacion;
use App\Models\CertificadoDiscapacidad;
use App\Models\Colegiatura;
use App\Models\CompensacionBeneficio;
use App\Models\Controversia;
use App\Models\DeclaracionVerificacion;
use App\Models\Desplazamiento;
use App\Models\Desvinculacion;
use App\Models\ExperienciaLaboral;
use App\Models\Familiar;
use App\Models\Induccion;
use App\Models\Legajo;
use App\Models\PeriodoPrueba;
use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\Reconocimiento;
use App\Models\RegistroSstBienestar;
use App\Models\Sancion;
use App\Models\TiempoServicioReconocido;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportesSeccionesTest extends TestCase
{
    use RefreshDatabase;

    public static function registros(): array
    {
        return [
            ['familiares', Familiar::class, 'apellidos_nombres', 'Familiar del servidor'],
            ['antecedentes', DeclaracionVerificacion::class, 'resultado', 'sin_registro'],
            ['discapacidad', CertificadoDiscapacidad::class, 'numero_conadis', 'CONADIS-001'],
            ['inducciones', Induccion::class, 'responsable', 'Oficina de personal'],
            ['periodos_prueba', PeriodoPrueba::class, 'fecha_inicio', '2025-04-02'],
            ['colegiaturas', Colegiatura::class, 'numero_colegiatura', '001234'],
            ['experiencia', ExperienciaLaboral::class, 'entidad_empresa', 'Entidad anterior'],
            ['tiempo_servicios', TiempoServicioReconocido::class, 'anios', 12],
            ['beneficios', CompensacionBeneficio::class, 'concepto', 'Reconocimiento de beneficio'],
            ['desplazamientos', Desplazamiento::class, 'puesto_destino', 'Especialista de destino'],
            ['progresiones', ProgresionCarrera::class, 'nivel_nuevo', 'Nivel siguiente'],
            ['ratificaciones', RatificacionDocente::class, 'numero_resolucion', 'RES-DOC-2025'],
            ['reconocimientos', Reconocimiento::class, 'motivo', 'Mérito institucional'],
            ['sanciones', Sancion::class, 'numero_resolucion', 'RES-SANCION-2025'],
            ['controversias', Controversia::class, 'materia', 'Materia registrada'],
            ['afiliaciones', Afiliacion::class, 'numero_afiliacion', '00007654'],
            ['sst_bienestar', RegistroSstBienestar::class, 'entidad', 'Entidad de bienestar'],
            ['desvinculaciones', Desvinculacion::class, 'numero_resolucion', 'RES-CESE-2025'],
        ];
    }

    #[DataProvider('registros')]
    public function test_each_section_exports_real_fields_excluding_cancelled_records_and_people_without_a_legajo(string $tipo, string $modelo, string $campo, string|int $valor): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create(['numero_legajo' => 'LEG-REPORTE']);
        $modelo::factory()->create(['usuario_id' => $legajo->usuario_id, $campo => $valor, 'anulado' => false]);
        $modelo::factory()->create(['usuario_id' => $legajo->usuario_id, 'anulado' => true]);
        $modelo::factory()->create(['usuario_id' => Usuario::factory()->create()->id, 'anulado' => false]);
        $this->getJson('/api/v1/reportes/'.$tipo.'?exportar=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.'.$campo, $valor)->assertJsonPath('data.0.legajo', 'LEG-REPORTE')
            ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.registrado_por');
    }

    public function test_personal_information_excludes_accounts_without_a_legajo_and_never_includes_credentials(): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create();
        $legajo->usuario->update(['fecha_nacimiento' => '1990-06-15', 'domicilio' => 'Domicilio de prueba', 'telefono' => '900000001']);
        Usuario::factory()->create(['domicilio' => 'NO_EXPORTAR_SIN_LEGAJO']);
        $this->getJson('/api/v1/reportes/ficha_personal?exportar=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.domicilio', 'Domicilio de prueba')->assertJsonPath('data.0.fecha_nacimiento', '1990-06-15')
            ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.ultimo_acceso')->assertJsonMissingPath('data.0.foto_path');
        $this->getJson('/api/v1/reportes/personal')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_sensitive_disciplinary_and_career_reports_check_permissions_in_the_api_and_catalogue(): void
    {
        $this->actuarComo(codigo: 'asistente_legajo');
        foreach (['ficha_personal', 'familiares', 'antecedentes', 'discapacidad', 'sanciones', 'afiliaciones', 'sst_bienestar'] as $tipo) {
            $this->getJson('/api/v1/reportes/'.$tipo.'?exportar=1')->assertForbidden();
            $this->getJson('/api/v1/reportes/opciones')->assertOk()->assertJsonMissing(['id' => $tipo]);
        }
        config(['permisos.roles.lector_basico' => ['legajo.ver']]);
        $this->actuarComo(codigo: 'lector_basico');
        $this->getJson('/api/v1/reportes/desplazamientos')->assertForbidden();
        $this->getJson('/api/v1/reportes/progresiones')->assertForbidden();
        $this->getJson('/api/v1/reportes/ratificaciones')->assertForbidden();
    }

    public function test_experience_can_be_filtered_by_content_person_and_start_date(): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create(['numero_legajo' => 'LEG-EXPERIENCIA']);
        ExperienciaLaboral::factory()->create(['usuario_id' => $legajo->usuario_id, 'entidad_empresa' => 'Universidad Nacional', 'fecha_inicio' => '2024-02-10']);
        ExperienciaLaboral::factory()->create(['usuario_id' => $legajo->usuario_id, 'entidad_empresa' => 'Otra entidad', 'fecha_inicio' => '2023-02-10']);
        $this->getJson('/api/v1/reportes/experiencia?q=LEG-EXPERIENCIA&contenido=UNIVERSIDAD&desde=2024-01-01&hasta=2024-12-31')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.entidad_empresa', 'Universidad Nacional');
        $this->getJson('/api/v1/reportes/experiencia?contenido=sin_coincidencias')->assertOk()->assertJsonCount(0, 'data');
    }
}
