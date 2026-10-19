<?php

namespace App\Support\Documentos;

use App\Models\DocumentoUsuario;
use App\Models\TipoDocumento;

/**
 * Estado visible y condición de "aprobado" de un documento.
 *
 * La aceptación directa del responsable acredita el registro interno, sin marcar fiscalización externa.
 * Para los estados anteriores: aprobado = presente (con archivo y no marcado falso) Y con recepción registrada (Directiva, art. 4.5) Y,
 * si el tipo acredita el perfil del puesto, fiscalizado conforme (art. 7). La fiscalización aún no se
 * registra desde el sistema, así que esos tipos no salen aprobados todavía.
 */
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

    /** TODO pendiente de confirmar con el cliente: si un documento "observado" cuenta como presente. */
    public static function aprobado(DocumentoUsuario $documento, TipoDocumento $tipo): bool
    {
        if ($documento->estado === 'aceptado') {
            return $documento->archivo !== null && $documento->archivo->estado_antivirus !== 'infectado';
        }

        $presente = $documento->archivo_id !== null && ! in_array($documento->estado, ['pendiente', 'falso'], true);
        $fiscalizado = ! $tipo->acredita_perfil_puesto || ($documento->fiscalizado && $documento->estado === 'verificado');

        return $presente && self::recepcionado($documento) && $fiscalizado;
    }
}
