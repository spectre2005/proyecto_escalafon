<?php

namespace App\Support\Plataforma;

use RuntimeException;

/** PLATAFORMA_ALMACENAMIENTO_UNICA no se puede usar (AlmacenamientoUnica): la aplicación no arranca. Sin datos personales. */
final class AlmacenamientoUnicaNoValido extends RuntimeException
{
    public function __construct(string $motivo)
    {
        parent::__construct('PLATAFORMA_ALMACENAMIENTO_UNICA: '.$motivo);
    }
}
