<?php

namespace Tests\Fixtures;

use App\Support\Antivirus\AntivirusNoDisponible;
use App\Support\Antivirus\EscanerAntivirus;

/**
 * Motor de pruebas: detecta la cadena de prueba EICAR (estándar e inofensiva) como lo haría ClamAV, y puede simular
 * que el antivirus está caído. Tests\TestCase lo registra en cada prueba; las pruebas no dependen del contenedor.
 */
final class AntivirusFalso implements EscanerAntivirus
{
    public const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public bool $caido = false;

    public int $revisados = 0;

    public function activo(): bool
    {
        return true;
    }

    public function escanearArchivo(string $ruta): ?string
    {
        return $this->escanearContenido((string) file_get_contents($ruta));
    }

    public function escanearContenido(string $contenido): ?string
    {
        if ($this->caido) {
            throw new AntivirusNoDisponible('Antivirus de prueba caído.');
        }
        $this->revisados++;

        return str_contains($contenido, self::EICAR) ? 'Eicar-Test-Signature' : null;
    }
}
