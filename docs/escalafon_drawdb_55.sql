-- =====================================================================
-- Sistema de Escalafón Universitario — 55 tablas — versión para drawDB
-- Dialecto: PostgreSQL
-- Solo para VISUALIZAR el modelo (tablas, columnas, PK y FK).
-- Los ENUM quedan como VARCHAR con el comentario "-- enum: ..." con sus valores.
-- No incluye triggers, columnas calculadas, índices parciales ni FK compuestas
-- (las FK compuestas se dibujan como FK simples).
-- Las tablas están ordenadas por dependencia: cada tabla se crea después de las que referencia.
-- =====================================================================


-- =====================================================================
-- MÓDULO 1: Organización, usuarios y perfiles
-- =====================================================================

CREATE TABLE unidades_organicas (
    id               UUID PRIMARY KEY,
    codigo           VARCHAR(20) NOT NULL,
    nombre           VARCHAR(150) NOT NULL,
    tipo             VARCHAR(30) NOT NULL,   -- enum: facultad, escuela, departamento_academico, oficina, direccion, otro
    unidad_padre_id  UUID REFERENCES unidades_organicas(id),
    activo           BOOLEAN NOT NULL DEFAULT true,
    created_at       TIMESTAMP,
    updated_at       TIMESTAMP
);

CREATE TABLE regimenes_normativos (
    id        UUID PRIMARY KEY,
    codigo    VARCHAR(20) NOT NULL,   -- LEY_30220, DL_276, DL_728, DL_1057, LEY_30057, OTRO
    nombre    VARCHAR(150) NOT NULL,
    aplica_a  VARCHAR(15) NOT NULL,   -- enum: docente, administrativo, ambos
    activo    BOOLEAN NOT NULL DEFAULT true
);

CREATE TABLE roles (
    id                  UUID PRIMARY KEY,
    codigo              VARCHAR(40) NOT NULL,   -- servidor, responsable_legajo, jefe_orh, fiscalizador, fedatario, titular_entidad, jefe_unidad, oci, administrador_ti
    nombre              VARCHAR(100) NOT NULL,
    descripcion         TEXT,
    ve_datos_sensibles  BOOLEAN NOT NULL DEFAULT false,
    activo              BOOLEAN NOT NULL DEFAULT true
);

CREATE TABLE usuarios (
    id                        UUID PRIMARY KEY,
    tipo_doc_identidad        VARCHAR(15) NOT NULL DEFAULT 'dni',   -- enum: dni, ce, pasaporte, otro
    numero_doc_identidad      VARCHAR(20) NOT NULL,
    apellido_paterno          VARCHAR(80) NOT NULL,
    apellido_materno          VARCHAR(80),
    nombres                   VARCHAR(120) NOT NULL,
    fecha_nacimiento          DATE,
    nacionalidad              VARCHAR(60) DEFAULT 'Peruana',
    estado_civil              VARCHAR(15),   -- enum: soltero, casado, conviviente, divorciado, viudo
    domicilio                 VARCHAR(250),
    telefono                  VARCHAR(20),
    email_institucional       VARCHAR(255) NOT NULL,
    password                  VARCHAR(255) NOT NULL,
    tipo_personal             VARCHAR(15) NOT NULL,   -- enum: docente, administrativo
    regimen_laboral           VARCHAR(20) NOT NULL,   -- enum: carrera, plazo_determinado, designado
    regimen_normativo_id      UUID REFERENCES regimenes_normativos(id),
    unidad_organica_id        UUID REFERENCES unidades_organicas(id),
    nombre_puesto             VARCHAR(150),
    obligado_djbr             BOOLEAN NOT NULL DEFAULT false,
    exige_colegiatura         BOOLEAN NOT NULL DEFAULT false,
    tiene_periodo_prueba      BOOLEAN NOT NULL DEFAULT false,
    tiene_derechohabientes    BOOLEAN NOT NULL DEFAULT false,
    con_discapacidad          BOOLEAN NOT NULL DEFAULT false,
    activo                    BOOLEAN NOT NULL DEFAULT true,
    ultimo_acceso             TIMESTAMP,
    created_at                TIMESTAMP,
    updated_at                TIMESTAMP
);

CREATE TABLE usuario_roles (
    id                    UUID PRIMARY KEY,
    usuario_id            UUID NOT NULL REFERENCES usuarios(id),
    rol_id                UUID NOT NULL REFERENCES roles(id),
    ambito_tipo_personal  VARCHAR(15) NOT NULL DEFAULT 'ambos',   -- enum: docente, administrativo, ambos
    unidad_organica_id    UUID REFERENCES unidades_organicas(id),
    vigente_desde         DATE NOT NULL,
    vigente_hasta         DATE,
    asignado_por          UUID NOT NULL REFERENCES usuarios(id),
    created_at            TIMESTAMP
);

CREATE TABLE vinculos_laborales (
    id                         UUID PRIMARY KEY,
    usuario_id                 UUID NOT NULL REFERENCES usuarios(id),
    tipo_personal              VARCHAR(15) NOT NULL,   -- enum: docente, administrativo
    regimen_laboral            VARCHAR(20) NOT NULL,   -- enum: carrera, plazo_determinado, designado
    regimen_normativo_id       UUID NOT NULL REFERENCES regimenes_normativos(id),
    unidad_organica_id         UUID REFERENCES unidades_organicas(id),
    nombre_puesto              VARCHAR(150) NOT NULL,
    obligado_djbr              BOOLEAN NOT NULL DEFAULT false,
    exige_colegiatura          BOOLEAN NOT NULL DEFAULT false,
    tiene_periodo_prueba       BOOLEAN NOT NULL DEFAULT false,
    proceso_seleccion          VARCHAR(100),
    fecha_resultado_seleccion  DATE,
    numero_documento_vinculo   VARCHAR(60),   -- N° de resolución o contrato
    fecha_inicio               DATE NOT NULL,
    fecha_fin_prevista         DATE,
    fecha_cese                 DATE,
    created_at                 TIMESTAMP,
    updated_at                 TIMESTAMP
);

