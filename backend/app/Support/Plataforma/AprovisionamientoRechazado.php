<?php

namespace App\Support\Plataforma;

use RuntimeException;

/** La institución no se puede aprovisionar en su estado actual (409 en el panel). No se creó ni se borró nada. */
final class AprovisionamientoRechazado extends RuntimeException {}
