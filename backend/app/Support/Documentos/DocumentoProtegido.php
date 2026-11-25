<?php

namespace App\Support\Documentos;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Intento de eliminar o alterar un documento del legajo o su archivo (Directiva, arts. 2.3 y 5.3). Si llega a una
 * petición responde 409 con el motivo; en código es un error de programación, no un caso normal.
 */
final class DocumentoProtegido extends ConflictHttpException {}
