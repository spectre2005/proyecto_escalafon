<?php

namespace App\Support\Identidad;

use LogicException;

/**
 * SIMULACIÓN para demostraciones y pruebas: no consulta RENIEC ni ningún servicio, no usa la red y no acredita la
 * identidad de nadie. Solo responde a los DNI de demostración de CASOS (personas ficticias); cualquier otro DNI no tiene
 * coincidencia. Fuera de APP_ENV local o testing se niega a responder, aunque alguien la elija por error
 * (ProveedorIdentidad tampoco la entrega en ese caso).
 */
final class ConsultaDniSimulada implements ConsultaDni
{
    public const ENTORNOS = ['local', 'testing'];

    /**
     * DNI de demostración → nombres ficticios. Los apellidos dicen que son de prueba. La ubicación, cuando la hay, se
     * escribe como la informan los proveedores (mayúsculas, sin tildes): 00000001 está en el catálogo nacional y 00000004
     * no existe en ningún catálogo (se elige a mano).
     */
    public const CASOS = [
        '00000001' => ['nombres' => 'Ana Lucía', 'apellido_paterno' => 'Demostración', 'apellido_materno' => 'Ficticia',
            'ubicacion' => ['departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA']],
        '00000002' => ['nombres' => 'Carlos Alberto', 'apellido_paterno' => 'Prueba', 'apellido_materno' => 'Simulado'],
        '00000003' => ['nombres' => 'María', 'apellido_paterno' => 'Ejemplo', 'apellido_materno' => null],
        '00000004' => ['nombres' => 'Lucía Fernanda', 'apellido_paterno' => 'Muestra', 'apellido_materno' => 'Simulada',
            'ubicacion' => ['departamento' => 'REGION FICTICIA', 'provincia' => 'PROVINCIA FICTICIA', 'distrito' => 'DISTRITO FICTICIO']],
    ];

    /** DNI de demostración que simula un proveedor caído, para probar que el registro sigue manual. */
    public const DNI_NO_DISPONIBLE = '00000009';

    public static function permitida(): bool
    {
        return in_array(app()->environment(), self::ENTORNOS, true);
    }

    public function nombre(): string
    {
        return 'simulado';
    }

    public function esOficial(): bool
    {
        return false;
    }

    public function esSimulado(): bool
    {
        return true;
    }

    public function consultar(string $dni): ResultadoIdentidad
    {
        if (! self::permitida()) {
            throw new LogicException('El proveedor de identidad simulado solo funciona en local o en pruebas.');
        }
        if ($dni === self::DNI_NO_DISPONIBLE) {
            throw new ProveedorNoDisponible('Proveedor simulado: no disponible (caso de demostración).');
        }
        $caso = self::CASOS[$dni] ?? null;

        return $caso ? ResultadoIdentidad::coincidencia($caso['nombres'], $caso['apellido_paterno'], $caso['apellido_materno'], $caso['ubicacion'] ?? null) : ResultadoIdentidad::sinCoincidencia();
    }
}
