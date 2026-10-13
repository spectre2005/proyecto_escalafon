<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Mensajes genéricos en español (lang/es): misma estructura que los de Laravel en inglés, de modo que ninguna regla
 * muestre una clave sin traducir («validation.required»), y nombres de campo legibles.
 */
class TraduccionesTest extends TestCase
{
    public function test_every_laravel_message_has_its_spanish_translation(): void
    {
        $origen = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');
        foreach (['validation', 'auth', 'passwords', 'pagination'] as $archivo) {
            $ingles = array_keys(Arr::dot(require "{$origen}/{$archivo}.php"));
            $espanol = array_keys(Arr::dot(require lang_path("es/{$archivo}.php")));
            // Los ejemplos de Laravel para mensajes y atributos propios no se traducen.
            $faltan = array_filter(array_diff($ingles, $espanol), fn (string $clave): bool => ! str_starts_with($clave, 'custom.') && $clave !== 'attributes');
            $this->assertSame([], array_values($faltan), "Faltan en lang/es/{$archivo}.php");
        }
    }

    public function test_validation_errors_are_in_spanish_with_readable_field_names(): void
    {
        $this->assertSame('es', app()->getLocale());
        $errores = Validator::make(['fecha_emision' => 'ayer', 'email' => 'no-es-correo', 'nombres' => ''],
            ['fecha_emision' => 'date', 'email' => 'email', 'nombres' => 'required', 'motivo_cese' => 'required|string|min:5'])->errors();

        $this->assertSame('El campo fecha de emisión debe ser una fecha válida.', $errores->first('fecha_emision'));
        $this->assertSame('El campo correo electrónico debe ser un correo electrónico válido.', $errores->first('email'));
        $this->assertSame('El campo nombres es obligatorio.', $errores->first('nombres'));
        $this->assertSame('El campo motivo del cese es obligatorio.', $errores->first('motivo_cese'));
    }

    public function test_an_api_request_answers_in_spanish(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173');
        config(['sanctum.stateful' => ['localhost:5173'], 'cache.limiter' => 'array']);

        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'El campo correo electrónico es obligatorio.')
            ->assertJsonPath('errors.password.0', 'El campo contraseña es obligatorio.');
    }
}
