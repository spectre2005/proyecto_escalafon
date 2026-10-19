<?php

namespace App\Support\Documentos;

use App\Models\DocumentoUsuario;
use App\Models\TipoDocumento;

/** Un documento completo exige PDF, recepción registrada y aceptación o verificación. */
final class EstadoDocumento
{
    public static function recepcionado(DocumentoUsuario $documento): bool
    {
        return $documento->fecha_recepcion !== null && $documento->recepcionado_por !== null;
    }

    /**
     * pendiente | cargado | recepcionado, o el estado del flujo de fiscalización cuando lo tenga
     * (en_fiscalizacion, verificado, observado, falso: preparados, sin acciones todavía).
     */
    public static function visible(DocumentoUsuario $documento): string
    {
        if ($documento->estado === 'cargado') {
            return self::recepcionado($documento) ? 'recepcionado' : 'cargado';
        }

        return $documento->estado;
    }

    /** Un observado se conserva en el historial, pero no cuenta como completo. */
    public static function aprobado(DocumentoUsuario $documento, TipoDocumento $tipo): bool
    {
        $presente = $documento->archivo_id !== null && in_array($documento->estado, ['aceptado', 'verificado'], true);

        return $presente && self::recepcionado($documento);
    }
}