CREATE TABLE perfil_docente (
    id                      UUID PRIMARY KEY,
    vinculo_laboral_id      UUID NOT NULL REFERENCES vinculos_laborales(id),
    tipo_personal           VARCHAR(15) NOT NULL DEFAULT 'docente',
    condicion_docente       VARCHAR(15) NOT NULL,   -- enum: ordinario, extraordinario, contratado
    categoria               VARCHAR(10),            -- enum: principal, asociado, auxiliar (solo ordinario)
    dedicacion              VARCHAR(25),            -- enum: exclusiva, tiempo_completo, tiempo_parcial
    subtipo_extraordinario  VARCHAR(15),            -- enum: emerito, honorario, investigador, visitante, experto
    funcion_previa          VARCHAR(25),            -- enum: jefe_practica, ayudante_catedra, ayudante_laboratorio
    codigo_renacyt          VARCHAR(30),
    created_at              TIMESTAMP,
    updated_at              TIMESTAMP
);

CREATE TABLE perfil_administrativo (
    id                   UUID PRIMARY KEY,
    vinculo_laboral_id   UUID NOT NULL REFERENCES vinculos_laborales(id),
    tipo_personal        VARCHAR(15) NOT NULL DEFAULT 'administrativo',
    grupo_ocupacional    VARCHAR(15),   -- enum: profesional, tecnico, auxiliar
    nivel_carrera        VARCHAR(20),
    cargo_estructural    VARCHAR(150),
    es_cargo_confianza   BOOLEAN NOT NULL DEFAULT false,
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP
);


-- =====================================================================
-- MÓDULO 2: Catálogos del legajo
-- =====================================================================

CREATE TABLE secciones_legajo (
    id               SMALLINT PRIMARY KEY,   -- número de sección (1 a 13)
    nombre           VARCHAR(150) NOT NULL,
    descripcion      TEXT,
    es_nucleo_comun  BOOLEAN NOT NULL DEFAULT false,
    orden            SMALLINT NOT NULL
);

CREATE TABLE subsecciones_legajo (
    id                 UUID PRIMARY KEY,
    seccion_legajo_id  SMALLINT NOT NULL REFERENCES secciones_legajo(id),
    codigo             VARCHAR(10) NOT NULL,
    nombre             VARCHAR(150) NOT NULL,
    orden              SMALLINT NOT NULL DEFAULT 0
);

CREATE TABLE tipos_documento (
    id                         UUID PRIMARY KEY,
    seccion_legajo_id          SMALLINT NOT NULL REFERENCES secciones_legajo(id),
    subseccion_legajo_id       UUID REFERENCES subsecciones_legajo(id),
    codigo                     VARCHAR(40) NOT NULL,
    nombre                     VARCHAR(200) NOT NULL,
    descripcion                TEXT,
    base_normativa             VARCHAR(15) NOT NULL DEFAULT 'SERVIR',   -- enum: SERVIR, LEY_30220, UNIVERSIDAD
    aplica_carrera             BOOLEAN NOT NULL DEFAULT true,           -- bloque 1: régimen
    aplica_plazo_determinado   BOOLEAN NOT NULL DEFAULT true,
    aplica_designado           BOOLEAN NOT NULL DEFAULT true,
    requiere_docente           BOOLEAN NOT NULL DEFAULT false,          -- bloque 2: tipo de personal
    requiere_administrativo    BOOLEAN NOT NULL DEFAULT false,
    requiere_djbr              BOOLEAN NOT NULL DEFAULT false,          -- bloque 3: banderas
    requiere_colegiatura       BOOLEAN NOT NULL DEFAULT false,
    requiere_derechohabientes  BOOLEAN NOT NULL DEFAULT false,
    requiere_periodo_prueba    BOOLEAN NOT NULL DEFAULT false,
    requiere_discapacidad      BOOLEAN NOT NULL DEFAULT false,
    tiene_banderas             BOOLEAN NOT NULL DEFAULT false,          -- calculada en producción
    es_universal               BOOLEAN NOT NULL DEFAULT false,
    obligatorio                BOOLEAN NOT NULL DEFAULT true,
    acredita_perfil_puesto     BOOLEAN NOT NULL DEFAULT false,
    permite_multiples          BOOLEAN NOT NULL DEFAULT false,
    requiere_num_resolucion    BOOLEAN NOT NULL DEFAULT false,
    requiere_fedateo           BOOLEAN NOT NULL DEFAULT false,
    es_dato_sensible           BOOLEAN NOT NULL DEFAULT false,
    es_dato_salud              BOOLEAN NOT NULL DEFAULT false,
    es_hook_universidad        BOOLEAN NOT NULL DEFAULT false,
    formulario_datos           VARCHAR(40),   -- tabla de datos que se abre al subir el documento
    orden                      SMALLINT NOT NULL DEFAULT 0,
    activo                     BOOLEAN NOT NULL DEFAULT true,
    created_at                 TIMESTAMP,
    updated_at                 TIMESTAMP
);


-- =====================================================================
-- MÓDULO 3: Legajo y documentos (capa A: evidencia)
-- =====================================================================

CREATE TABLE legajos (
    id                                   UUID PRIMARY KEY,
    usuario_id                           UUID NOT NULL REFERENCES usuarios(id),
    numero_legajo                        VARCHAR(20) NOT NULL,
    estado_legajo                        VARCHAR(10) NOT NULL DEFAULT 'activo',    -- enum: activo, pasivo
    formato_legajo                       VARCHAR(10) NOT NULL DEFAULT 'digital',   -- enum: fisico, digital, mixto
    ubicacion                            VARCHAR(200),
    fecha_apertura                       DATE NOT NULL,
    fecha_pasivo                         DATE,
    fecha_transferencia_archivo_central  DATE,
    created_at                           TIMESTAMP,
    updated_at                           TIMESTAMP
);

CREATE TABLE legajo_secciones (
    id                    UUID PRIMARY KEY,
    legajo_id             UUID NOT NULL REFERENCES legajos(id),
    seccion_legajo_id     SMALLINT NOT NULL REFERENCES secciones_legajo(id),
    ultimo_folio          INT NOT NULL DEFAULT 0,
    ultima_actualizacion  TIMESTAMP
);

