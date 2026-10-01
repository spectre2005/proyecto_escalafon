# CLAUDE.md
Sistema de Escalafón y Expediente Digital Universitario. Es un producto para alquilar a varias entidades: ningún nombre ni logo de entidad en el código.

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Monorepo with two independent apps, both run exclusively through Docker Compose (the host only needs Git and Docker):

- `backend/` — Laravel 13 (PHP 8.4) API, PostgreSQL 16.
- `frontend/` — React 19 SPA built with Vite, managed with **pnpm**.


## Docker services (`docker-compose.yml`)

| Service    | Purpose                                  | Host port |
|------------|------------------------------------------|-----------|
| `app`      | PHP-FPM 8.4 + Composer, mounts `backend/` at `/var/www/html` | — |
| `nginx`    | Serves `backend/public`, forwards PHP to `app:9000` | 8000 |
| `frontend` | Node 22 + pnpm, runs `pnpm install && pnpm dev --host` on start | 5173 |
| `db`       | PostgreSQL 16                            | 5432 |

Containers run as `DOCKER_UID:DOCKER_GID` from the root `.env` so generated files are owned by the host user. Two `.env` files exist: the root `.env` (Docker/Postgres credentials) and `backend/.env` (Laravel; `DB_HOST=db`). Keep DB credentials consistent between them.

## Commands

Run everything inside the containers — PHP, Composer and Node are not expected on the host.

First run:

    cp .env.example .env
    cp backend/.env.example backend/.env
    docker compose up -d --build
    docker compose exec app composer install
    docker compose exec app php artisan key:generate
    docker compose exec app php artisan migrate

Daily: `docker compose up -d` / `docker compose down`.

Backend:

    docker compose exec app php artisan test                              # all tests
    docker compose exec app php artisan test --filter=ExampleTest         # single test class/method
    docker compose exec app php artisan test tests/Feature/ExampleTest.php
    docker compose exec app ./vendor/bin/pint                             # format (Laravel Pint)
    docker compose exec app php artisan migrate

Tests use PHPUnit (not Pest) and run against in-memory SQLite (`phpunit.xml`), not the Postgres container.

Frontend:

    docker compose exec frontend pnpm lint
    docker compose exec frontend pnpm build
    docker compose exec frontend pnpm add <package>

## Team rules

- Frontend dependencies are managed **only with pnpm, always inside Docker** (`docker compose exec frontend pnpm ...`). Never use npm.
- `backend/CLAUDE.md` / `backend/AGENTS.md` contain the generic Laravel Boost bootstrap text, which tells agents to install PHP/Composer on the host. Ignore that part — in this project PHP and Composer run in the `app` container. Laravel Boost is not installed; don't add it unless asked.


## Qué es el sistema

Sistema de Escalafón y Expediente Digital Universitario: gestiona el legajo (expediente) del personal de una universidad según la Directiva N° 001-2023-SERVIR-GDSRH (PDF en `docs/`). Consulta el PDF solo cuando necesites un artículo concreto; no lo leas completo.

**Perfil por dos ejes (no hay tipos de usuario fijos):**
- Régimen laboral: carrera, plazo determinado (CAS/728/1057) o designado (confianza).
- Banderas: obligado_djbr, exige_colegiatura, tiene_derechohabientes, tiene_periodo_prueba, con_discapacidad.
- Del cruce de ambos se deduce qué documentos se exigen a cada persona.

**El legajo tiene 13 secciones fijas:**
01 Información personal y familiar · 02 Incorporación · 03 Formación académica y capacitación · 04 Experiencia laboral · 05 Movimientos del personal · 06 Compensaciones · 07 Evaluación, progresión y desplazamiento · 08 Reconocimientos y sanciones · 09 Relaciones laborales · 10 SST y bienestar · 11 Desvinculación · 12 Otros · 13 Legajo pasivo.
- Núcleo común obligatorio para todos: 01, 02, 03, 04 y 10.
- Cada sección tiene un hook para "otros documentos requeridos por la universidad".

**Dos capas de datos:**
- A (evidencia): el PDF y sus metadatos (`documentos_usuario`, `archivos_documento`). Los PDF van a disco o almacenamiento, no a PostgreSQL.
- B (información): tablas por sección con datos estructurados.

**Metadatos obligatorios de cada documento:** sección, tipo, rango de folios, fecha de incorporación, emisor, fecha de emisión, titular (nombre/DNI), N° de resolución si aplica.

**Estados:** Pendiente → Cargado → En fiscalización → Verificado | Observado | Falso.
Un documento está "aprobado" solo si: está presente Y tiene recepción registrada Y (fiscalizado conforme, si acredita el perfil del puesto).

**Quién hace qué (directiva):**
- Arts. 2.3 y 5.3: prohibido eliminar o alterar documentos.
- Art. 4.5: la recepción lleva sello, firma, fecha y el nombre de quien la hizo.
- Art. 6.2: jefes y OCI leen sin datos sensibles ni de salud.
- Art. 6.3 y 6.4: el jefe de la ORH decide los accesos; la carga la hace personal de la ORH.
- Art. 7: la fiscalización posterior la conduce la ORH.
- Plazos en días hábiles (5 apertura, 30 actualización): verificar en el PDF antes de ponerlos en código.

**Usuarios actuales:** administrador (jefe de ORH del cliente) y asistente. Los demás roles quedan para después.



## Reglas que no se rompen

- Documentos validados: no se eliminan ni se editan. Se anulan (`anulado = true` con motivo) o se subsana con un documento nuevo.
- Folios correlativos por sección (sugerido: `ultimo_folio + 1`). Todo listado y contador excluye `anulado = true`.
- Permisos: el backend manda una lista (ej. `documento.subir`) y el frontend pregunta "¿puede hacer esto?". Nunca se programa por nombre de rol. El backend valida siempre.
- Quien sube un documento no es quien lo verifica.
- Secciones 01, 09 y 10 se bloquean si el rol no tiene `ve_datos_sensibles`.
- Sin `entidad_id` en las tablas. La marca sale de `config/institution.js` y `parametros_sistema`.

## Cómo se hace el frontend

- Tailwind siempre, sin CSS aparte ni librerías de estilos nuevas.
- El diseño cambia: copia el estilo del panel existente, no fijes colores propios.
- Auth con Laravel Sanctum, contraseñas con bcrypt.

## Contexto

- Esquema de BD (55 tablas): `docs/escalafon_drawdb_55.sql`.
- Pendientes y cosas sin confirmar con el cliente: `docs/ESTADO_PROYECTO.md`. Si algo está marcado "pendiente de confirmar", no lo asumas: déjalo pendiente y avísame.
- Un chat = una tarea. No adivines el contenido de archivos que no hayas visto. Si generas código, verifica que compile y dime cómo.
- Responde siempre en español, salvo código y comandos.


## Flujo de trabajo en equipo

- Somos 3 personas en el mismo repo (1 en Ubuntu, 2 en Windows).
- Cada tarea va en su propia rama (`feature/nombre-de-la-tarea`). Nunca trabajes directo en `main`.
- No hagas `git commit`, `git push` ni cambies de rama: los hago yo. Cuando termines, dime qué archivos tocaste.
- Antes de editar, revisa que no haya cambios sin guardar de otra tarea (`git status`). Si los hay, avísame.
