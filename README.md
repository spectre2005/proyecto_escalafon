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
    docker compose exec app php artisan plataforma:migrar-instituciones

- Laravel: http://localhost:8000
- React: http://localhost:5173

## Día a día

    docker compose up -d      # encender
    docker compose down       # apagar

Después de traer cambios (y en cada despliegue), migrar todas las bases: la del .env y,
si hay instituciones por subdominio, la de la plataforma y la de cada institución.

    docker compose exec app php artisan migrate
    docker compose exec app php artisan plataforma:migrar-instituciones

Regla del equipo: el frontend se maneja solo con pnpm, y siempre dentro de Docker
(`docker compose exec frontend pnpm add <paquete>`). No usar npm.