CREATE TABLE archivos_documento (
    id                    UUID PRIMARY KEY,
    almacenamiento        VARCHAR(30) NOT NULL DEFAULT 'local',
    bucket                VARCHAR(100),
    storage_path          VARCHAR(500) NOT NULL,
    nombre_original       VARCHAR(255) NOT NULL,
    mime_type             VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    tamano_bytes          BIGINT NOT NULL,
    hash_sha256           CHAR(64) NOT NULL,
    num_paginas           INT,
    firmado_digitalmente  BOOLEAN NOT NULL DEFAULT false,
    cifrado               BOOLEAN NOT NULL DEFAULT true,
    estado_antivirus      VARCHAR(15) NOT NULL DEFAULT 'pendiente',   -- enum: pendiente, limpio, infectado
    subido_por            UUID NOT NULL REFERENCES usuarios(id),
    subido_en             TIMESTAMP
);

CREATE TABLE documentos_usuario (
    id                        UUID PRIMARY KEY,
    usuario_id                UUID NOT NULL REFERENCES usuarios(id),
    legajo_id                 UUID NOT NULL REFERENCES legajos(id),
    tipo_documento_id         UUID NOT NULL REFERENCES tipos_documento(id),
    seccion_legajo_id         SMALLINT NOT NULL REFERENCES secciones_legajo(id),
    vinculo_laboral_id        UUID REFERENCES vinculos_laborales(id),
    archivo_id                UUID REFERENCES archivos_documento(id),
    modalidad_presentacion    VARCHAR(20),   -- enum: original, copia_simple, copia_fedateada, digital_firmado
    motivo_incorporacion      VARCHAR(300),
    folio_inicio              INT,
    folio_fin                 INT,
    fecha_recepcion           DATE,
    recepcionado_por          UUID REFERENCES usuarios(id),
    fecha_incorporacion       DATE,
    emisor                    VARCHAR(200),
    fecha_emision             DATE,
    titular_nombre            VARCHAR(200),
    titular_documento         VARCHAR(20),
    relacion_titular          VARCHAR(20) NOT NULL DEFAULT 'servidor',   -- enum: servidor, conyuge_concubino, hijo, otro
    num_resolucion            VARCHAR(60),
    estado                    VARCHAR(20) NOT NULL DEFAULT 'pendiente',  -- enum: pendiente, cargado, en_fiscalizacion, verificado, observado, falso
    fedateado                 BOOLEAN NOT NULL DEFAULT false,
    fecha_fedateo             DATE,
    fedatario_id              UUID REFERENCES usuarios(id),
    fiscalizado               BOOLEAN NOT NULL DEFAULT false,
    fecha_fiscalizacion       DATE,
    fiscalizado_por           UUID REFERENCES usuarios(id),
    subsana_a_documento_id    UUID REFERENCES documentos_usuario(id),
    created_by                UUID NOT NULL REFERENCES usuarios(id),
    created_at                TIMESTAMP,
    updated_at                TIMESTAMP
);

CREATE TABLE vinculo_adendas (
    id                  UUID PRIMARY KEY,
    vinculo_laboral_id  UUID NOT NULL REFERENCES vinculos_laborales(id),
    numero              VARCHAR(40),
    motivo              VARCHAR(200),
    fecha_inicio        DATE NOT NULL,
    fecha_fin           DATE NOT NULL,
    documento_id        UUID REFERENCES documentos_usuario(id),
    registrado_por      UUID NOT NULL REFERENCES usuarios(id),
    created_at          TIMESTAMP
);

