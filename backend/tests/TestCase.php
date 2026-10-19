<?php

namespace Tests;

use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use App\Support\Documentos\AnalizadorAntivirus;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\PdfValido;

abstract class TestCase extends BaseTestCase
{
    use PdfValido;

    protected function setUp(): void
    {
        parent::setUp();
        config(['documentos.clave' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->mock(AnalizadorAntivirus::class)
            ->shouldReceive('analizar')->andReturnNull()->byDefault();
    }

    /** Asigna un rol vigente al usuario (lo crea si no existe el rol). */
    protected function asignarRol(Usuario $usuario, string $codigo = 'ADMIN', array $asignacion = [], array $rol = []): Usuario
    {
        $registro = Rol::query()->firstOrCreate(['codigo' => $codigo], $rol + [
            'nombre' => $codigo, 've_datos_sensibles' => $codigo === 'ADMIN', 'activo' => true,
        ]);
        UsuarioRol::query()->create($asignacion + [
            'usuario_id' => $usuario->id, 'rol_id' => $registro->id, 'ambito_tipo_personal' => 'ambos',
            'vigente_desde' => today()->subYear()->toDateString(), 'asignado_por' => $usuario->id,
        ]);

        return $usuario;
    }

    /** Autentica por Sanctum como un usuario con el rol indicado. */
    protected function actuarComo(?Usuario $usuario = null, string $codigo = 'ADMIN'): Usuario
    {
        // refresh(): carga los valores por defecto de la base (activo) antes de calcular permisos.
        $usuario = $this->asignarRol($usuario ?? Usuario::factory()->create(), $codigo)->refresh();
        Sanctum::actingAs($usuario);

        return $usuario;
    }
}
