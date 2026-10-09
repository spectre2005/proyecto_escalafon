<?php

/*
| Catálogo de secciones y funcionalidades configurables.
|
| Referencia: docs/decisiones-configuracion-secciones.md. Solo declara lo que existe hoy en el código.
| El estado activo/inactivo no vive aquí: se guarda en configuracion_funcionalidades y lo consulta
| App\Support\ConfiguracionFuncionalidades. Sin fila guardada, una funcionalidad implementada está activa.
|
| Esta configuración es independiente de los permisos (config/permisos.php): activar algo no da permiso
| a nadie, y quién puede cambiarla se decidirá con configuracion.modulos (mecanismo pendiente del equipo).
*/

return [

    // Secciones del legajo. Las 12 normativas (art. 3.1) no se desactivan enteras en la primera versión
    // (PROVISIONAL). La 12 no está implementada. La adicional de legajos pasivos (art. 3.2, "13") es
    // optativa: no se exige ni se crea al abrir un legajo.
    'secciones' => [
        1 => ['caracter' => 'normativa', 'implementada' => true],
        2 => ['caracter' => 'normativa', 'implementada' => true],
        3 => ['caracter' => 'normativa', 'implementada' => true],
        4 => ['caracter' => 'normativa', 'implementada' => true],
        5 => ['caracter' => 'normativa', 'implementada' => true],
        6 => ['caracter' => 'normativa', 'implementada' => true],
        7 => ['caracter' => 'normativa', 'implementada' => true],
        8 => ['caracter' => 'normativa', 'implementada' => true],
        9 => ['caracter' => 'normativa', 'implementada' => true],
        10 => ['caracter' => 'normativa', 'implementada' => true],
        11 => ['caracter' => 'normativa', 'implementada' => true],
        12 => ['caracter' => 'normativa', 'implementada' => false],
        13 => ['caracter' => 'adicional', 'implementada' => true],
    ],

    /*
    | Funcionalidades: código => definición.
    |
    | - seccion: id de secciones_legajo.
    | - implementada: existe en el código. Lo implementado se considera disponible (primera versión, sin planes).
    | - esencial: null, o ['motivo', 'permanente' => dependencias del sistema que siguen vigentes mientras no
    |   cambie el código]. Una esencial no se desactiva mientras tenga una dependencia vigente: una permanente
    |   o una funcionalidad activa que dependa de ella.
    | - depende_de: funcionalidades que necesita (verificado en claves foráneas y controladores).
    | - bloqueo: null, o ['tipo' => sin_alternativa | pendiente_universidad | pendiente_equipo, 'motivo'].
    |   Mientras exista, la funcionalidad no se puede desactivar.
    | - rutas_escritura: rutas de creación y modificación que la sirven hoy, algunas compartidas por tipo.
    |   Son solo referencia: en esta etapa no se bloquea ninguna ruta.
    | - campos (opcional): columnas tabla.campo a las que afecta una opción dentro de otra funcionalidad. Una opción
    |   no tiene rutas propias; desactivarla no borra ni modifica los valores ya guardados.
    */
    'funcionalidades' => [

        // Sección 01
        's01.identidad' => [
            'nombre' => 'Ficha de datos personales y documento de identidad', 'seccion' => 1, 'implementada' => true,
            'esencial' => ['motivo' => 'Identifica a la persona titular del legajo (PROVISIONAL).',
                'permanente' => ['El titular de todo documento se completa con los datos de la persona (DocumentoLegajoController).']],
            'depende_de' => [], 'bloqueo' => null,
            'rutas_escritura' => ['PATCH api/v1/expedientes/{usuario}', 'POST api/v1/expedientes/{usuario}/foto'],
        ],
        's01.familiares' => [
            'nombre' => 'Familiares y derechohabientes', 'seccion' => 1, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_universidad', 'motivo' => 'Contenido normativo de la sección 01: falta confirmar si se puede desactivar.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/familiares', 'PATCH api/v1/expedientes/{usuario}/familiares/{familiar}',
                'POST api/v1/expedientes/{usuario}/{tipo}/{registro}/anular'],
        ],
        's01.declaraciones' => [
            'nombre' => 'Declaraciones juradas y verificaciones', 'seccion' => 1, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_universidad', 'motivo' => 'Contenido normativo de la sección 01: falta confirmar si se puede desactivar.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/declaraciones-verificaciones',
                'PATCH api/v1/expedientes/{usuario}/declaraciones-verificaciones/{declaracion}', 'POST api/v1/expedientes/{usuario}/{tipo}/{registro}/anular'],
        ],
        's01.discapacidad' => [
            'nombre' => 'Certificados de discapacidad', 'seccion' => 1, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_universidad', 'motivo' => 'Contenido normativo de la sección 01: falta confirmar si se puede desactivar.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/certificados-discapacidad',
                'PATCH api/v1/expedientes/{usuario}/certificados-discapacidad/{certificado}', 'POST api/v1/expedientes/{usuario}/{tipo}/{registro}/anular'],
        ],

        // Sección 02
        's02.seleccion' => [
            'nombre' => 'Documentos del proceso de selección (2.1)', 'seccion' => 2, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_universidad', 'motivo' => 'Contenido normativo de la sección 02 (2.1): falta confirmar si se puede desactivar.'],
            // Solo documentos, por la subida común.
            'rutas_escritura' => [],
        ],
        's02.vinculos' => [
            'nombre' => 'Vínculos laborales (2.2)', 'seccion' => 2, 'implementada' => true,
            'esencial' => ['motivo' => 'Los vínculos solo se crean en la sección 02 (PROVISIONAL).',
                'permanente' => ['PerfilDocumental calcula lo exigido en 01, 03, 04, 06, 07 y 08 a partir de los vínculos.']],
            'depende_de' => [], 'bloqueo' => null,
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/vinculos-laborales', 'PATCH api/v1/expedientes/{usuario}/vinculos-laborales/{vinculo}',
                'POST api/v1/expedientes/{usuario}/seccion-02/{tipo}/{registro}/anular'],
        ],
        's02.adendas' => [
            'nombre' => 'Adendas del vínculo (2.2)', 'seccion' => 2, 'implementada' => true, 'esencial' => null, 'depende_de' => ['s02.vinculos'],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Contenido de la 2.2 sin clasificar en las decisiones de configuración.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/vinculo-adendas', 'POST api/v1/expedientes/{usuario}/seccion-02/{tipo}/{registro}/anular'],
        ],
        's02.datos_puesto' => [
            'nombre' => 'Datos del puesto: perfil docente o administrativo (2.2)', 'seccion' => 2, 'implementada' => true, 'esencial' => null,
            'depende_de' => ['s02.vinculos'],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Contenido de la 2.2 sin clasificar en las decisiones de configuración.'],
            'rutas_escritura' => ['PUT api/v1/expedientes/{usuario}/vinculos-laborales/{vinculo}/datos-puesto'],
        ],
        's02.inducciones' => [
            'nombre' => 'Inducciones (2.3)', 'seccion' => 2, 'implementada' => true, 'esencial' => null, 'depende_de' => ['s02.vinculos'],
            'bloqueo' => ['tipo' => 'pendiente_universidad', 'motivo' => 'Contenido normativo de la sección 02 (2.3): falta confirmar si se puede desactivar.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/inducciones', 'POST api/v1/expedientes/{usuario}/seccion-02/{tipo}/{registro}/anular'],
        ],
        's02.periodos_prueba' => [
            'nombre' => 'Períodos de prueba (2.4)', 'seccion' => 2, 'implementada' => true, 'esencial' => null, 'depende_de' => ['s02.vinculos'],
            'bloqueo' => ['tipo' => 'pendiente_universidad', 'motivo' => 'Contenido normativo de la sección 02 (2.4): falta confirmar si se puede desactivar.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/periodos-prueba', 'PATCH api/v1/expedientes/{usuario}/periodos-prueba/{periodo}',
                'POST api/v1/expedientes/{usuario}/seccion-02/{tipo}/{registro}/anular'],
        ],

        // Sección 03
        's03.formacion' => [
            'nombre' => 'Formación académica, capacitación y colegiaturas', 'seccion' => 3, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-03/documentos', 'POST api/v1/expedientes/{usuario}/seccion-03/documentos/{documento}/estado'],
        ],

        // Sección 04
        's04.experiencia' => [
            'nombre' => 'Experiencia laboral', 'seccion' => 4, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'sin_alternativa', 'motivo' => 'La subida común rechaza sus documentos: solo entran por su formulario propio.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-04/documentos', 'POST api/v1/expedientes/{usuario}/seccion-04/documentos/{documento}/estado'],
        ],

        // Sección 05
        's05.licencias' => [
            'nombre' => 'Licencias y permisos', 'seccion' => 5, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-05/licencias-permisos', 'POST api/v1/expedientes/{usuario}/seccion-05/{tipo}/{registro}/anular'],
        ],
        's05.periodos_vacacionales' => [
            'nombre' => 'Períodos vacacionales', 'seccion' => 5, 'implementada' => true, 'esencial' => null, 'depende_de' => ['s02.vinculos'],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-05/periodos-vacacionales', 'POST api/v1/expedientes/{usuario}/seccion-05/{tipo}/{registro}/anular'],
        ],
        's05.goces_vacacionales' => [
            'nombre' => 'Goces vacacionales', 'seccion' => 5, 'implementada' => true, 'esencial' => null, 'depende_de' => ['s05.periodos_vacacionales'],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-05/goces-vacacionales', 'POST api/v1/expedientes/{usuario}/seccion-05/{tipo}/{registro}/anular'],
        ],

        // Sección 06
        's06.compensaciones' => [
            'nombre' => 'Compensaciones y tiempo de servicios reconocido', 'seccion' => 6, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'sin_alternativa', 'motivo' => 'La subida común rechaza sus documentos: solo entran por su formulario propio.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-06/documentos', 'POST api/v1/expedientes/{usuario}/seccion-06/documentos/{documento}/estado'],
        ],

        // Sección 07 (también la pantalla Evaluaciones, que solo lee)
        's07.evaluaciones' => [
            'nombre' => 'Evaluaciones de desempeño', 'seccion' => 7, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-07/{tipo}', 'PATCH api/v1/expedientes/{usuario}/seccion-07/{tipo}/{registro}',
                'POST api/v1/expedientes/{usuario}/seccion-07/{tipo}/{registro}/anular'],
        ],
        // Opción dentro de las evaluaciones. La universidad no usa hoy una puntuación numérica, aunque podría
        // incorporarla más adelante; las evaluaciones siguen en uso. No afecta a la calificación ni a 7.1 a) y b).
        's07.evaluaciones.puntaje' => [
            'nombre' => 'Puntuación numérica de las evaluaciones', 'seccion' => 7, 'implementada' => true, 'esencial' => null,
            'depende_de' => ['s07.evaluaciones'], 'bloqueo' => null, 'rutas_escritura' => [],
            'campos' => ['evaluaciones_desempeno.puntaje'],
        ],
        's07.progresiones' => [
            'nombre' => 'Progresiones en la carrera', 'seccion' => 7, 'implementada' => true, 'esencial' => null, 'depende_de' => ['s02.vinculos'],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-07/{tipo}', 'PATCH api/v1/expedientes/{usuario}/seccion-07/{tipo}/{registro}',
                'POST api/v1/expedientes/{usuario}/seccion-07/{tipo}/{registro}/anular'],
        ],
        's07.ratificaciones' => [
            'nombre' => 'Ratificaciones docentes', 'seccion' => 7, 'implementada' => true, 'esencial' => null, 'depende_de' => ['s02.datos_puesto'],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-07/{tipo}', 'PATCH api/v1/expedientes/{usuario}/seccion-07/{tipo}/{registro}',
                'POST api/v1/expedientes/{usuario}/seccion-07/{tipo}/{registro}/anular'],
        ],
        's07.desplazamientos' => [
            'nombre' => 'Desplazamientos', 'seccion' => 7, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-07/{tipo}', 'PATCH api/v1/expedientes/{usuario}/seccion-07/{tipo}/{registro}',
                'POST api/v1/expedientes/{usuario}/seccion-07/{tipo}/{registro}/anular'],
        ],

        // Sección 08
        's08.reconocimientos' => [
            'nombre' => 'Reconocimientos', 'seccion' => 8, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-08/documentos', 'POST api/v1/expedientes/{usuario}/seccion-08/documentos/{documento}/estado'],
        ],
        's08.sanciones' => [
            'nombre' => 'Sanciones disciplinarias', 'seccion' => 8, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-08/documentos', 'POST api/v1/expedientes/{usuario}/seccion-08/documentos/{documento}/estado'],
        ],

        // Sección 09
        's09.controversias' => [
            'nombre' => 'Controversias individuales y colectivas', 'seccion' => 9, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-09/controversias', 'PATCH api/v1/expedientes/{usuario}/seccion-09/controversias/{registro}',
                'POST api/v1/expedientes/{usuario}/seccion-09/{tipo}/{registro}/anular'],
        ],
        's09.afiliaciones' => [
            'nombre' => 'Afiliación sindical', 'seccion' => 9, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-09/afiliaciones', 'PATCH api/v1/expedientes/{usuario}/seccion-09/afiliaciones/{registro}',
                'POST api/v1/expedientes/{usuario}/seccion-09/{tipo}/{registro}/anular'],
        ],

        // Sección 10
        's10.sst_bienestar' => [
            'nombre' => 'SST y bienestar social', 'seccion' => 10, 'implementada' => true, 'esencial' => null, 'depende_de' => [],
            'bloqueo' => ['tipo' => 'sin_alternativa', 'motivo' => 'La subida común rechaza sus documentos: solo entran por su formulario propio.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-10/documentos', 'PATCH api/v1/expedientes/{usuario}/seccion-10/documentos/{documento}',
                'POST api/v1/expedientes/{usuario}/seccion-10/documentos/{documento}/estado', 'POST api/v1/expedientes/{usuario}/seccion-10/documentos/{documento}/anular'],
        ],

        // Sección 11
        's11.desvinculaciones' => [
            'nombre' => 'Desvinculaciones', 'seccion' => 11, 'implementada' => true, 'esencial' => null, 'depende_de' => ['s02.vinculos'],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir la alternativa para incorporar sus documentos si se desactiva.'],
            'rutas_escritura' => ['POST api/v1/expedientes/{usuario}/seccion-11/desvinculaciones', 'PATCH api/v1/expedientes/{usuario}/seccion-11/desvinculaciones/{registro}',
                'POST api/v1/expedientes/{usuario}/seccion-11/desvinculaciones/{registro}/anular'],
        ],

        // Sección 12: sin implementar. No tiene funcionalidades.

        // Sección adicional de legajos pasivos: hoy solo el CRUD genérico de referencias.
        'adicional.referencias_pasivas' => [
            'nombre' => 'Referencias a legajos pasivos', 'seccion' => 13, 'implementada' => true, 'esencial' => null, 'depende_de' => ['s02.vinculos'],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir si las referencias usan folios del legajo activo.'],
            'rutas_escritura' => ['POST api/v1/referencias-legajo-pasivo', 'PUT api/v1/referencias-legajo-pasivo/{record}',
                'PATCH api/v1/referencias-legajo-pasivo/{record}', 'DELETE api/v1/referencias-legajo-pasivo/{record}'],
        ],
    ],

];
