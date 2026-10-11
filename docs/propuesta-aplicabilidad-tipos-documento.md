# Propuesta: a quién corresponde cada tipo de documento

- **Estado:** [PROPUESTA] para validar con el equipo y con la universidad. **No está cargada** en el sistema: hoy todo el catálogo tiene `aplica_tipo_personal = ambos`.
- **Fecha:** 09/10/2026. Catálogo de la rama `feature/configuracion-secciones` (87 tipos activos, secciones 01 a 11).
- **Para qué sirve:** la etapa B ya filtra los tipos de documento que se ofrecen para cargar según la persona (`App\Support\AplicacionDocumental`). Este documento propone los valores de esa clasificación. Una vez validados, se cargan en los seeders del catálogo.

## Criterios

1. **Tipo de personal** (`aplica_tipo_personal`: ambos, docente o administrativo) indica a quién se **ofrece** el documento. Es distinto de `requiere_docente` / `requiere_administrativo`, que indican a quién se **exige**: la maestría se exige al docente (Ley 30220), pero un administrativo también puede tenerla y cargarla.
2. **Prudencia:** el backend rechaza cargar un tipo que no corresponde. Por eso solo se propone «Docente» o «Administrativo» cuando es claro; en la duda queda «Ambos» y se formula la pregunta. Un tipo de más en pantalla molesta; uno de menos impide cargar un documento legítimo.
3. **Régimen** (`aplica_carrera`, `aplica_plazo_determinado`, `aplica_designado`) es otro filtro, ya implementado, que solo actúa cuando el régimen de la persona es conocido (carrera, plazo determinado o designado). Su propuesta está en la segunda tabla; depende de los pendientes 11 y U2 de `ESTADO_PROYECTO.md`.
4. **Cargo (puesto):** no se propone nada. El catálogo no relaciona documentos con puestos y falta la lista de puestos de la universidad (U4).

## Resultado en una línea

El catálogo sigue la Directiva de SERVIR, pensada para todo servidor civil: **casi todos los tipos corresponden a docentes y administrativos por igual**. Con esta propuesta el filtro por tipo de personal solo distingue 4 tipos. La diferencia real entre docentes y administrativos está en **documentos que el catálogo no tiene** (ver «Preguntas»), y en el **régimen**, que hoy no se puede usar porque la clasificación de regímenes está pendiente.

| Propuesta | Tipos |
|---|---|
| Ambos | 83 |
| Solo docente | 1 (confianza alta) |
| Solo administrativo | 3 (confianza media: a validar) |

## Tabla 1. Tipo de personal (propuesta)

Solo se listan los tipos que **no** quedan en «Ambos». Todos los demás (83) quedan en «Ambos».

| Sección | Código | Documento | Propuesta | Confianza | Fundamento |
|---|---|---|---|---|---|
| 08 | `REC_LABOR_DOCENTE` | Reconocimiento excepcional por labor docente | **Docente** | Alta | El propio nombre lo limita a la labor docente (Directiva, literal 8.1 b). |
| 02 | `S02_E_RESOLUCION_CARRERA` | Resolución administrativa del servicio civil de carrera | **Administrativo** | Media | La carrera del servicio civil (D. Leg. 276 / Ley 30057) es administrativa; el docente ordinario ingresa por nombramiento (Ley 30220). **Pregunta:** ¿la universidad archiva aquí la resolución de nombramiento docente, al no haber otro tipo? Si es así, queda en «Ambos». |
| 11 | `S11_B_TERMINO_CARRERA` | Resolución de término del servicio civil de carrera | **Administrativo** | Media | Misma razón que el anterior. Ya está limitado por régimen a carrera. |
| 11 | `S11_G_NO_RENOVACION_CAS` | Carta de no renovación de contrato (DL 1057) | **Administrativo** | Media | El CAS (DL 1057) es administrativo; el docente contratado se rige por la Ley 30220. **Pregunta:** ¿la universidad tiene docentes bajo CAS? Si los tiene, queda en «Ambos». |

### Tipos que quedan en «Ambos» con una duda que conviene confirmar

