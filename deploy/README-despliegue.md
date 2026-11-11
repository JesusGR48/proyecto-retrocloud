# Desplegar RetroCloud en un dominio gratuito

Guía para publicar RetroCloud en **AlwaysData** (plan gratuito) con HTTPS,
y opcionalmente apuntarle un dominio `*.is-a.dev`.

Tiempo estimado: **30-45 minutos**.

---

## Por qué AlwaysData y no un hosting gratuito típico

RetroCloud usa 10 tablas InnoDB, 11 claves foráneas, 3 vistas, 1
procedimiento almacenado y 1 disparador, y exige que el *document root*
apunte a `public/`.

La mayoría de hostings gratuitos compartidos (InfinityFree, 000webhost y
similares) **no soportan nada de eso**. En InfinityFree, por ejemplo, su
propio administrador lo dice en el foro oficial: solo conceden los
privilegios `ALTER, CREATE, DELETE, DROP, INDEX, INSERT, SELECT, UPDATE,
LOCK TABLES`, y *«foreign keys, stored procedures and triggers are all
disabled»*; tampoco hay vistas y el único motor es MyISAM, no InnoDB.
Además el *document root* es fijo (`htdocs/`) y no se puede cambiar.

AlwaysData, en su plan gratuito, sí da:

| Necesidad del proyecto | AlwaysData gratuito |
|---|---|
| MariaDB con `GRANT ALL PRIVILEGES` | Sí (InnoDB, vistas, triggers, rutinas) |
| *Document root* configurable | Sí, por sitio |
| PHP 8 con versión elegible | Sí |
| HTTPS | Certificado comodín `*.alwaysdata.net` inmediato |
| Acceso SSH | Sí (permite `git clone` en el servidor) |
| Espacio | 1 GB SSD |
| Conexiones MySQL simultáneas | 40 |

1 GB basta de sobra: los núcleos del emulador se cargan del CDN y las
ROMs homebrew de NES/GB/GBA pesan de kilobytes a pocos megabytes.

---

## Antes de empezar

Ten a mano:

- Un correo para registrar la cuenta.
- El nombre de cuenta que elijas (en esta guía, `TUCUENTA`). Será también
  tu subdominio: `TUCUENTA.alwaysdata.net`.

---

## Paso 1 · Crear la cuenta

1. Entra a <https://www.alwaysdata.com/en/register/?p=2012> (el `p=2012`
   selecciona directamente el plan gratuito).
2. Elige el nombre de cuenta. **Piénsalo bien**: es tu subdominio
   público y renombrarlo después es un trámite aparte.
3. Confirma el correo.

El panel de administración queda en <https://admin.alwaysdata.com>.

> Si eres estudiante, mira también su programa académico en la
> documentación (`admin-billing/programs/students-and-job-seekers-program`):
> puede darte más recursos sin costo.

---

## Paso 2 · Crear la base de datos

En el panel: **Databases → MySQL → Add a database**.

- Nombre: `retrocloud` (el panel lo guardará como `TUCUENTA_retrocloud`).
- Crea también el usuario y **asígnale `GRANT ALL PRIVILEGES`**. Esto es
  lo que permite que existan las vistas, el procedimiento y el trigger.

Anota estos cuatro datos, que son los que usarás en el paso 4:

```
Host      mysql-TUCUENTA.alwaysdata.net
Puerto    3306
Base      TUCUENTA_retrocloud
Usuario   TUCUENTA  (o el que hayas creado)
```

---

## Paso 3 · Subir el código por SSH

Conéctate. Usuario y contraseña son los de la cuenta:

```bash
ssh TUCUENTA@ssh-TUCUENTA.alwaysdata.net
```

> ¿Sin cliente SSH a mano? El panel ofrece una consola web en
> `https://ssh-TUCUENTA.alwaysdata.net`.

Ya dentro, clona el proyecto en `~/www/`:

```bash
cd ~/www
git clone -b despliegue-dominio-gratuito https://github.com/JesusGR48/proyecto-retrocloud.git retrocloud
cd retrocloud
```

---

## Paso 4 · Preparar el servidor

Un `git clone` **no deja el proyecto listo**: el `.gitignore` ignora
`logs/` y `storage/roms/`, y git no permite rescatar los `.gitkeep` de
dentro de una carpeta ignorada, así que esas carpetas no existen en un
clon nuevo y la aplicación falla al arrancar. El script las crea y,
de paso, genera `src/config.php`.

Desde `~/www/retrocloud`, en una sola línea (cambia los valores):

```bash
DB_HOST=mysql-TUCUENTA.alwaysdata.net DB_NOMBRE=TUCUENTA_retrocloud DB_USUARIO=TUCUENTA DB_PASSWORD='tu-contrasena' bash deploy/preparar-servidor.sh
```

