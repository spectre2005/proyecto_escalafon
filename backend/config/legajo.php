<?php

/*
| Reglas del legajo que el cliente puede ajustar sin tocar código.
|
| Cada valor se puede sobrescribir con un registro en parametros_sistema (misma clave)
| o con la variable de entorno indicada. Ver App\Support\Parametros.
*/

return [

    // TODO pendiente de confirmar con el cliente: días de anticipación para avisar que un vínculo
    // laboral (o su última adenda) está por vencer. Días calendario, no hábiles.
    'dias_aviso_vencimiento_vinculo' => (int) env('LEGAJO_DIAS_AVISO_VENCIMIENTO', 30),

    // TODO pendiente de confirmar con el cliente: tamaño máximo de cada PDF del legajo, en KB.
    // Subirlo por encima de 2048 exige también ampliar upload_max_filesize/post_max_size de PHP y
    // client_max_body_size de nginx (hoy 2 MB y 3 MB en docker/).
    'tamano_maximo_pdf_kb' => (int) env('LEGAJO_TAMANO_MAXIMO_PDF_KB', 2048),

    // TODO pendiente de confirmar con el cliente: formato del número de legajo. Único lugar donde se define
    // (App\Support\NumeroLegajo). {anio} = año de apertura; el correlativo se reinicia cada año. Máximo 20 caracteres.
    'numero_legajo' => ['prefijo' => 'LEG-{anio}-', 'digitos' => 6],

    // Disco privado donde se guardan los PDF del legajo (config/filesystems.php). Nunca un disco público.
    'disco_documentos' => env('LEGAJO_DISCO_DOCUMENTOS', 'documentos'),

];
