# Producción

Instalación del sistema en un servidor Linux con Docker. Sirve igual para producción y para un servidor de pruebas
idéntico a producción. El `docker-compose.yml` de la raíz es solo para desarrollo; producción usa
`docker-compose.produccion.yml`.

## Qué se instala

| Contenedor | Qué hace |
|---|---|
| `caddy` | Único con puertos públicos (80 y 443). HTTPS automático por subdominio, frontend compilado y paso a PHP de `/api`, `/sanctum` y `/up`. |
| `app` | Laravel (php-fpm) con el código dentro de la imagen. No arranca si `php artisan produccion:verificar` falla. |
| `worker` | Trabajos en cola (`queue:work`), cada uno con la base y los archivos de su institución. |
| `db` | PostgreSQL 16, sin puerto público. Una base por institución, más la de la plataforma. |

Volúmenes persistentes: `postgres` (bases), `storage` (PDF, fotos, sesiones, caché) y `caddy_datos` (certificados).

## Requisitos

- Servidor Linux con Docker y el plugin `docker compose` (2 GB de RAM como mínimo; 4 GB recomendados).
- Puertos 80 y 443 abiertos hacia el servidor.
- Un dominio para la plataforma, por ejemplo `escalafon.pe`, con dos registros DNS hacia la IP del servidor:
  - `A escalafon.pe` (panel de plataforma)
  - `A *.escalafon.pe` (cada institución: `uniq.escalafon.pe`, …)

Los certificados HTTPS los pide Caddy a Let's Encrypt la primera vez que alguien entra a cada subdominio. Antes,
pregunta a la aplicación si el host es el dominio de la plataforma o el de una institución registrada; si no lo es,
no pide nada.

**Decidido (13/10/2026):** modelo SaaS en un servidor en la nube que alquilamos nosotros; todas las instituciones en
el mismo servidor, cada una con su base y su carpeta. **Pendiente de confirmar en el contrato con cada universidad:**
que acepta que sus datos personales estén en ese proveedor (si el servidor está fuera del Perú, es un flujo
transfronterizo de datos personales según la Ley 29733) y quién es responsable de qué.

## Primera instalación

1. Clonar el repositorio en el servidor y crear la configuración:

       cp .env.produccion.example .env.produccion
       chmod 600 .env.produccion

2. Completar `.env.produccion`:
   - `APP_URL`, `PLATAFORMA_DOMINIO` y `SANCTUM_STATEFUL_DOMAINS` con el dominio real.
   - `DB_PASSWORD` y `PLATAFORMA_DB_PASSWORD`: claves largas (16 caracteres o más), por ejemplo con
     `openssl rand -base64 24`.
   - `ACME_EMAIL`: correo para los avisos de Let's Encrypt.
   - `APP_KEY`: generarla con

         docker compose --env-file .env.produccion -f docker-compose.produccion.yml run --rm --no-deps --entrypoint php app artisan key:generate --show

3. Construir, migrar y arrancar:

       sh docker/produccion/desplegar.sh

4. Crear la primera cuenta del panel de plataforma (pide la contraseña sin mostrarla):

       docker compose --env-file .env.produccion -f docker-compose.produccion.yml exec app php artisan plataforma:crear-administrador

5. Entrar a `https://escalafon.pe/plataforma/login`, registrar la primera institución y pulsar **Aprovisionar**
   (crea su base, sus catálogos, su primer administrador y su carpeta). Luego **Activar**, y la institución entra por
   `https://{slug}.escalafon.pe`.

Si `produccion:verificar` encuentra un problema, el contenedor `app` no arranca y lo explica en su log:

    docker compose --env-file .env.produccion -f docker-compose.produccion.yml logs app

## Actualizar

    git pull
    sh docker/produccion/desplegar.sh

El script construye las imágenes nuevas, migra **todas** las bases (la del `.env`, la de la plataforma y la de cada
institución) **antes** de reemplazar la aplicación, y después arranca los contenedores nuevos. Si una migración
falla, la versión anterior sigue funcionando.

## Respaldos

    sh docker/produccion/respaldar.sh

Guarda en `respaldos/AAAAMMDD-HHMMSS/` cada base (`pg_dump`) y los archivos privados de todas las instituciones, y
borra los respaldos de más de 14 días (`RESPALDO_DIAS`). Programarlo cada noche con `crontab -e`:

    0 2 * * * cd /ruta/del/proyecto && sh docker/produccion/respaldar.sh >> respaldos/respaldo.log 2>&1

Un respaldo en el mismo servidor no protege si se pierde el servidor: copiar `respaldos/` a otro lugar (otro
servidor, almacenamiento en la nube del cliente) con `rsync` o `rclone`.

Restaurar una base:

    docker compose --env-file .env.produccion -f docker-compose.produccion.yml exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d NOMBRE_BASE --clean --if-exists' < respaldos/FECHA/NOMBRE_BASE.dump

Restaurar los archivos:

    docker compose --env-file .env.produccion -f docker-compose.produccion.yml exec -T app tar -xzf - -C /var/www/html/storage < respaldos/FECHA/archivos.tar.gz

## Seguridad

- La API está cerrada fuera de local salvo `API_HABILITADA=true`, y `produccion:verificar` exige: `APP_ENV=production`,
  `APP_DEBUG=false`, `APP_KEY`, `APP_URL` con `https://`, cookies de sesión seguras, claves de base de datos largas y,
  con subdominios, el dominio real, `SESSION_DOMAIN` vacío y los dominios de Sanctum.
- El CRUD genérico por tabla (`/api/v1/{tabla}`) es de solo lectura fuera de local: su escritura salta las reglas de
  cada sección (anular en vez de borrar, historial, folios). El frontend solo lo lee; todas sus escrituras van por
  `/expedientes`. `API_CRUD_GENERICO_ESCRITURA=true` lo abriría: no hacerlo sin auditar antes cada recurso.
- Caddy agrega HSTS y cabeceras de seguridad, limita las peticiones a 4 MB y solo pasa a PHP `/api`, `/sanctum` y `/up`.
- PostgreSQL no publica su puerto.
- `.env.produccion` y `respaldos/` están en `.gitignore`: nunca se suben al repositorio.

**Pendiente antes de recibir datos reales:**
- Cifrado en reposo de los PDF (pendiente 9 de `ESTADO_PROYECTO.md`) y antivirus de las cargas.
- La aplicación usa el usuario administrador de PostgreSQL (lo necesita para crear las bases al aprovisionar).
  Separar un usuario con permisos mínimos para la operación diaria.
- Copia de los respaldos fuera del servidor y una prueba de restauración.
- Avisos de vencimiento por correo (`MAIL_*`); hoy solo se ven dentro del sistema.

## Probar en una máquina sin dominio

Con un dominio inventado (por ejemplo `escalafon.test`) y certificados de una CA local:

    CADDY_GLOBAL_EXTRA=local_certs
    PUERTO_HTTP=8080
    PUERTO_HTTPS=8443
    APP_URL=https://escalafon.test:8443
    SANCTUM_STATEFUL_DOMAINS=escalafon.test,*.escalafon.test,escalafon.test:8443,*.escalafon.test:8443

y apuntar `escalafon.test` y cada `{slug}.escalafon.test` a `127.0.0.1` en el archivo `hosts`. El navegador avisará
que el certificado no es de confianza (es de la CA local de Caddy).
