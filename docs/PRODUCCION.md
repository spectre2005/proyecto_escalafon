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

Hace un respaldo **por institución** en `RESPALDO_DIR/AAAAMMDD-HHMMSS/<objetivo>/` (`RESPALDO_DIR` es `respaldos`,
en la raíz del proyecto, por defecto; carpetas 0700 y archivos 0600):

- `plataforma/`: la base de la plataforma (instituciones, superadministradores, pagos y auditoría).
- `<slug>/` de cada institución aprovisionada, también las suspendidas: `base.dump` (`pg_dump`), `archivos.tar.gz`
  (sus PDF y fotos, de `storage/app/private/instituciones/<slug>`) y `SHA256SUMS`.
- `principal/`: la base del `.env`, solo si no es de ninguna institución.
- `otras/`: cualquier otra base del servidor que no figure en el plan, para no perder nada.

Qué respaldar lo decide `php artisan plataforma:respaldo-plan`. Cada resultado se registra en la base de la
plataforma y el **panel** lo muestra: el estado en cada institución y, en el resumen, las instituciones sin un
respaldo correcto en las últimas 26 horas. El script termina con error si algo falló. Se conservan
`RESPALDO_DIAS` días en el servidor (14 por defecto), salvo los protegidos.

**Respaldo protegido** (p. ej. antes de normalizar el almacenamiento de una institución): con
`RESPALDO_CONSERVAR="<motivo>"`, el respaldo lleva una marca `.conservar` y la limpieza automática no lo borra nunca,
aunque después se ejecute con la retención predeterminada; borrarlo es una decisión expresa, a mano. Se niega a
empezar si `RESPALDO_REMOTO` no está vacío (no sale a ninguna copia externa) o si `RESPALDO_DIR` está dentro de un
repositorio git (`.gitignore` no basta: `git add -f` lo incluiría). Por ejemplo:

    RESPALDO_DIR="$HOME/respaldos-escalafon" RESPALDO_REMOTO= RESPALDO_CONSERVAR="antes de normalizar uniq" \
        sh docker/produccion/respaldar.sh
    RESPALDO_DIR="$HOME/respaldos-escalafon" sh docker/produccion/restaurar.sh verificar AAAAMMDD-HHMMSS uniq

Pruebas aisladas (sin Docker real, sin red ni bases reales): `sh docker/produccion/pruebas/respaldos.sh` (respaldo,
verificación y copia externa con un rclone simulado) y `sh docker/produccion/pruebas/conectar-drive.sh` (el asistente
de Google Drive).

Las variables `RESPALDO_*` se leen del entorno o de `.env.produccion`. **Una variable del entorno manda aunque esté
vacía**: `RESPALDO_REMOTO= sh docker/produccion/respaldar.sh` no sube nada aunque `.env.produccion` tenga un remoto.

Programarlo cada noche con `crontab -e`:

    0 2 * * * cd /ruta/del/proyecto && sh docker/produccion/respaldar.sh >> respaldos/respaldo.log 2>&1

### Copia externa cifrada (Google Drive)