CREATE TABLE compromisos_entrega_originales (
    id                  UUID PRIMARY KEY,
    usuario_id          UUID NOT NULL REFERENCES usuarios(id),
    legajo_id           UUID NOT NULL REFERENCES legajos(id),
    motivo_impedimento  TEXT NOT NULL,
    fecha_compromiso    DATE NOT NULL,
    fecha_limite        DATE NOT NULL,
    estado              VARCHAR(15) NOT NULL DEFAULT 'vigente',   -- enum: vigente, cumplido, incumplido
    fecha_cumplimiento  DATE,
    archivo_id          UUID REFERENCES archivos_documento(id),
    registrado_por      UUID NOT NULL REFERENCES usuarios(id),
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE TABLE compromiso_documentos (
    id                 UUID PRIMARY KEY,
    compromiso_id      UUID NOT NULL REFERENCES compromisos_entrega_originales(id),
    tipo_documento_id  UUID NOT NULL REFERENCES tipos_documento(id),
    descripcion        VARCHAR(200),
    documento_id       UUID REFERENCES documentos_usuario(id),
    entregado_en       DATE
);

CREATE TABLE referencias_legajo_pasivo (
    id                     UUID PRIMARY KEY,
    legajo_id              UUID NOT NULL REFERENCES legajos(id),
    vinculo_laboral_id     UUID NOT NULL REFERENCES vinculos_laborales(id),
    tipo_documento_id      UUID NOT NULL REFERENCES tipos_documento(id),
    folio_inicio_pasivo    INT NOT NULL,
    folio_fin_pasivo       INT NOT NULL,
    fedateado              BOOLEAN NOT NULL DEFAULT false,
    fecha_fedateo          DATE,
    fiscalizado            BOOLEAN NOT NULL DEFAULT false,
    fecha_fiscalizacion    DATE,
    responsable_legajo_id  UUID NOT NULL REFERENCES usuarios(id),
    created_at             TIMESTAMP
);


-- =====================================================================
-- MÓDULO 4: Datos por sección (capa B: información)
-- Todas incluyen las columnas estándar: usuario_id, documento_id (respaldo),
-- registrado_por, anulado, motivo_anulacion, created_at, updated_at.
-- =====================================================================

-- ---- Sección 01 ----
CREATE TABLE familiares (   -- sensible
    id                    UUID PRIMARY KEY,
    usuario_id            UUID NOT NULL REFERENCES usuarios(id),
    documento_id          UUID REFERENCES documentos_usuario(id),
    parentesco            VARCHAR(15) NOT NULL,   -- enum: conyuge, concubino, hijo, otro
    tipo_doc_identidad    VARCHAR(15),            -- enum: dni, ce, pasaporte, otro
    numero_doc_identidad  VARCHAR(20),
    apellidos_nombres     VARCHAR(200) NOT NULL,
    fecha_nacimiento      DATE,
    es_derechohabiente    BOOLEAN NOT NULL DEFAULT true,
    registrado_por        UUID NOT NULL REFERENCES usuarios(id),
    anulado               BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion      TEXT,
    created_at            TIMESTAMP,
    updated_at            TIMESTAMP
);

CREATE TABLE declaraciones_verificaciones (
    id                UUID PRIMARY KEY,
    usuario_id        UUID NOT NULL REFERENCES usuarios(id),
    documento_id      UUID REFERENCES documentos_usuario(id),
    tipo              VARCHAR(25) NOT NULL,   -- enum: antecedente_penal, antecedente_judicial, antecedente_policial, impedimentos_rnssc, impedimentos_redjum, djbr, dj_intereses, dj_no_impedimentos, otra_dj
    anio              INT,
    fecha             DATE NOT NULL,
    resultado         VARCHAR(15) NOT NULL,   -- enum: presentada, sin_registros, con_registros, no_aplica
    vigente_hasta     DATE,
    observacion       VARCHAR(300),
    registrado_por    UUID NOT NULL REFERENCES usuarios(id),
    anulado           BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion  TEXT,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

CREATE TABLE certificados_discapacidad (   -- sensible
    id                UUID PRIMARY KEY,
    usuario_id        UUID NOT NULL REFERENCES usuarios(id),
    documento_id      UUID REFERENCES documentos_usuario(id),
    persona           VARCHAR(10) NOT NULL,   -- enum: servidor, familiar
    familiar_id       UUID REFERENCES familiares(id),
    numero_conadis    VARCHAR(30) NOT NULL,
    fecha_emision     DATE,
    vigente_hasta     DATE,
    registrado_por    UUID NOT NULL REFERENCES usuarios(id),
    anulado           BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion  TEXT,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

-- ---- Sección 02 ----
CREATE TABLE inducciones (
    id                  UUID PRIMARY KEY,
    usuario_id          UUID NOT NULL REFERENCES usuarios(id),
    documento_id        UUID REFERENCES documentos_usuario(id),
    vinculo_laboral_id  UUID NOT NULL REFERENCES vinculos_laborales(id),
    tipo                VARCHAR(15) NOT NULL,   -- enum: general, especifica
    fecha               DATE NOT NULL,
    responsable         VARCHAR(150),
    registrado_por      UUID NOT NULL REFERENCES usuarios(id),
    anulado             BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion    TEXT,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE TABLE periodos_prueba (
    id                  UUID PRIMARY KEY,
    usuario_id          UUID NOT NULL REFERENCES usuarios(id),
    documento_id        UUID REFERENCES documentos_usuario(id),
    vinculo_laboral_id  UUID NOT NULL REFERENCES vinculos_laborales(id),
    fecha_inicio        DATE NOT NULL,
    fecha_fin           DATE NOT NULL,
    resultado           VARCHAR(15) NOT NULL DEFAULT 'pendiente',   -- enum: pendiente, aprobado, no_aprobado
    fecha_resultado     DATE,
    registrado_por      UUID NOT NULL REFERENCES usuarios(id),
    anulado             BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion    TEXT,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

-- ---- Sección 03 ----
CREATE TABLE formacion_academica (
    id                      UUID PRIMARY KEY,
    usuario_id              UUID NOT NULL REFERENCES usuarios(id),
    documento_id            UUID REFERENCES documentos_usuario(id),
    nivel                   VARCHAR(30) NOT NULL,   -- enum: educacion_basica, tecnico, bachiller, titulo_profesional, diplomado_especializacion, maestria, doctorado
    denominacion            VARCHAR(200) NOT NULL,
    institucion             VARCHAR(200) NOT NULL,
    pais                    VARCHAR(60) NOT NULL DEFAULT 'Perú',
    es_revalidado           BOOLEAN NOT NULL DEFAULT false,
    fecha_inicio            DATE,
    fecha_obtencion         DATE,
    numero_registro_sunedu  VARCHAR(40),
    numero_diploma          VARCHAR(40),
    registrado_por          UUID NOT NULL REFERENCES usuarios(id),
    anulado                 BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion        TEXT,
    created_at              TIMESTAMP,
    updated_at              TIMESTAMP
);

CREATE TABLE colegiaturas (
    id                   UUID PRIMARY KEY,
    usuario_id           UUID NOT NULL REFERENCES usuarios(id),
    documento_id         UUID REFERENCES documentos_usuario(id),
    colegio_profesional  VARCHAR(150) NOT NULL,
    numero_colegiatura   VARCHAR(30) NOT NULL,
    fecha_colegiatura    DATE,
    habilitado           BOOLEAN NOT NULL DEFAULT true,
    fecha_verificacion   DATE,
    vigente_hasta        DATE,
    registrado_por       UUID NOT NULL REFERENCES usuarios(id),
    anulado              BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion     TEXT,
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP
);

CREATE TABLE capacitaciones (
    id                UUID PRIMARY KEY,
    usuario_id        UUID NOT NULL REFERENCES usuarios(id),
    documento_id      UUID REFERENCES documentos_usuario(id),
    tipo              VARCHAR(25) NOT NULL,   -- enum: curso, programa_especializacion, congreso, seminario, taller, otro
    nombre            VARCHAR(250) NOT NULL,
    entidad           VARCHAR(200),
    horas             INT,
    fecha_inicio      DATE,
    fecha_fin         DATE,
    registrado_por    UUID NOT NULL REFERENCES usuarios(id),
    anulado           BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion  TEXT,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

-- ---- Sección 04 ----
CREATE TABLE experiencia_laboral (
    id                UUID PRIMARY KEY,
    usuario_id        UUID NOT NULL REFERENCES usuarios(id),
    documento_id      UUID REFERENCES documentos_usuario(id),
    entidad_empresa   VARCHAR(200) NOT NULL,
    sector            VARCHAR(10) NOT NULL,   -- enum: publico, privado
    cargo             VARCHAR(150) NOT NULL,
    funciones         TEXT,
    fecha_inicio      DATE NOT NULL,
    fecha_fin         DATE,   -- NULL = sigue vigente
    registrado_por    UUID NOT NULL REFERENCES usuarios(id),
    anulado           BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion  TEXT,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

-- ---- Sección 05 ----
CREATE TABLE licencias_permisos (   -- sensible cuando es descanso médico (solo fechas y días)
    id                UUID PRIMARY KEY,
    usuario_id        UUID NOT NULL REFERENCES usuarios(id),
    documento_id      UUID REFERENCES documentos_usuario(id),
    tipo              VARCHAR(30) NOT NULL,   -- enum: permiso, suspension, descanso_medico, licencia_maternidad, licencia_paternidad, lactancia, adopcion, sindical, familiar_grave, comite_sst, asistencia_medica_familiar, bombero_voluntario, otra
    forma_permiso     VARCHAR(15),            -- enum: papeleta, memorandum, otro (solo si tipo = permiso; migración 2026_10_05_120000)
    con_goce          BOOLEAN NOT NULL DEFAULT true,
    fecha_inicio      DATE NOT NULL,
    fecha_fin         DATE NOT NULL,
    dias              INT NOT NULL,
    numero_documento  VARCHAR(60),
    registrado_por    UUID NOT NULL REFERENCES usuarios(id),
    anulado           BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion  TEXT,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

CREATE TABLE periodos_vacacionales (
    id                  UUID PRIMARY KEY,
    usuario_id          UUID NOT NULL REFERENCES usuarios(id),
    documento_id        UUID REFERENCES documentos_usuario(id),
    vinculo_laboral_id  UUID NOT NULL REFERENCES vinculos_laborales(id),
    periodo_anio        INT NOT NULL,
    dias_derecho        INT NOT NULL DEFAULT 30,
    registrado_por      UUID NOT NULL REFERENCES usuarios(id),
    anulado             BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion    TEXT,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE TABLE goces_vacacionales (
    id                     UUID PRIMARY KEY,
    usuario_id             UUID NOT NULL REFERENCES usuarios(id),
    documento_id           UUID REFERENCES documentos_usuario(id),
    periodo_vacacional_id  UUID NOT NULL REFERENCES periodos_vacacionales(id),
    tipo                   VARCHAR(15) NOT NULL,   -- enum: goce, adelanto, postergacion
    fecha_inicio           DATE NOT NULL,
    fecha_fin              DATE NOT NULL,
    dias                   INT NOT NULL,
    numero_documento       VARCHAR(60),
    registrado_por         UUID NOT NULL REFERENCES usuarios(id),
    anulado                BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion       TEXT,
    created_at             TIMESTAMP,
    updated_at             TIMESTAMP
);

-- ---- Sección 06 ----
CREATE TABLE tiempo_servicios_reconocidos (
    id                 UUID PRIMARY KEY,
    usuario_id         UUID NOT NULL REFERENCES usuarios(id),
    documento_id       UUID REFERENCES documentos_usuario(id),
    numero_resolucion  VARCHAR(60) NOT NULL,
    fecha_resolucion   DATE NOT NULL,
    anios              INT NOT NULL DEFAULT 0,
    meses              INT NOT NULL DEFAULT 0,
    dias               INT NOT NULL DEFAULT 0,
    registrado_por     UUID NOT NULL REFERENCES usuarios(id),
    anulado            BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion   TEXT,
    created_at         TIMESTAMP,
    updated_at         TIMESTAMP
);

CREATE TABLE compensaciones_beneficios (
    id                 UUID PRIMARY KEY,
    usuario_id         UUID NOT NULL REFERENCES usuarios(id),
    documento_id       UUID REFERENCES documentos_usuario(id),
    tipo               VARCHAR(15) NOT NULL,   -- enum: economica, no_economica
    concepto           VARCHAR(200) NOT NULL,
    fecha              DATE NOT NULL,
    numero_documento   VARCHAR(60),
    registrado_por     UUID NOT NULL REFERENCES usuarios(id),
    anulado            BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion   TEXT,
    created_at         TIMESTAMP,
    updated_at         TIMESTAMP
);

-- ---- Sección 07 ----
CREATE TABLE evaluaciones_desempeno (
    id                UUID PRIMARY KEY,
    usuario_id        UUID NOT NULL REFERENCES usuarios(id),
    documento_id      UUID REFERENCES documentos_usuario(id),
    ciclo_anio        INT NOT NULL,
    fecha_evaluacion  DATE,
    calificacion      VARCHAR(40) NOT NULL,
    puntaje           NUMERIC(5,2),
    plan_mejora       BOOLEAN NOT NULL DEFAULT false,
    evaluador_id      UUID REFERENCES usuarios(id),
    registrado_por    UUID NOT NULL REFERENCES usuarios(id),
    anulado           BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion  TEXT,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

CREATE TABLE desplazamientos (
    id                  UUID PRIMARY KEY,
    usuario_id          UUID NOT NULL REFERENCES usuarios(id),
    documento_id        UUID REFERENCES documentos_usuario(id),
    tipo                VARCHAR(20) NOT NULL,   -- enum: designacion, rotacion, destaque, encargo_puesto, encargo_funciones, otro
    unidad_origen_id    UUID REFERENCES unidades_organicas(id),
    unidad_destino_id   UUID REFERENCES unidades_organicas(id),
    puesto_destino      VARCHAR(150),
    fecha_inicio        DATE NOT NULL,
    fecha_fin           DATE,
    numero_documento    VARCHAR(60),
    registrado_por      UUID NOT NULL REFERENCES usuarios(id),
    anulado             BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion    TEXT,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE TABLE progresiones_carrera (   -- administrativos
    id                  UUID PRIMARY KEY,
    usuario_id          UUID NOT NULL REFERENCES usuarios(id),
    documento_id        UUID REFERENCES documentos_usuario(id),
    vinculo_laboral_id  UUID NOT NULL REFERENCES vinculos_laborales(id),
    nivel_anterior      VARCHAR(20),
    nivel_nuevo         VARCHAR(20) NOT NULL,
    fecha_efectiva      DATE NOT NULL,
    numero_resolucion   VARCHAR(60) NOT NULL,
    registrado_por      UUID NOT NULL REFERENCES usuarios(id),
    anulado             BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion    TEXT,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE TABLE ratificaciones_docentes (   -- docentes
    id                          UUID PRIMARY KEY,
    usuario_id                  UUID NOT NULL REFERENCES usuarios(id),
    documento_id                UUID REFERENCES documentos_usuario(id),
    perfil_docente_id           UUID NOT NULL REFERENCES perfil_docente(id),
    tipo                        VARCHAR(15) NOT NULL,   -- enum: nombramiento, ratificacion, promocion
    categoria_anterior          VARCHAR(10),            -- enum: principal, asociado, auxiliar
    categoria_nueva             VARCHAR(10) NOT NULL,   -- enum: principal, asociado, auxiliar
    numero_resolucion           VARCHAR(60) NOT NULL,
    fecha_resolucion            DATE NOT NULL,
    fecha_inicio_periodo        DATE NOT NULL,
    fecha_vencimiento_periodo   DATE NOT NULL,
    resultado                   VARCHAR(15) NOT NULL DEFAULT 'pendiente',   -- enum: pendiente, ratificado, promovido, no_ratificado
    registrado_por              UUID NOT NULL REFERENCES usuarios(id),
    anulado                     BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion            TEXT,
    created_at                  TIMESTAMP,
    updated_at                  TIMESTAMP
);

-- ---- Sección 08 ----
CREATE TABLE reconocimientos (
    id                UUID PRIMARY KEY,
    usuario_id        UUID NOT NULL REFERENCES usuarios(id),
    documento_id      UUID REFERENCES documentos_usuario(id),
    tipo              VARCHAR(20) NOT NULL,   -- enum: felicitacion, condecoracion, diploma, labor_docente, tiempo_servicios, otro
    fecha             DATE NOT NULL,
    motivo            VARCHAR(300) NOT NULL,
    otorgado_por      VARCHAR(150),
    numero_documento  VARCHAR(60),
    registrado_por    UUID NOT NULL REFERENCES usuarios(id),
    anulado           BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion  TEXT,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

CREATE TABLE sanciones (
    id                       UUID PRIMARY KEY,
    usuario_id               UUID NOT NULL REFERENCES usuarios(id),
    documento_id             UUID REFERENCES documentos_usuario(id),
    tipo                     VARCHAR(25) NOT NULL,   -- enum: amonestacion_escrita, suspension, destitucion
    numero_resolucion        VARCHAR(60) NOT NULL,
    fecha_resolucion         DATE NOT NULL,
    falta_imputada           VARCHAR(300),
    fecha_inicio             DATE,
    fecha_fin                DATE,
    dias_suspension          INT,
    resultado_recurso        VARCHAR(15) NOT NULL DEFAULT 'sin_recurso',   -- enum: sin_recurso, pendiente, fundado, infundado, improcedente
    fecha_resultado_recurso  DATE,
    registrada_en_rnssc      BOOLEAN NOT NULL DEFAULT false,
    registrado_por           UUID NOT NULL REFERENCES usuarios(id),
    anulado                  BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion         TEXT,
    created_at               TIMESTAMP,
    updated_at               TIMESTAMP
);

-- ---- Sección 09 ----
CREATE TABLE controversias (
    id                UUID PRIMARY KEY,
    usuario_id        UUID NOT NULL REFERENCES usuarios(id),
    documento_id      UUID REFERENCES documentos_usuario(id),
    tipo              VARCHAR(15) NOT NULL,   -- enum: individual, colectiva
    instancia         VARCHAR(25) NOT NULL,   -- enum: entidad, tribunal_servicio_civil, poder_judicial, otra
    materia           VARCHAR(300) NOT NULL,
    fecha_inicio      DATE NOT NULL,
    estado            VARCHAR(15) NOT NULL DEFAULT 'en_tramite',   -- enum: en_tramite, resuelta, archivada
    fecha_resolucion  DATE,
    resultado         VARCHAR(300),
    registrado_por    UUID NOT NULL REFERENCES usuarios(id),
    anulado           BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion  TEXT,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

CREATE TABLE afiliaciones (   -- sensible
    id                 UUID PRIMARY KEY,
    usuario_id         UUID NOT NULL REFERENCES usuarios(id),
    documento_id       UUID REFERENCES documentos_usuario(id),
    tipo               VARCHAR(15) NOT NULL,   -- enum: sindicato, eps, seguro_social, otro
    entidad            VARCHAR(150) NOT NULL,
    numero_afiliacion  VARCHAR(40),
    fecha_inicio       DATE NOT NULL,
    fecha_fin          DATE,
    registrado_por     UUID NOT NULL REFERENCES usuarios(id),
    anulado            BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion   TEXT,
    created_at         TIMESTAMP,
    updated_at         TIMESTAMP
);

-- ---- Sección 10 ----
CREATE TABLE registros_sst_bienestar (   -- sensible en accidente, enfermedad, examen y trámites
    id                UUID PRIMARY KEY,
    usuario_id        UUID NOT NULL REFERENCES usuarios(id),
    documento_id      UUID REFERENCES documentos_usuario(id),
    tipo              VARCHAR(30) NOT NULL,   -- enum: entrega_reglamento_sst, entrenamiento_simulacro, capacitacion_sst, accidente_trabajo, enfermedad_ocupacional, examen_medico_realizado, tramite_seguro, tramite_subsidio
    fecha             DATE NOT NULL,
    descripcion       VARCHAR(300),
    estado_tramite    VARCHAR(15) NOT NULL DEFAULT 'no_aplica',   -- enum: no_aplica, en_tramite, aprobado, denegado
    registrado_por    UUID NOT NULL REFERENCES usuarios(id),
    anulado           BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion  TEXT,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

-- ---- Sección 11 ----
CREATE TABLE desvinculaciones (
    id                          UUID PRIMARY KEY,
    usuario_id                  UUID NOT NULL REFERENCES usuarios(id),
    documento_id                UUID REFERENCES documentos_usuario(id),
    vinculo_laboral_id          UUID NOT NULL REFERENCES vinculos_laborales(id),
    motivo_cese                 VARCHAR(25) NOT NULL,   -- enum: renuncia, termino_designacion, termino_contrato, no_renovacion, destitucion, jubilacion, fallecimiento, otro
    fecha_cese                  DATE NOT NULL,
    numero_resolucion           VARCHAR(60),
    fecha_entrega_cargo         DATE,
    informe_gestion_presentado  BOOLEAN NOT NULL DEFAULT false,
    fecha_liquidacion           DATE,
    observaciones               TEXT,
    registrado_por              UUID NOT NULL REFERENCES usuarios(id),
    anulado                     BOOLEAN NOT NULL DEFAULT false,
    motivo_anulacion            TEXT,
    created_at                  TIMESTAMP,
    updated_at                  TIMESTAMP
);


-- =====================================================================
-- MÓDULO 5: Requerimientos, observaciones y fiscalización
-- =====================================================================

CREATE TABLE requerimientos_documentos (
    id                   UUID PRIMARY KEY,
    usuario_id           UUID NOT NULL REFERENCES usuarios(id),
    tipo_documento_id    UUID REFERENCES tipos_documento(id),
    origen               VARCHAR(15) NOT NULL,   -- enum: apertura, actualizacion, fiscalizacion, otro
    descripcion          VARCHAR(300) NOT NULL,
    fecha_requerimiento  DATE NOT NULL,
    plazo_dias_habiles   INT NOT NULL,
    fecha_limite         DATE NOT NULL,
    prorroga_hasta       DATE,
    estado               VARCHAR(15) NOT NULL DEFAULT 'abierto',   -- enum: abierto, cumplido, vencido, cancelado
    documento_id         UUID REFERENCES documentos_usuario(id),
    solicitado_por       UUID NOT NULL REFERENCES usuarios(id),
    cerrado_en           TIMESTAMP,
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP
);

CREATE TABLE observaciones_documento (
    id                        UUID PRIMARY KEY,
    documento_id              UUID NOT NULL REFERENCES documentos_usuario(id),
    tipo_observacion          VARCHAR(30) NOT NULL,   -- enum: ilegible, incompleto, sin_firma_o_sello, vencido, no_corresponde_al_tipo, datos_inconsistentes, folios_incorrectos, otro
    descripcion               TEXT NOT NULL,
    estado                    VARCHAR(15) NOT NULL DEFAULT 'abierta',   -- enum: abierta, subsanada, vencida, anulada
    registrada_por            UUID NOT NULL REFERENCES usuarios(id),
    fecha_registro            TIMESTAMP,
    plazo_subsanacion         DATE NOT NULL,
    prorroga_hasta            DATE,
    documento_subsanacion_id  UUID REFERENCES documentos_usuario(id),
    fecha_subsanacion         TIMESTAMP,
    verificada_por            UUID REFERENCES usuarios(id),
    comentario_cierre         TEXT,
    created_at                TIMESTAMP,
    updated_at                TIMESTAMP
);

CREATE TABLE procesos_fiscalizacion (
    id                          UUID PRIMARY KEY,
    usuario_id                  UUID NOT NULL REFERENCES usuarios(id),
    legajo_id                   UUID NOT NULL REFERENCES legajos(id),
    tipo_muestra                VARCHAR(25) NOT NULL,   -- enum: total_confianza, muestreo_ordinario, a_solicitud
    fecha_vinculacion           DATE NOT NULL,
    fecha_inicio                DATE NOT NULL,
    fecha_limite                DATE NOT NULL,
    fecha_fin                   DATE,
    estado                      VARCHAR(15) NOT NULL DEFAULT 'abierto',   -- enum: abierto, en_curso, concluido
    responsable_id              UUID NOT NULL REFERENCES usuarios(id),
    informe_documento_id        UUID REFERENCES documentos_usuario(id),
    conclusiones                TEXT,
    comunicado_procuraduria_en  DATE,
    comunicado_stpad_en         DATE,
    created_at                  TIMESTAMP,
    updated_at                  TIMESTAMP
);

CREATE TABLE solicitudes_fiscalizacion (
    id                        UUID PRIMARY KEY,
    proceso_fiscalizacion_id  UUID NOT NULL REFERENCES procesos_fiscalizacion(id),
    documento_id              UUID NOT NULL REFERENCES documentos_usuario(id),
    entidad_consultada        VARCHAR(200) NOT NULL,
    medio                     VARCHAR(30) NOT NULL,   -- enum: oficio, carta, correo_electronico, colaboracion_entre_entidades
    numero_oficio             VARCHAR(40),
    fecha_envio               DATE NOT NULL,
    oficio_archivo_id         UUID REFERENCES archivos_documento(id),
    fecha_respuesta           DATE,
    documento_respuesta_id    UUID REFERENCES documentos_usuario(id),
    resultado                 VARCHAR(15) NOT NULL DEFAULT 'pendiente',   -- enum: pendiente, conforme, inexacto, falso, sin_respuesta
    descripcion_hallazgo      TEXT,
    registrado_por            UUID NOT NULL REFERENCES usuarios(id),
    created_at                TIMESTAMP,
    updated_at                TIMESTAMP
);


-- =====================================================================
-- MÓDULO 6: Acceso y custodia
-- =====================================================================

CREATE TABLE solicitudes_acceso_legajo (
    id                           UUID PRIMARY KEY,
    legajo_id                    UUID NOT NULL REFERENCES legajos(id),
    solicitante_usuario_id       UUID REFERENCES usuarios(id),
    solicitante_nombre           VARCHAR(200) NOT NULL,
    solicitante_documento        VARCHAR(20) NOT NULL,
    entidad_procedencia          VARCHAR(200),
    tipo_solicitante             VARCHAR(20) NOT NULL,   -- enum: titular, titular_entidad, jefe_linea_directa, oci, entidad_destino, otro
    detalle_requerido            TEXT NOT NULL,
    sustento                     TEXT NOT NULL,
    forma_entrega                VARCHAR(10) NOT NULL,   -- enum: virtual, fisica
    incluye_datos_sensibles      BOOLEAN NOT NULL DEFAULT false,
    compromiso_confidencialidad  BOOLEAN NOT NULL DEFAULT false,
    estado                       VARCHAR(15) NOT NULL DEFAULT 'pendiente',   -- enum: pendiente, aprobada, denegada, atendida
    resuelta_por                 UUID REFERENCES usuarios(id),
    fecha_resolucion             TIMESTAMP,
    motivo_resolucion            TEXT,
    vigente_hasta                DATE,
    created_at                   TIMESTAMP,
    updated_at                   TIMESTAMP
);

CREATE TABLE bitacora_accesos_legajo (
    id                   UUID PRIMARY KEY,
    legajo_id            UUID NOT NULL REFERENCES legajos(id),
    documento_id         UUID REFERENCES documentos_usuario(id),
    usuario_id           UUID NOT NULL REFERENCES usuarios(id),
    solicitud_acceso_id  UUID REFERENCES solicitudes_acceso_legajo(id),
    accion               VARCHAR(15) NOT NULL,   -- enum: ver, descargar, imprimir
    ip_origen            VARCHAR(45),
    user_agent           VARCHAR(300),
    fecha_acceso         TIMESTAMP
);

CREATE TABLE movimientos_custodia_legajo (
    id                           UUID PRIMARY KEY,
    legajo_id                    UUID NOT NULL REFERENCES legajos(id),
    tipo_movimiento              VARCHAR(35) NOT NULL,   -- enum: salida_temporal, transferencia_archivo_central, transferencia_otra_entidad, copia_entidad_destino, reconstruccion
    base_autorizacion            VARCHAR(30),            -- enum: orden_judicial, autorizacion_jefe_orh, inventario_transferencia, otro
    autorizado_por               UUID NOT NULL REFERENCES usuarios(id),
    entidad_destino              VARCHAR(200),
    numero_documento_sustento    VARCHAR(60),
    sustento_archivo_id          UUID REFERENCES archivos_documento(id),
    formato_legajo               VARCHAR(10),            -- enum: fisico, digital, mixto
    numero_inventario            VARCHAR(40),
    fecha_movimiento             DATE NOT NULL,
    fecha_retorno                DATE,
    servidor_comunicado          BOOLEAN NOT NULL DEFAULT false,
    fecha_comunicacion_servidor  DATE,
    observaciones                TEXT,
    created_at                   TIMESTAMP
);


-- =====================================================================
-- MÓDULO 7: Auditoría y notificaciones
-- =====================================================================

CREATE TABLE historial_estados_documento (
    id                    UUID PRIMARY KEY,
    documento_usuario_id  UUID NOT NULL REFERENCES documentos_usuario(id),
    estado_anterior       VARCHAR(20),            -- enum estado_documento (vacío en el primer registro)
    estado_nuevo          VARCHAR(20) NOT NULL,   -- enum estado_documento
    cambiado_por          UUID NOT NULL REFERENCES usuarios(id),
    motivo                TEXT,
    observacion_id        UUID REFERENCES observaciones_documento(id),
    ip_origen             VARCHAR(45),
    fecha_cambio          TIMESTAMP
);

CREATE TABLE auditoria_eventos (
    id                  UUID PRIMARY KEY,
    fecha_evento        TIMESTAMP,
    actor_usuario_id    UUID REFERENCES usuarios(id),
    usuario_titular_id  UUID REFERENCES usuarios(id),
    accion              VARCHAR(40) NOT NULL,
    entidad             VARCHAR(60) NOT NULL,
    entidad_id          UUID NOT NULL,
    datos_antes         TEXT,   -- JSON
    datos_despues       TEXT,   -- JSON
    ip_origen           VARCHAR(45),
    user_agent          VARCHAR(300),
    request_id          UUID,
    hash_previo         CHAR(64),
    hash_registro       CHAR(64) NOT NULL
);

CREATE TABLE notificaciones_usuario (
    id                UUID PRIMARY KEY,
    usuario_id        UUID NOT NULL REFERENCES usuarios(id),
    tipo              VARCHAR(30) NOT NULL,   -- enum: observacion, requerimiento, plazo_por_vencer, cambio_estado, acceso_legajo, vencimiento_vinculo, ratificacion_docente
    titulo            VARCHAR(150) NOT NULL,
    mensaje           TEXT NOT NULL,
    documento_id      UUID REFERENCES documentos_usuario(id),
    observacion_id    UUID REFERENCES observaciones_documento(id),
    requerimiento_id  UUID REFERENCES requerimientos_documentos(id),
    leida             BOOLEAN NOT NULL DEFAULT false,
    fecha_lectura     TIMESTAMP,
    created_at        TIMESTAMP
);


-- =====================================================================
-- MÓDULO 8: Configuración
-- =====================================================================

CREATE TABLE parametros_sistema (
    id               UUID PRIMARY KEY,
    clave            VARCHAR(80) NOT NULL,
    valor            VARCHAR(200) NOT NULL,
    tipo_dato        VARCHAR(10) NOT NULL,   -- enum: int, decimal, bool, text
    descripcion      VARCHAR(300),
    actualizado_por  UUID REFERENCES usuarios(id),
    updated_at       TIMESTAMP
);

CREATE TABLE feriados (
    id           UUID PRIMARY KEY,
    fecha        DATE NOT NULL,
    descripcion  VARCHAR(120) NOT NULL
);