| Código | Duda |
|---|---|
| `S07_71A_GESTION_RENDIMIENTO`, `S07_71B_ACTA_RETROALIMENTACION` | El formato de gestión del rendimiento es de SERVIR. ¿Se evalúa con él también a los docentes, o solo a los administrativos? (La universidad confirmó que evalúa, sin puntaje numérico). |
| `S02_M_EVALUACION_PERIODO_PRUEBA` | ¿Los docentes tienen período de prueba? |
| `S07_72A_RESOLUCION_PROGRESION` | Lo usan las progresiones (administrativos de carrera) y las ratificaciones docentes. Queda en «Ambos» mientras compartan tipo. (La 7.2 está desactivada para esta universidad). |
| `S07_73B` a `S07_73F` (desplazamientos) | Designación, rotación, destaque y encargo son figuras del servicio civil. ¿Aplican también a docentes (por ejemplo, encargos de funciones administrativas)? |
| `FORM_MAESTRIA_DOCTORADO` | Se **exige** al docente, pero también puede tenerla un administrativo: queda en «Ambos» a propósito. |

## Tabla 2. Régimen (propuesta, depende de los pendientes 11 y U2)

Hoy estos tipos se ofrecen a todos los regímenes (`aplica_*` en verdadero, «permisivos, pendiente de confirmar»). Esta tabla propone restringirlos. **Solo tendrá efecto cuando el régimen de cada persona esté clasificado** como carrera, plazo determinado o designado; con regímenes como «276» o «CAS» registrados tal cual, el sistema no filtra.

| Código | Documento | Regímenes propuestos | Fundamento |
|---|---|---|---|
| `S02_C_RESOLUCION_DESIGNACION` | Resolución de designación | Designado | Formaliza el vínculo de un cargo de confianza o directivo. |
| `S02_D_RESOLUCION_RENOVACION` | Resolución de renovación | Plazo determinado, designado | **Pregunta:** ¿en qué casos se renueva por resolución? |
| `S02_E_RESOLUCION_CARRERA` | Resolución del servicio civil de carrera | Carrera | Formaliza el ingreso a la carrera. |
| `S02_F_CONTRATO` | Contrato | Plazo determinado | El contrato es propio del personal a plazo determinado (CAS, 728, docente contratado). **Pregunta:** ¿hay contratos en otros regímenes? |
| `S02_G_ADENDA` | Adenda | Plazo determinado | Modifica un contrato. |
| `S01_F_DJ_NO_IMPEDIMENTOS_31419` | DJ de no impedimentos (Ley 31419) | Designado | La Ley 31419 regula a funcionarios y directivos de libre designación. |
| `S02_B_INFORME_REQUISITOS_PUESTO` | Informe de ORH sobre requisitos | Designado (y Ley 31419) | La Directiva lo limita a servidores sujetos a la Ley 31419 y de confianza. |

Ya restringidos hoy: `S07_72A_RESOLUCION_PROGRESION` (carrera), `S11_A_TERMINO_DESIGNACION` (designado), `S11_B_TERMINO_CARRERA` (carrera), `S11_G_NO_RENOVACION_CAS` (plazo determinado).

## Preguntas para la universidad

1. **Documentos propios de docentes que el catálogo no tiene.** El catálogo sigue la Directiva de SERVIR y no recoge documentos de la Ley 30220. ¿Archivan en el legajo, y en qué sección, documentos como estos?
   - resolución de nombramiento docente;
   - resoluciones de ratificación y promoción (hoy se registran en 7.2 con el tipo de progresión, desactivada para esta universidad);
   - categoría y régimen de dedicación;
   - grados y títulos registrados en SUNEDU;
   - carga lectiva o asignación de cursos.

   **Esto es lo que de verdad distinguiría lo que carga un docente de lo que carga un administrativo.** No se agregan tipos sin su respuesta.
2. ¿Archivan la resolución de nombramiento docente como «Resolución administrativa del servicio civil de carrera»?
3. ¿Tienen docentes contratados bajo CAS (DL 1057)?
4. ¿Se evalúa a los docentes con el formato de gestión del rendimiento de SERVIR (7.1)?
5. ¿Los docentes tienen período de prueba?
6. ¿Qué regímenes usan y cómo se clasifican en carrera, plazo determinado o designado (U2)? Sin eso, la tabla 2 no tiene efecto.
7. Para filtrar por cargo: ¿tienen una lista de puestos y los documentos que exige cada uno (U4)?

## Cómo se aplicará cuando se valide

1. Ajustar `aplica_tipo_personal` (y, si se aprueba la tabla 2, `aplica_*`) en los seeders de catálogo de cada sección (`database/seeders/SeccionNNCatalogoSeeder.php`).
2. Una migración que actualice solo los campos de aplicabilidad en las bases ya instaladas. Hay que revisarla seeder por seeder: algunos (por ejemplo, 02 y 07) no alteran un tipo que ya tiene documentos, y la aplicabilidad sí debe poder cambiarse en esos tipos sin tocar nada más.
3. Pruebas que verifiquen la clasificación cargada.
