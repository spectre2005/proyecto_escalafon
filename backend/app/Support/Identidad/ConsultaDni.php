<?php

namespace App\Support\Identidad;

/**
 * Proveedor de la consulta de identidad por DNI. Solo obtiene datos para completar el formulario del registro manual:
 * no crea ni guarda personas. La validación del DNI, los permisos, el módulo de la institución, el límite de consultas
 * y la auditoría son del controlador (ConsultaIdentidadController); la elección del proveedor, de ProveedorIdentidad.
 */
interface ConsultaDni
{
    /** Clave del proveedor en config/identidad.php (se guarda en la auditoría). */
    public function nombre(): string;

    /** true solo para un servicio oficial autorizado; las respuestas de uno no oficial se muestran como simuladas. */
    public function esOficial(): bool;

    /**
     * @param  string  $dni  ocho dígitos, ya validado
     *
     * @throws ProveedorNoDisponible si el servicio falla, tarda demasiado o no puede atender ahora
     */
    public function consultar(string $dni): ResultadoIdentidad;
}