Deja `src/config.php` en modo producción, con HTTPS forzado y
`CONFIAR_PROXY` activo (necesario: AlwaysData termina el TLS en un proxy
delante de PHP).

---

## Paso 5 · Importar la base de datos

Usa los archivos `-hosting`, **no** `schema.sql` ni `seed.sql`: esos dos
llevan `CREATE DATABASE` / `USE retrocloud`, que en un hosting compartido
fallan con *Access denied* porque el nombre de la base lo asigna el panel.

```bash
mysql -h mysql-TUCUENTA.alwaysdata.net -u TUCUENTA -p TUCUENTA_retrocloud < database/schema-hosting.sql
```

```bash
mysql -h mysql-TUCUENTA.alwaysdata.net -u TUCUENTA -p TUCUENTA_retrocloud < database/seed-hosting.sql
```

Comprueba que todo llegó:

```bash
mysql -h mysql-TUCUENTA.alwaysdata.net -u TUCUENTA -p TUCUENTA_retrocloud -e "SHOW FULL TABLES; SHOW TRIGGERS\G"
```

Debes ver 10 tablas, 3 vistas (`VIEW`) y el disparador
`tr_save_slot_valido`.

---

## Paso 6 · Crear el sitio web

En el panel: **Web → Sites → Add a site**.

| Campo | Valor |
|---|---|
| Addresses | `TUCUENTA.alwaysdata.net` |
| Type | PHP |
| PHP version | 8.2 o superior |
| **Document root** | `/www/retrocloud/public` |

El *document root* es el punto crítico: debe terminar en **`/public`**.
Así `src/`, `storage/`, `database/` y `logs/` quedan fuera del alcance
del navegador, que es justo el diseño de seguridad del proyecto.

En la sección **SSL** del mismo sitio, marca **Force HTTPS**. La
redirección ocurre en el proxy, antes del servidor HTTP.

> Las direcciones `*.alwaysdata.net` ya están cubiertas por un
> certificado comodín, así que el HTTPS funciona de inmediato, sin
> esperar a Let's Encrypt.

---

## Paso 7 · Comprobar

Abre `https://TUCUENTA.alwaysdata.net/diagnostico.php`.

Esa página revisa versión de PHP, extensiones, conexión a MySQL,
presencia de las tablas, permisos de escritura, límites de subida y
estado de HTTPS. Si algo falta, ahí lo dice.

Luego entra a `https://TUCUENTA.alwaysdata.net/` e inicia sesión con la
cuenta de administrador (contraseña generada en el paso 4; si la
perdiste, vuelve a generar el seed).

---

## Paso 8 · Cerrar el sitio antes de entregarlo

```bash
rm public/diagnostico.php
```

`diagnostico.php` revela versiones, rutas y estado interno: útil al
instalar, pero no debe quedar público. Bórralo o protégelo con sesión de
administrador.

Repasa también la lista de "Antes de entregar o publicar" del README
principal.

---

## Paso 9 (opcional, en paralelo) · Dominio `retrocloud.is-a.dev`

Esto tarda de horas a días, así que hazlo **después** de tener el sitio
funcionando en `TUCUENTA.alwaysdata.net`.

1. Comprueba disponibilidad en <https://is-a.dev>.
2. Haz un *fork* de <https://github.com/is-a-dev/register>.
3. Copia `deploy/is-a-dev/retrocloud.json` a `domains/retrocloud.json`
   dentro del fork, y sustituye `TUCUENTA` por tu cuenta real.
4. Abre el *pull request*.
5. Cuando lo aprueben, añade `retrocloud.is-a.dev` en
   **Web → Sites → Addresses** del sitio. AlwaysData genera y renueva su
   certificado Let's Encrypt automáticamente (lo reintenta cada 30
   minutos durante 24 horas, así que dale tiempo a la propagación DNS).

No marques `"proxied": true`: con el proxy de Cloudflare de por medio se
complica la validación del certificado.

---

## Si algo sale mal

| Síntoma | Causa más probable |
|---|---|
| Error 500 en todo el sitio | *Document root* mal puesto, o falta `src/config.php` |
| "Access denied" al importar | Usaste `schema.sql` en vez de `schema-hosting.sql` |
| Bucle de redirecciones | Falta `CONFIAR_PROXY` en `src/config.php` |
| "Unable to write" al subir | Faltan las carpetas de `storage/`: reejecuta el paso 4 |
| Vistas o trigger ausentes | El usuario de MySQL no tiene `GRANT ALL PRIVILEGES` |
| El emulador no carga | La CSP bloquea el CDN: revisa `EMULADOR_ORIGEN` |

Los registros de error quedan en `~/www/retrocloud/logs/php-error.log` y
los del servidor en `/home/TUCUENTA/admin/logs/http/`.
