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

];
