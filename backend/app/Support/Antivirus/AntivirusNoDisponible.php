<?php

namespace App\Support\Antivirus;

use RuntimeException;

/** El motor no respondió o respondió algo que no se entiende: el archivo no se da por limpio. */
final class AntivirusNoDisponible extends RuntimeException {}
