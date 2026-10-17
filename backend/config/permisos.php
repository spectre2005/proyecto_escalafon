<?php

/*
| Permisos del sistema.
|
| El backend valida cada ruta con can:<permiso> (Gate). El frontend recibe
| la lista calculada en /auth/login y /auth/me y solo la usa para mostrar
| u ocultar opciones.
|
| Los permisos de un usuario salen de sus roles vigentes (usuario_roles con
| vigente_desde <= hoy <= vigente_hasta, y roles.activo = true).
*/

return [

    // Catálogo cerrado: un permiso que no esté aquí no existe.
    'catalogo' => [
        'personal.ver' => 'Ver la lista del personal.',
        'usuarios.gestionar' => 'Crear, editar y desactivar personal y cuentas.',
        'legajo.ver' => 'Ver el legajo de una persona.',
        'legajo.editar' => 'Llenar y corregir datos del legajo (datos generales, foto, familiares).',
        'legajo.abrir' => 'Abrir el legajo de una persona que no tiene uno activo.',
        'documento.subir' => 'Subir documentos al legajo.',
        'documento.recepcionar' => 'Registrar la recepción de un documento.',
        'documento.revisar' => 'Aceptar u observar documentos de formación académica, experiencia laboral, compensaciones y SST/bienestar (RR. HH.).',
        'seccion07.ver' => 'Ver la sección 07: evaluación, progresión y desplazamiento.',
        'seccion07.registrar' => 'Registrar, corregir y anular datos de la sección 07 desde el legajo (anular exige además sistema.administrar).',
        'seccion07.ver_calificacion' => 'Ver calificaciones y puntajes de evaluación de desempeño.',
        'seccion08.sanciones.ver' => 'Ver sanciones disciplinarias y sus documentos en la sección 08.',
        'seccion08.sanciones.registrar' => 'Registrar sanciones y recursos impugnatorios en la sección 08.',
        'seccion08.sanciones.revisar' => 'Aceptar u observar documentos de sanciones en la sección 08.',
        'datos_sensibles.ver' => 'Ver datos sensibles y de salud (secciones 01, 09 y 10).',
        // Qué puede hacer el asistente con este dato está pendiente del cliente: de momento no lo tiene.
        'discapacidad.editar' => 'Registrar o cambiar si la persona tiene discapacidad (el servidor o alguno de sus hijos). Cada cambio queda en la auditoría.',
        'sistema.administrar' => 'Configuración, catálogos y herramientas internas de la ORH.',
        'configuracion.modulos' => 'Activar o desactivar funcionalidades configurables, con motivo y auditoría.',
    ],

    // Permisos que no se asignan por rol, sino que salen de una columna de roles.
    'derivados' => [
        'datos_sensibles.ver' => 've_datos_sensibles',
    ],

    // Pendientes de decisión del cliente: no se asignan a ningún rol, ni siquiera ADMIN.
    'pendientes' => [
        'seccion07.ver_calificacion',
    ],

    // Asignación explícita: no los da el comodín '*'; solo un rol que los nombre en su lista
    // (docs/decisiones-configuracion-secciones.md, sección 8).
    'explicitos' => [
        'configuracion.modulos',
    ],

    // Código de rol (roles.codigo) => permisos. '*' = todo el catálogo menos
    // los derivados, los pendientes y los explícitos; un explícito se suma nombrándolo junto a '*'.
    'roles' => [
        // El administrador de cada institución (jefe de la ORH) también configura sus funcionalidades
        // (decisión del 17/10/2026). Cada cambio exige motivo y queda auditado.
        'ADMIN' => ['*', 'configuracion.modulos'],

        // Rol adicional para que otra persona, sin ser ADMIN, cambie la configuración de funcionalidades. No
        // existe en la base ni en los seeders: se asigna expresamente.
        'configuracion_modulos' => [
            'configuracion.modulos',
        ],

        // Pendiente de confirmar con el cliente: el rol no existe en la base
        // ni en los seeders. Basado en la tabla "Límites del asistente".
        'asistente_legajo' => [
            'personal.ver',
            'legajo.ver',
            'legajo.editar',
            'documento.subir',
            'documento.recepcionar',
            'seccion07.ver',
        ],
    ],

];
