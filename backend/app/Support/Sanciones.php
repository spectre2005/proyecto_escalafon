<?php

namespace App\Support;

/**
 * Catálogo de sanciones (sanciones.tipo). Reúne las de la Ley 30057 (servicio civil: amonestación verbal y escrita,
 * suspensión, destitución) y las de la Ley 30220 para docentes (cese temporal), más la inhabilitación que impone otra
 * autoridad. Pendiente del cliente: qué tipos usa para cada régimen; hoy se ofrecen todos.
 */
final class Sanciones
{
    public const TIPOS = [
        'AMONESTACION_VERBAL' => 'Amonestación verbal',
        'AMONESTACION_ESCRITA' => 'Amonestación escrita',
        'SUSPENSION' => 'Suspensión',
        'CESE_TEMPORAL' => 'Cese temporal',
        'DESTITUCION' => 'Destitución',
        'INHABILITACION' => 'Inhabilitación',
    ];

    /**
     * Tipos de documento de la sección 08 que forman la documentación disciplinaria: solo los ve quien tiene
     * seccion08.sanciones.ver (además de datos sensibles). Única lista; no repetirla en otros archivos.
     */
    public const CODIGOS_DOCUMENTO = ['SAN_RESOLUCION_SANCION', 'SAN_RESOLUCION_RECURSO', 'SAN_INICIO_PAD', 'SAN_MEDIDA_CAUTELAR'];

    /** Sanciones que separan del cargo por un periodo: exigen días y fechas, y pueden descontarse del tiempo de servicio. */
    public const CON_SEPARACION = ['SUSPENSION', 'CESE_TEMPORAL'];

    public static function etiqueta(?string $tipo): string
    {
        return self::TIPOS[$tipo ?? ''] ?? ConsultaReportes::etiqueta($tipo);
    }
}
