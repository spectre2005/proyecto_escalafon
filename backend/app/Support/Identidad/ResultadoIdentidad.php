<?php

namespace App\Support\Identidad;

/**
 * Respuesta de un proveedor: solo los campos que completa el registro manual. Nombres y apellidos; la ubicación
 * (departamento, provincia y distrito tal como los informa el proveedor) es opcional y se empareja después con el catálogo
 * (UbicacionCatalogo). Nada de esto se guarda.
 */
final readonly class ResultadoIdentidad
{
    private function __construct(
        public bool $encontrado,
        public ?string $nombres = null,
        public ?string $apellidoPaterno = null,
        public ?string $apellidoMaterno = null,
        /** @var array{departamento: string, provincia: string, distrito: string}|null */
        public ?array $ubicacion = null,
    ) {}

    /** @param  array{departamento: string, provincia: string, distrito: string}|null  $ubicacion */
    public static function coincidencia(string $nombres, string $apellidoPaterno, ?string $apellidoMaterno, ?array $ubicacion = null): self
    {
        return new self(true, $nombres, $apellidoPaterno, $apellidoMaterno, $ubicacion);
    }

    public static function sinCoincidencia(): self
    {
        return new self(false);
    }
}
