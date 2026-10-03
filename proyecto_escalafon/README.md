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

- Laravel: http://localhost:8000
- React: http://localhost:5173

## Día a día

    docker compose up -d      # encender
    docker compose down       # apagar

Regla del equipo: el frontend se maneja solo con pnpm, y siempre dentro de Docker
(`docker compose exec frontend pnpm add <paquete>`). No usar npm.
