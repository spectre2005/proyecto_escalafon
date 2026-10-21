<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Validation\ValidationException;

final class IdentidadPersonal
{
    /** @return list<string> Campos ya registrados que no se reemplazan desde la ficha general. */
    public static function camposBloqueados(Usuario $persona): array
    {
        $bloqueados = ['tipo_doc_identidad'];
        if (filled($persona->numero_doc_identidad)) {
            $bloqueados[] = 'numero_doc_identidad';
        }
        if ($persona->tipo_doc_identidad !== 'dni') {
            return $bloqueados;
        }
        foreach (['nombres', 'apellido_paterno', 'apellido_materno', 'nacionalidad'] as $campo) {
            if (filled($persona->{$campo})) {
                $bloqueados[] = $campo;
            }
        }
        foreach (['region', 'provincia', 'distrito'] as $campo) {
            if (filled($persona->ubigeo?->{$campo})) {
                $bloqueados[] = 'ubicacion.'.$campo;
            }
        }

        return $bloqueados;
    }

    /** Comprueba el registro actual bajo el bloqueo de escritura, incluso si se omite If-Match. */
    public static function validarCambios(Usuario $persona, array $cambios): void
    {
        $errores = [];
        foreach (self::camposBloqueados($persona) as $campo) {
            if (str_starts_with($campo, 'ubicacion.')) {
                if (! array_key_exists('ubicacion', $cambios)) {
                    continue;
                }
                $parte = substr($campo, strlen('ubicacion.'));
                $anterior = mb_strtolower(trim((string) $persona->ubigeo?->{$parte}));
                $nuevo = mb_strtolower(trim((string) ($cambios['ubicacion'][$parte] ?? '')));
            } else {
                if (! array_key_exists($campo, $cambios)) {
                    continue;
                }
                $anterior = trim((string) $persona->{$campo});
                $nuevo = trim((string) $cambios[$campo]);
            }
            if ($nuevo !== $anterior) {
                $errores[$campo] = 'Este dato de identidad ya está registrado y no se modifica desde la ficha general.';
            }
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }
}