Un respaldo en el mismo servidor no protege si se pierde el servidor. Con `RESPALDO_REMOTO`, `respaldar.sh` sube cada
respaldo **después** de crearlo en el servidor, con [rclone](https://rclone.org) en su imagen Docker (sin instalarlo),
y lo comprueba con `rclone cryptcheck`. El resultado queda en el panel como copia externa `correcta` (subida y
comprobada), `fallida` (con el motivo) o `no_configurada`. Si la copia externa falla, el respaldo local sigue
registrado como correcto y el script termina con error.

No se sube nada, y queda como `fallida`, si:

- la aplicación no está en `APP_ENV=production` (un entorno local, aunque esté conectado a datos reales, nunca sube);
- `RESPALDO_REMOTO` no es `remoto:carpeta`, o el remoto no es de tipo **crypt** (Google ve los archivos cifrados y
  con nombres ilegibles, porque llevan datos personales y de salud);
- `rclone.conf` no existe, **no está cifrado**, o su clave falta, tiene permisos distintos de 600/400 o no lo abre.

El motivo distingue una autorización de Google rechazada o revocada (hay que volver a ejecutar el asistente), una
subida fallida y una comprobación que no coincide.

**Pendiente del cliente:** de quién es la cuenta de destino (del proveedor o de cada universidad), y que la
universidad autorice que sus datos, aunque vayan cifrados, se guarden en un servicio externo.

#### Credenciales: rclone.conf cifrado y su clave

`rclone.conf` guarda el token de Google y las contraseñas del cifrado de los respaldos. Sin cifrar, rclone solo las
**ofusca** (cualquiera que lea el archivo las recupera). Por eso se exige `rclone.conf` cifrado con la clave de
`RESPALDO_RCLONE_CLAVE` (por defecto `~/.config/escalafon/rclone.clave`, en otra carpeta que `rclone.conf`):

- La clave llega a rclone con `--password-command`, desde un archivo montado de solo lectura: nunca en los argumentos,
  en el entorno, en `.env.produccion` ni en los logs. Al renovar el token, rclone reescribe `rclone.conf` cifrado.
- `rclone.conf` y la clave van fuera del repositorio, con permisos 600. **Guarda una copia de cada uno, por separado y
  fuera del servidor** (p. ej. el gestor de contraseñas del equipo): sin los dos no se pueden leer las copias de Drive.
- Limitación: el usuario que ejecuta los respaldos lee ambos archivos; el cifrado protege `rclone.conf` si se copia,
  se filtra o se respalda por separado, no frente a quien ya controla esa cuenta del servidor.

#### Conectar Google Drive (una vez, en el servidor de producción)

    sh docker/produccion/conectar-drive.sh                 # con navegador en este equipo
    sh docker/produccion/conectar-drive.sh --sin-navegador # servidor sin navegador («rclone authorize» en otro equipo)
    sh docker/produccion/conectar-drive.sh --verificar     # solo comprueba la conexión, sin cambiar nada

Exige `.env.produccion` con `APP_ENV=production` y una terminal. El asistente:

1. Crea la clave (si falta) y cifra `rclone.conf` con ella. La clave no se muestra.
2. Autoriza Google Drive con OAuth (alcance `drive.file`: rclone solo ve los archivos que él mismo crea) en el remoto
   `escalafon_drive`.
3. Crea el remoto crypt de `RESPALDO_REMOTO` (por defecto `escalafon_cifrado:copias`) sobre
   `escalafon_drive:escalafon-respaldos`, con nombres de archivos y carpetas cifrados. Cuando rclone pida sus
   contraseñas, elegir `g` (generar).
4. Sube un archivo de prueba, lo lee, comprueba que coincide y lo borra (solo ese archivo).
5. Solo si todo salió bien, guarda en `.env.produccion` `RESPALDO_REMOTO`, `RESPALDO_RCLONE_CONF` y
   `RESPALDO_RCLONE_CLAVE`, sin tocar las demás líneas ni sus permisos.

rclone muestra la configuración al crear cada remoto: el asistente oculta tokens y contraseñas en pantalla. No sube
respaldos, no borra copias y no programa nada.

Opcional: `RESPALDO_REMOTO_DIAS` (30), los días que se conservan en la copia externa. Tras una copia correcta y
comprobada, `respaldar.sh` borra de la carpeta de `RESPALDO_REMOTO` las copias más antiguas: esa carpeta debe ser
solo para respaldos. (Comportamiento anterior a esta integración, sin cambios; pendiente de confirmar.)

Recuperar desde Drive (restaurar desde la copia externa queda para una fase posterior; hoy es manual), con
`rclone.conf` y su clave:

    docker run --rm -v ~/.config/rclone:/config/rclone -v ~/.config/escalafon/rclone.clave:/run/clave:ro \
        -v "$PWD/respaldos:/respaldos" rclone/rclone:1.68.2 --password-command "cat /run/clave" \
        copy escalafon_cifrado:copias/AAAAMMDD-HHMMSS /respaldos/AAAAMMDD-HHMMSS

### Verificar y restaurar

Prueba de restauración, que no toca nada real. Comprueba las huellas, restaura cada base en una base temporal que
crea esa misma ejecución, y lee los paquetes de archivos. Solo borra la temporal que creó: si `createdb` falla (también
porque ya existe una base con ese nombre), se detiene sin restaurar ni borrar nada. Con otro `RESPALDO_DIR`, se indica
igual que al respaldar. El resultado aparece en el panel. Conviene programarla cada semana:

    sh docker/produccion/restaurar.sh verificar AAAAMMDD-HHMMSS          # todos los objetivos
    sh docker/produccion/restaurar.sh verificar AAAAMMDD-HHMMSS uniq     # solo uno

Restaurar **una** institución, o `plataforma`, o `principal`. Conviene suspenderla antes desde el panel. Pide
escribir su nombre para confirmar. Antes guarda su estado actual en `RESPALDO_DIR/antes-de-restaurar-…/` y aparta sus
archivos actuales en `storage/app/private/instituciones/<slug>.antes-de-restaurar-…`, sin borrarlos. Después
aplica las migraciones pendientes:

    sh docker/produccion/restaurar.sh restaurar AAAAMMDD-HHMMSS uniq

## Seguridad

- La API está cerrada fuera de local salvo `API_HABILITADA=true`, y `produccion:verificar` exige: `APP_ENV=production`,
  `APP_DEBUG=false`, `APP_KEY`, `APP_URL` con `https://`, cookies de sesión seguras, claves de base de datos largas y,
  con subdominios, el dominio real, `SESSION_DOMAIN` vacío y los dominios de Sanctum.
- El CRUD genérico por tabla (`/api/v1/{tabla}`) es de solo lectura fuera de local: su escritura salta las reglas de
  cada sección (anular en vez de borrar, historial, folios). El frontend solo lo lee; todas sus escrituras van por
  `/expedientes`. `API_CRUD_GENERICO_ESCRITURA=true` lo abriría: no hacerlo sin auditar antes cada recurso.
- Caddy agrega HSTS y cabeceras de seguridad, limita las peticiones a 4 MB y solo pasa a PHP `/api`, `/sanctum` y `/up`.
- PostgreSQL no publica su puerto.
- `.env.produccion` y `respaldos/` están en `.gitignore`, pero eso no impide un `git add -f`: los respaldos con datos
  reales conviene guardarlos fuera del repositorio (`RESPALDO_DIR`), y los protegidos lo exigen.

**Pendiente antes de recibir datos reales:**
- Cifrado en reposo de los PDF (pendiente 9 de `ESTADO_PROYECTO.md`) y antivirus de las cargas.
- La aplicación usa el usuario administrador de PostgreSQL (lo necesita para crear las bases al aprovisionar).
  Separar un usuario con permisos mínimos para la operación diaria.
- Configurar la copia externa cifrada de los respaldos (`RESPALDO_REMOTO`, «Copia externa cifrada») y programar
  la verificación semanal (`restaurar.sh verificar`). Decidir con el cliente de quién es la cuenta de destino.
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
