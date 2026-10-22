<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\Familiar;
use App\Models\PerfilAdministrativo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConvertirMayusculasTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_apply_it_only_counts_and_writes_nothing(): void
    {
        $persona = Usuario::factory()->create(['nombres' => 'José', 'apellido_paterno' => 'Peña']);

        $this->artisan('datos:mayusculas')->expectsOutputToContain('usuarios: ')->assertSuccessful();

        $this->assertSame('José', $persona->fresh()->nombres);
        $this->assertSame(0, AuditoriaEvento::query()->where('accion', 'datos.mayusculas')->count());
    }

    public function test_apply_converts_short_fields_with_spanish_letters_and_audits_each_row(): void
    {
        $persona = Usuario::factory()->create(['nombres' => 'José ñandú', 'apellido_paterno' => 'Peña', 'apellido_materno' => 'QUISPE',
            'email_institucional' => 'jose.pena@example.test', 'domicilio' => 'Av. Sol 123']);
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'nombre_puesto' => 'Jefe de práctica', 'numero_documento_vinculo' => 'res-001']);
        $perfil = PerfilAdministrativo::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'cargo_estructural' => 'jefe de unidad']);
        $familiar = Familiar::factory()->create(['usuario_id' => $persona->id, 'apellidos_nombres' => 'peña rosa']);
        $anulado = Familiar::factory()->create(['usuario_id' => $persona->id, 'apellidos_nombres' => 'anulada', 'anulado' => true, 'motivo_anulacion' => 'error de registro']);

        $this->artisan('datos:mayusculas', ['--aplicar' => true])->assertSuccessful();

        $persona->refresh();
        $this->assertSame(['JOSÉ ÑANDÚ', 'PEÑA', 'QUISPE', 'AV. SOL 123'], [$persona->nombres, $persona->apellido_paterno, $persona->apellido_materno, $persona->domicilio]);
        $this->assertSame('jose.pena@example.test', $persona->email_institucional);
        $this->assertSame(['JEFE DE PRÁCTICA', 'RES-001'], [$vinculo->fresh()->nombre_puesto, $vinculo->fresh()->numero_documento_vinculo]);
        $this->assertSame('JEFE DE UNIDAD', $perfil->fresh()->cargo_estructural);
        $this->assertSame('PEÑA ROSA', $familiar->fresh()->apellidos_nombres);
        $this->assertSame('anulada', $anulado->fresh()->apellidos_nombres);
        $this->assertSame('error de registro', $anulado->fresh()->motivo_anulacion);

        $eventoPersona = AuditoriaEvento::query()->where('accion', 'datos.mayusculas')->where('entidad_id', $persona->id)->sole();
        $this->assertNull($eventoPersona->actor_usuario_id);
        $this->assertSame($persona->id, $eventoPersona->usuario_titular_id);
        // Solo los campos que cambiaron, con el valor anterior y el nuevo.
        $this->assertSame(['nombres' => 'José ñandú', 'apellido_paterno' => 'Peña', 'domicilio' => 'Av. Sol 123'], json_decode($eventoPersona->datos_antes, true));
        $this->assertSame($persona->id, AuditoriaEvento::query()->where('entidad', 'perfil_administrativo')->where('entidad_id', $perfil->id)->value('usuario_titular_id'));

        // Una segunda pasada no encuentra nada ni audita de nuevo.
        $eventos = AuditoriaEvento::query()->count();
        $this->artisan('datos:mayusculas', ['--aplicar' => true])->expectsOutputToContain('usuarios: 0 fila(s) convertida(s)')->assertSuccessful();
        $this->assertSame($eventos, AuditoriaEvento::query()->count());
    }
}
