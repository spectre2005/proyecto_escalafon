<?php

/*
| Reglas del legajo que el cliente puede ajustar sin tocar código.
|
| Cada valor se puede sobrescribir con un registro en parametros_sistema (misma clave)
| o con la variable de entorno indicada. Ver App\Support\Parametros.
*/

return [
    'exp_dias_por_mes' => 30,
    'exp_dias_por_anio' => 360,
    'exp_hueco_largo_dias' => 90,

    // Pendiente del cliente: reglas del tiempo de servicio (App\Support\TiempoServicio), 1 = sí, 0 = no. Por defecto no
    // se resta nada y un plazo vencido sin cese cuenta hasta su fin previsto (pendiente 13), así que el reporte dice
    // «según vínculos registrados». Días por mes y por año: exp_dias_por_mes y exp_dias_por_anio (pendiente 25).
    'ts_restar_licencias_sin_goce' => 0,
    'ts_restar_suspensiones' => 0,
    'ts_vencido_hasta_corte' => 0,

    // Pendiente del cliente: días de anticipación para avisar que un vínculo
    // laboral (o su última adenda) está por vencer. Días calendario, no hábiles.
    'dias_aviso_vencimiento_vinculo' => (int) env('LEGAJO_DIAS_AVISO_VENCIMIENTO', 30),

    // Pendiente del cliente: tamaño máximo de cada PDF del legajo, en KB.
    // Subirlo por encima de 2048 exige también ampliar upload_max_filesize/post_max_size de PHP y
    // client_max_body_size de nginx (hoy 2 MB y 3 MB en docker/).
    'tamano_maximo_pdf_kb' => (int) env('LEGAJO_TAMANO_MAXIMO_PDF_KB', 2048),

    // Pendiente del cliente: formato del número de legajo. Único lugar donde se define
    // (App\Support\NumeroLegajo). {anio} = año de apertura; el correlativo se reinicia cada año. Máximo 20 caracteres.
    'numero_legajo' => ['prefijo' => 'LEG-{anio}-', 'digitos' => 6],

    // Disco privado donde se guardan los PDF del legajo (config/filesystems.php). Nunca un disco público.
    'disco_documentos' => env('LEGAJO_DISCO_DOCUMENTOS', 'documentos'),

];
