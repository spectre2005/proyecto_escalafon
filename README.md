# proyecto_escalafon

Laravel 13 + React (Vite, pnpm) + PostgreSQL, todo con Docker.
Solo se necesita Git y Docker (en Windows: Docker Desktop con WSL2).

## Primer arranque

    cp .env.example .env
    cp backend/.env.example backend/.env
    docker compose up -d --build
    docker compose exec app composer install
    docker compose exec app php artisan key:generate
    docker compose exec app php artisan migrate
    docker compose exec app php artisan plataforma:migrar-instituciones --crear-base
    docker compose exec app php artisan db:seed

En Windows, `iniciar.ps1` hace todo lo anterior (se puede volver a correr sin duplicar datos).

- Laravel: http://localhost:8000
- React: http://localhost:5173

## Cuentas de prueba

`db:seed` crea las mismas cuentas e instituciones en todos los equipos, con los valores de `backend/.env.example`.
Solo existen en local; en producción nunca se crean.

El sistema corre en modo subdominio (`PLATAFORMA_MODO=subdominio`): cada institución abre en `{slug}.localhost:5173`
y el panel de plataforma en `localhost:5173`, sin subdominio. Chrome, Edge y Firefox abren `*.localhost` sin configurar nada.
`http://localhost:5173` sin subdominio responde "No existe una institución activa en esta dirección": es lo esperado.

Contraseña de todas las cuentas: `Demo-Escalafon-2026`

| Para entrar a | Dirección | Correo |
|---|---|---|
| Panel de plataforma | http://localhost:5173/plataforma/login | superadmin@demo.test |
| Institución principal (base de `DB_DATABASE`, con 10 usuarios demo) | http://uniq.localhost:5173 | admin.uniq@demo.test |
| Universidad de Prueba Uno (base propia) | http://uno.localhost:5173 | admin.uno@demo.test |
| Universidad de Prueba Dos (base propia) | http://dos.localhost:5173 | admin.dos@demo.test |

Si ya tenías el proyecto: copia de `backend/.env.example` a tu `backend/.env` las líneas `ADMIN_*`, `PLATAFORMA_ADMIN_*`,
`PLATAFORMA_PRUEBA_*`, `PLATAFORMA_MODO` y `PLATAFORMA_DOMINIO`, y vuelve a correr `iniciar.ps1` (o los comandos de
arriba). Las instituciones que ya tengas registradas no se tocan.

## Día a día

    docker compose up -d      # encender
    docker compose down       # apagar

Después de traer cambios (y en cada despliegue), migrar todas las bases: la del .env y,
si hay instituciones por subdominio, la de la plataforma y la de cada institución.

    docker compose exec app php artisan migrate
    docker compose exec app php artisan plataforma:migrar-instituciones

Regla del equipo: el frontend se maneja solo con pnpm, y siempre dentro de Docker
(`docker compose exec frontend pnpm add <paquete>`). No usar npm.
