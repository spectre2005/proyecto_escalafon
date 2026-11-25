<?php

namespace App\Support\Antivirus;

/** ANTIVIRUS_DRIVER=ninguno (solo desarrollo): no revisa nada y los archivos quedan como pendientes. */
final class SinAntivirus implements EscanerAntivirus
{
    public function activo(): bool
    {
        return false;
    }

    public function escanearArchivo(string $ruta): ?string
    {
        return null;
    }

    public function escanearContenido(string $contenido): ?string
    {
        return null;
    }
}
