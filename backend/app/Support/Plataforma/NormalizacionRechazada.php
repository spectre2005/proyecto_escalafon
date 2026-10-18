<?php

namespace App\Support\Plataforma;

use RuntimeException;

/** Una etapa de NormalizacionAlmacenamiento no se hizo (o se deshizo): el mensaje no lleva rutas ni datos personales. */
final class NormalizacionRechazada extends RuntimeException {}
