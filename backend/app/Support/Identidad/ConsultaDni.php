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

    /** true solo para un servicio oficial autorizado (RENIEC/PIDE). Un servicio externo de pago no lo es. */
    public function esOficial(): bool;

    /** true solo para datos ficticios de demostración: sus respuestas se muestran como simuladas. */
    public function esSimulado(): bool;

    /**
     * @param  string  $dni  ocho dígitos, ya validado
     *
     * @throws ProveedorNoDisponible si el servicio falla, tarda demasiado o no puede atender ahora
     */
    public function consultar(string $dni): ResultadoIdentidad;
}
