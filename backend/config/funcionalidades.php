<?php

/*
| Catálogo de secciones y funcionalidades configurables.
|
| Referencia: docs/decisiones-configuracion-secciones.md. Solo declara lo que existe hoy en el código.
| El estado activo/inactivo no vive aquí: se guarda en configuracion_funcionalidades y lo consulta
| App\Support\ConfiguracionFuncionalidades. Sin fila guardada, una funcionalidad implementada está activa: el
| producto se instala con todo activo y cada institución desactiva lo que no usa (con motivo y auditoría).
|
| Esta configuración es independiente de los permisos (config/permisos.php): activar algo no da permiso a nadie.
| Cambiarla exige configuracion.modulos (asignación explícita, fuera de '*').
*/

// Escritura del CRUD genérico de una tabla de sección: se bloquea con la funcionalidad a la que pertenece.
$crud = fn (string $recurso): array => ["POST api/v1/{$recurso}", "PUT api/v1/{$recurso}/{record}",
    "PATCH api/v1/{$recurso}/{record}", "DELETE api/v1/{$recurso}/{record}"];
$expediente = 'api/v1/expedientes/{usuario}';

return [

    // Secciones del legajo. La institución decide qué usa, también secciones normativas enteras, con advertencia
    // (art. 3.1: el legajo debe contener las 12), motivo y auditoría; nada se borra y todo es reversible. Una sección
    // se oculta cuando no le queda ninguna funcionalidad activa. La 12 no está implementada. La adicional de legajos
    // pasivos (art. 3.2, "13") es optativa: no se exige ni se crea al abrir un legajo.
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

    // Advertencia al desactivar algo de una sección normativa.
    'advertencia_normativa' => 'La Directiva N° 001-2023-SERVIR-GDSRH (art. 3.1) establece que el legajo debe contener las 12 secciones. '
        .'Desactivar no elimina datos, pero la institución asume la decisión de no registrar esta información.',

    /*
    | Funcionalidades: código => definición. La unidad es la entrada del menú del legajo que ve el administrador.
    |
    | - seccion: id de secciones_legajo.
    | - implementada: existe en el código. Lo implementado se considera disponible (primera versión, sin planes).
    | - esencial: null, o ['motivo', 'permanente' => dependencias del sistema que siguen vigentes mientras no
    |   cambie el código, o decisiones confirmadas del expediente]. Con una dependencia permanente no se desactiva
    |   nunca (núcleo).
    | - depende_de: funcionalidades que necesita (verificado en claves foráneas y controladores). No se desactiva
    |   algo mientras otra activa dependa de ello, ni se activa algo cuya dependencia está inactiva.
    | - bloqueo: null, o ['tipo' => pendiente_equipo | pendiente_universidad, 'motivo']: no se puede desactivar.
    | - menu: entradas del menú del legajo (frontend) que se ocultan si está inactiva. Las entradas «Otros
    |   documentos» no son de ninguna funcionalidad: se ven mientras su sección esté visible.
    | - documentos: códigos de tipos_documento que se incorporan con ella, o ['*'] para todos los de su sección.
    |   Si está inactiva, esos tipos no se suben (subida común incluida) ni se exigen.
    | - rutas_escritura: rutas de creación y modificación ("MÉTODO uri" o [ruta, [parámetro => valores]]). Si está
    |   inactiva, responden 409. Anular, recepcionar y revisar no se bloquean (pendiente U8).
    | - tablas: tablas de datos propias, para avisar cuántos registros quedan ocultos al desactivarla.
    | - campos (opcional): columnas tabla.campo de una opción dentro de otra funcionalidad (sin rutas propias).
    */
    'funcionalidades' => [

        // Sección 01
        's01.identidad' => [
            'nombre' => 'Ficha de datos personales y documento de identidad', 'seccion' => 1, 'implementada' => true,
            'esencial' => ['motivo' => 'Identifica a la persona titular del legajo',
                'permanente' => ['El titular de todo documento se completa con los datos de la persona (DocumentoLegajoController).']],
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['general'],
            'documentos' => ['S01_A_FICHA_DATOS', 'S01_B_DOCUMENTO_IDENTIDAD'],
            'rutas_escritura' => ["PATCH {$expediente}", "POST {$expediente}/foto"],
            'tablas' => [],
        ],
        's01.declaraciones' => [
            'nombre' => 'Antecedentes y declaraciones juradas', 'seccion' => 1, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['s01-antecedentes', 's01-declaraciones'],
            'documentos' => ['S01_C_ANTECEDENTES_PENALES', 'S01_D_ANTECEDENTES_POLICIALES', 'S01_H_IMPEDIMENTOS_INHABILITACIONES',
                'S01_X_ANTECEDENTES_JUDICIALES', 'S01_E_DJ_BIENES_RENTAS', 'S01_F_DJ_NO_IMPEDIMENTOS_31419', 'S01_L_OTRAS_DJ', 'S01_X_DJ_INTERESES'],
            'rutas_escritura' => ["POST {$expediente}/declaraciones-verificaciones", "PATCH {$expediente}/declaraciones-verificaciones/{declaracion}",
                ...$crud('declaraciones-verificaciones')],
            'tablas' => ['declaraciones_verificaciones'],
        ],
        's01.familiares' => [
            'nombre' => 'Familia y derechohabientes', 'seccion' => 1, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['s01-familia'],
            'documentos' => ['S01_G_PARTIDA_MATRIMONIO_CONCUBINATO', 'S01_I_DNI_CONYUGE_CONCUBINO', 'S01_J_DNI_HIJOS_MENORES'],
            'rutas_escritura' => ["POST {$expediente}/familiares", "PATCH {$expediente}/familiares/{familiar}", ...$crud('familiares')],
            'tablas' => ['familiares'],
        ],
        's01.discapacidad' => [
            'nombre' => 'Certificados de discapacidad', 'seccion' => 1, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['s01-discapacidad'],
            'documentos' => ['S01_K_CERTIFICADO_DISCAPACIDAD'],
            'rutas_escritura' => ["POST {$expediente}/certificados-discapacidad", "PATCH {$expediente}/certificados-discapacidad/{certificado}",
                ...$crud('certificados-discapacidad')],
            'tablas' => ['certificados_discapacidad'],
        ],

        // Sección 02
        's02.seleccion' => [
            // La selección ocurre fuera del sistema, pero el resultado (S02_A) forma parte del expediente: no se desactiva.
            'nombre' => '2.1 Proceso de selección', 'seccion' => 2, 'implementada' => true,
            'esencial' => ['motivo' => 'La sección 02 forma parte obligatoria del expediente',
                'permanente' => ['Decisión confirmada: el resultado final del proceso de selección (S02_A) se conserva en el expediente.']],
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['s02-seleccion'],
            'documentos' => ['S02_A_RESULTADO_SELECCION', 'S02_B_INFORME_REQUISITOS_PUESTO'],
            // Solo documentos, por la subida común.
            'rutas_escritura' => [], 'tablas' => [],
        ],
        's02.vinculos' => [
            'nombre' => '2.2 Formalización del vínculo (vínculos, adendas y datos del puesto)', 'seccion' => 2, 'implementada' => true,
            'esencial' => ['motivo' => 'Los vínculos solo se crean en la sección 02',
                'permanente' => ['PerfilDocumental calcula lo exigido en todas las secciones a partir de los vínculos.']],
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['s02-vinculo'],
            'documentos' => ['S02_C_RESOLUCION_DESIGNACION', 'S02_D_RESOLUCION_RENOVACION', 'S02_E_RESOLUCION_CARRERA', 'S02_F_CONTRATO', 'S02_G_ADENDA'],
            'rutas_escritura' => ["POST {$expediente}/vinculos-laborales", "PATCH {$expediente}/vinculos-laborales/{vinculo}",
                "PUT {$expediente}/vinculos-laborales/{vinculo}/datos-puesto", "POST {$expediente}/vinculo-adendas"],
            'tablas' => ['vinculos_laborales', 'vinculo_adendas'],
        ],
        's02.inducciones' => [
            'nombre' => '2.3 Inducción', 'seccion' => 2, 'implementada' => true, 'esencial' => null,
            'depende_de' => ['s02.vinculos'], 'bloqueo' => null, 'menu' => ['s02-induccion'],
            'documentos' => ['S02_H_REGISTRO_INDUCCION', 'S02_I_CARGO_PERFIL_PUESTO', 'S02_J_CARGO_REGLAMENTO_INTERNO', 'S02_K_CARGO_CODIGO_ETICA', 'S02_L_OTROS_INDUCCION'],
            'rutas_escritura' => ["POST {$expediente}/inducciones"],
            'tablas' => ['inducciones'],
        ],
        's02.periodos_prueba' => [
            'nombre' => '2.4 Período de prueba', 'seccion' => 2, 'implementada' => true, 'esencial' => null,
            'depende_de' => ['s02.vinculos'], 'bloqueo' => null, 'menu' => ['s02-prueba'],
            'documentos' => ['S02_M_EVALUACION_PERIODO_PRUEBA'],
            'rutas_escritura' => ["POST {$expediente}/periodos-prueba", "PATCH {$expediente}/periodos-prueba/{periodo}"],
            'tablas' => ['periodos_prueba'],
        ],

        // Sección 03
        's03.formacion' => [
            'nombre' => 'Formación académica y capacitación', 'seccion' => 3, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null,
            'menu' => ['seccion-03', 'formacion-academica', 'capacitaciones', 'colegiaturas', 'documentos-03'],
            'documentos' => ['*'],
            'rutas_escritura' => ["POST {$expediente}/seccion-03/documentos", ...$crud('formacion-academica'), ...$crud('capacitaciones'), ...$crud('colegiaturas')],
            'tablas' => ['formacion_academica', 'capacitaciones', 'colegiaturas'],
        ],

        // Sección 04
        's04.experiencia' => [
            'nombre' => 'Experiencia laboral', 'seccion' => 4, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['seccion-04', 'experiencia-laboral', 'documentos-04'],
            'documentos' => ['*'],
            'rutas_escritura' => ["POST {$expediente}/seccion-04/documentos", ...$crud('experiencia-laboral')],
            'tablas' => ['experiencia_laboral'],
        ],

        // Sección 05
        's05.licencias' => [
            'nombre' => 'Licencias, permisos y suspensiones', 'seccion' => 5, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['s05-licencias'],
            'documentos' => ['S05_A_APRUEBA_LICENCIA_PERMISO', 'S05_E_LICENCIA_OTORGADA', 'S05_SUSPENSION', 'S05_DESCANSO_MEDICO'],
            'rutas_escritura' => ["POST {$expediente}/seccion-05/licencias-permisos"],
            'tablas' => ['licencias_permisos'],
        ],
        's05.vacaciones' => [
            'nombre' => 'Vacaciones (períodos y goces)', 'seccion' => 5, 'implementada' => true, 'esencial' => null,
            'depende_de' => ['s02.vinculos'], 'bloqueo' => null, 'menu' => ['s05-vacaciones'],
            'documentos' => ['S05_B_GOCE_VACACIONAL', 'S05_C_ADELANTO_POSTERGACION', 'S05_D_ROL_VACACIONES'],
            'rutas_escritura' => ["POST {$expediente}/seccion-05/periodos-vacacionales", "POST {$expediente}/seccion-05/goces-vacacionales"],
            'tablas' => ['periodos_vacacionales', 'goces_vacacionales'],
        ],

        // Sección 06
        's06.compensaciones' => [
            'nombre' => 'Compensaciones', 'seccion' => 6, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['seccion-06', 'compensaciones-beneficios', 'documentos-06'],
            'documentos' => ['*'],
            'rutas_escritura' => ["POST {$expediente}/seccion-06/documentos", ...$crud('compensaciones-beneficios'), ...$crud('tiempo-servicios-reconocidos')],
            'tablas' => ['compensaciones_beneficios', 'tiempo_servicios_reconocidos'],
        ],

        // Sección 07 (también la pantalla Evaluaciones, que solo lee)
        's07.evaluaciones' => [
            'nombre' => '7.1 Evaluación de desempeño', 'seccion' => 7, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['s07-desempeno'],
            'documentos' => ['S07_71A_GESTION_RENDIMIENTO', 'S07_71B_ACTA_RETROALIMENTACION'],
            'rutas_escritura' => [["POST {$expediente}/seccion-07/{tipo}", ['tipo' => ['evaluaciones-desempeno']]],
                ["PATCH {$expediente}/seccion-07/{tipo}/{registro}", ['tipo' => ['evaluaciones-desempeno']]]],
            'tablas' => ['evaluaciones_desempeno'],
        ],
        // Opción dentro de las evaluaciones: puntuación numérica (no afecta a la calificación ni a 7.1 a) y b)).
        's07.evaluaciones.puntaje' => [
            'nombre' => 'Puntuación numérica de las evaluaciones', 'seccion' => 7, 'implementada' => true, 'esencial' => null,
            'depende_de' => ['s07.evaluaciones'], 'bloqueo' => null, 'menu' => [], 'documentos' => [], 'rutas_escritura' => [],
            'tablas' => [], 'campos' => ['evaluaciones_desempeno.puntaje'],
        ],
        's07.progresion' => [
            'nombre' => '7.2 Progresión en la carrera (progresiones y ratificaciones docentes)', 'seccion' => 7, 'implementada' => true,
            'esencial' => null, 'depende_de' => ['s02.vinculos'], 'bloqueo' => null, 'menu' => ['s07-progresion'],
            'documentos' => ['S07_72A_RESOLUCION_PROGRESION'],
            'rutas_escritura' => [["POST {$expediente}/seccion-07/{tipo}", ['tipo' => ['progresiones-carrera', 'ratificaciones-docentes']]],
                ["PATCH {$expediente}/seccion-07/{tipo}/{registro}", ['tipo' => ['progresiones-carrera', 'ratificaciones-docentes']]]],
            'tablas' => ['progresiones_carrera', 'ratificaciones_docentes'],
        ],
        's07.desplazamientos' => [
            'nombre' => '7.3 Desplazamiento', 'seccion' => 7, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['s07-desplazamiento'],
            'documentos' => ['S07_73B_DESIGNACION', 'S07_73C_ROTACION', 'S07_73D_DESTAQUE', 'S07_73E_ENCARGO', 'S07_73F_OTROS'],
            'rutas_escritura' => [["POST {$expediente}/seccion-07/{tipo}", ['tipo' => ['desplazamientos']]],
                ["PATCH {$expediente}/seccion-07/{tipo}/{registro}", ['tipo' => ['desplazamientos']]]],
            'tablas' => ['desplazamientos'],
        ],

        // Sección 08: la subida de la 08 es compartida; se distingue por el tipo de documento.
        's08.reconocimientos' => [
            'nombre' => 'Reconocimientos', 'seccion' => 8, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['reconocimientos'],
            'documentos' => ['REC_CARTA_FELICITACION', 'REC_LABOR_DOCENTE', 'REC_TIEMPO_SERVICIOS'],
            'rutas_escritura' => [], 'tablas' => ['reconocimientos'],
        ],
        's08.sanciones' => [
            'nombre' => 'Sanciones disciplinarias', 'seccion' => 8, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['sanciones'],
            'documentos' => ['SAN_RESOLUCION_SANCION', 'SAN_RESOLUCION_RECURSO'],
            'rutas_escritura' => [], 'tablas' => ['sanciones'],
        ],

        // Sección 09
        's09.controversias' => [
            'nombre' => 'Controversias individuales y colectivas', 'seccion' => 9, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['s09-individuales', 's09-colectivas'],
            'documentos' => ['S09_A_CONTROVERSIAS_INDIVIDUALES', 'S09_B_CONTROVERSIAS_COLECTIVAS'],
            'rutas_escritura' => ["POST {$expediente}/seccion-09/controversias", "PATCH {$expediente}/seccion-09/controversias/{registro}"],
            'tablas' => ['controversias'],
        ],
        's09.afiliaciones' => [
            'nombre' => 'Afiliación al sindicato', 'seccion' => 9, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['s09-afiliacion'],
            'documentos' => ['S09_C_AFILIACION_SINDICATO'],
            'rutas_escritura' => ["POST {$expediente}/seccion-09/afiliaciones", "PATCH {$expediente}/seccion-09/afiliaciones/{registro}"],
            'tablas' => ['afiliaciones'],
        ],

        // Sección 10
        's10.sst_bienestar' => [
            'nombre' => 'SST y bienestar social', 'seccion' => 10, 'implementada' => true, 'esencial' => null,
            'depende_de' => [], 'bloqueo' => null, 'menu' => ['s10-sst', 's10-bienestar'],
            'documentos' => ['*'],
            'rutas_escritura' => ["POST {$expediente}/seccion-10/documentos", "PATCH {$expediente}/seccion-10/documentos/{documento}"],
            'tablas' => ['registros_sst_bienestar'],
        ],

        // Sección 11
        's11.desvinculaciones' => [
            'nombre' => 'Desvinculación', 'seccion' => 11, 'implementada' => true, 'esencial' => null,
            'depende_de' => ['s02.vinculos'], 'bloqueo' => null, 'menu' => ['s11-desvinculacion', 's11-documentos'],
            'documentos' => ['*'],
            'rutas_escritura' => ["POST {$expediente}/seccion-11/desvinculaciones", "PATCH {$expediente}/seccion-11/desvinculaciones/{registro}"],
            'tablas' => ['desvinculaciones'],
        ],

        // Sección 12: sin implementar. No tiene funcionalidades.

        // Sección adicional de legajos pasivos: hoy solo el CRUD genérico de referencias.
        'adicional.referencias_pasivas' => [
            'nombre' => 'Referencias a legajos pasivos', 'seccion' => 13, 'implementada' => true, 'esencial' => null,
            'depende_de' => ['s02.vinculos'],
            'bloqueo' => ['tipo' => 'pendiente_equipo', 'motivo' => 'Falta decidir si las referencias usan folios del legajo activo.'],
            'menu' => [], 'documentos' => [], 'rutas_escritura' => $crud('referencias-legajo-pasivo'),
            'tablas' => ['referencias_legajo_pasivo'],
        ],
    ],

];
