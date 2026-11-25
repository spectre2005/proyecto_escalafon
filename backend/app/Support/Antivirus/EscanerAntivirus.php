<?php

namespace App\Support\Antivirus;

/**
 * Motor que revisa un archivo. Devuelve null si está limpio o el nombre de la amenaza si está infectado; lanza
 * AntivirusNoDisponible si no pudo revisarlo. Las pruebas usan un motor falso (tests/Support/AntivirusFalso).
 */
interface EscanerAntivirus
{
    /** false cuando no hay motor configurado (solo en desarrollo): el archivo queda sin revisar. */
    public function activo(): bool;

    public function escanearArchivo(string $ruta): ?string;

    public function escanearContenido(string $contenido): ?string;
}
