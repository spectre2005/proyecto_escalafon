<?php

namespace App\Support\Identidad;

/** Respuesta de un proveedor: solo los campos que completa el registro manual (nombres y apellidos). */
final readonly class ResultadoIdentidad
{
    private function __construct(
        public bool $encontrado,
        public ?string $nombres = null,
        public ?string $apellidoPaterno = null,
        public ?string $apellidoMaterno = null,
    ) {}

    public static function coincidencia(string $nombres, string $apellidoPaterno, ?string $apellidoMaterno): self
    {
        return new self(true, $nombres, $apellidoPaterno, $apellidoMaterno);
    }

    public static function sinCoincidencia(): self
    {
        return new self(false);
    }
}
