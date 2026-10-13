# Imagen de producción del frontal: Caddy (HTTPS automático por subdominio) con el frontend ya compilado.
# Contexto: la raíz del repositorio.

FROM node:22-alpine AS frontend
RUN npm install -g pnpm@12.4.2
WORKDIR /app
COPY frontend/package.json frontend/pnpm-lock.yaml frontend/pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY frontend/ ./
RUN pnpm build

FROM caddy:2.10-alpine
COPY docker/produccion/Caddyfile /etc/caddy/Caddyfile
COPY --from=frontend /app/dist /srv/frontend
