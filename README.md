# RetroCloud

**Plataforma de emulación retro con sincronización de partidas en la nube**

Proyecto final · Desarrollo y Programación Web

---

## Qué es

RetroCloud permite jugar títulos de consolas clásicas directamente en el
navegador y **guardar el progreso en el servidor**, de modo que una partida
empezada en la computadora puede continuarse después en el teléfono.

La emulación corre en el cliente mediante núcleos de
[libretro/RetroArch](https://www.libretro.com/) compilados a **WebAssembly**
y orquestados por [EmulatorJS](https://emulatorjs.org/). El servidor no emula
nada: se encarga de la autenticación, el catálogo, la entrega controlada de
las ROMs y la sincronización de las partidas guardadas.

---

## Arranque rápido

### Requisitos

| Componente | Versión mínima | Notas |
|---|---|---|
| PHP | 8.0 | Con `pdo_mysql`, `mbstring`, `json`. Recomendadas: `gd`, `fileinfo`, `openssl` |
| MySQL o MariaDB | 5.7 / 10.2 | InnoDB y `utf8mb4` |
| Servidor web | Apache 2.4 | Con `mod_rewrite`, `mod_headers` y `AllowOverride All` |
| Navegador | Cualquiera con WebAssembly | Chrome, Edge, Firefox o Safari recientes |

En Windows, **MAMP** o **XAMPP** cubren los tres primeros de una sola vez.

### 1. Colocar el proyecto

Copia la carpeta `retrocloud/` dentro del directorio que sirve tu servidor
(`C:\MAMP\htdocs\` o `C:\xampp\htdocs\`).

> **Importante:** el *document root* debe apuntar a `retrocloud/public`, no a
> `retrocloud/`. Así `src/`, `storage/`, `database/` y `logs/` quedan fuera del
> alcance del navegador. Si no puedes cambiar el *document root*, el proyecto
> funciona igual entrando a `http://localhost/retrocloud/public/`, y el
> `.htaccess` de `storage/` sigue bloqueando el acceso directo.

### 2. Crear la base de datos

Desde **phpMyAdmin** (pestaña *Importar*) o por consola:

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql
```

`schema.sql` crea la base `retrocloud` con sus 10 tablas, 3 vistas, 1
procedimiento almacenado y 1 disparador. `seed.sql` carga las 12 consolas, los
géneros, los logros y **dos cuentas de prueba** (sus contraseñas están
documentadas dentro del propio archivo; cámbialas antes de publicar).

El catálogo de juegos queda vacío a propósito: se llena desde el panel.

### 3. Configurar la conexión

Edita `src/config.php` si tus credenciales no son las de MAMP por defecto:

```php
define('DB_HOST',     '127.0.0.1');
define('DB_PUERTO',   3306);
define('DB_NOMBRE',   'retrocloud');
define('DB_USUARIO',  'root');
define('DB_PASSWORD', 'root');   // MAMP: root/root · XAMPP: root sin contraseña
```

### 4. Comprobar la instalación

Abre `http://localhost/retrocloud/public/diagnostico.php`.

Esa página revisa automáticamente la versión de PHP, las extensiones, la
conexión a MySQL, la presencia de las tablas, los permisos de escritura, los
límites de subida y el estado de HTTPS. **Si algo falta, ahí lo dice.**

### 5. Entrar y cargar juegos

1. Inicia sesión con la cuenta de administrador de `seed.sql`.
2. Ve a **Panel → Juegos → Nuevo juego**.
3. Sube la ROM, elige la consola y completa la ficha.

---

## Sobre el catálogo y las licencias

Los emuladores son software legal. Las ROMs comerciales, en cambio, están
protegidas por derechos de autor y no pueden redistribuirse libremente.

El campo **Licencia** de cada juego existe precisamente para documentar la
procedencia de cada archivo. Sube únicamente ROMs que tengas derecho a
distribuir:

- **Homebrew**: juegos creados por la comunidad y publicados libremente.
- **Dominio público** o licencias abiertas (CC0, CC BY, GPL).
- **Demos técnicas** y *test suites* de la escena de desarrollo.
- **Copias de respaldo** de cartuchos propios, para uso personal.

---

## Estructura del proyecto

```
retrocloud/
├── public/                    ← ÚNICO directorio expuesto al navegador
│   ├── index.php                 catálogo
│   ├── login.php · registro.php  acceso
│   ├── jugar.php                 reproductor del emulador
│   ├── perfil.php                partidas, favoritos, logros
│   ├── diagnostico.php           comprobación del entorno
│   ├── admin/                    panel de administración
│   │   ├── index.php             resumen y gráficas
│   │   ├── juegos.php            CRUD del catálogo
│   │   └── usuarios.php          gestión de cuentas
│   ├── api/                      API REST (JSON)
│   │   ├── auth.php              registro, login, logout, contraseña
│   │   ├── juegos.php            catálogo y CRUD
│   │   ├── rom.php               entrega controlada de ROMs
│   │   ├── saves.php             partidas guardadas
│   │   ├── sesiones.php          telemetría de juego
│   │   ├── favoritos.php         relación N:M
│   │   ├── usuarios.php          administración de cuentas
│   │   └── stats.php             estadísticas y rankings
│   ├── partes/                   plantillas compartidas
│   ├── assets/                   css, js, imágenes, portadas
│   ├── .htaccess                 configuración de Apache
│   ├── manifest.webmanifest      PWA
│   └── sw.js                     Service Worker
│
├── src/                       ← FUERA del document root
│   ├── config.php                configuración central
│   ├── bootstrap.php             arranque, errores, cabeceras, sesión
│   ├── Database.php              PDO + sentencias preparadas
│   ├── Auth.php                  autenticación y roles
│   ├── Csrf.php                  protección CSRF
│   ├── Validator.php             validación y escapado
│   ├── RateLimiter.php           anti fuerza bruta
│   ├── Response.php              respuestas JSON uniformes
│   └── Storage.php               archivos binarios
│
├── storage/                   ← FUERA del document root
│   ├── roms/                     ROMs (servidas solo por PHP)
│   ├── saves/                    partidas guardadas
│   ├── capturas/                 miniaturas
│   └── sesiones/                 archivos de sesión
│
├── database/
│   ├── schema.sql                estructura
│   └── seed.sql                  datos iniciales
│
├── docs/
│   ├── documento-tecnico.md      documentación del proyecto
│   └── configuracion-ssl.md      HTTPS paso a paso
│
└── logs/                         registro de errores
```

---

## Uso

### Jugar

Entra al catálogo, elige un juego y pulsa para cargarlo. El primer arranque
descarga el núcleo de emulación de la consola (unos megabytes); después queda
en la caché del navegador.

**Controles**

| Acción | Teclado |
|---|---|
| Movimiento | Flechas |
| Botones A / B | <kbd>X</kbd> <kbd>Z</kbd> |
| Start / Select | <kbd>Enter</kbd> <kbd>Shift</kbd> |
| Gatillos L / R | <kbd>Q</kbd> <kbd>E</kbd> |

**Atajos de RetroCloud**

| Acción | Atajo |
|---|---|
| Guardar en ranura 1-4 | <kbd>F1</kbd> … <kbd>F4</kbd> |
| Cargar de ranura 1-4 | <kbd>Shift</kbd> + <kbd>F1</kbd> … <kbd>F4</kbd> |
| Guardado rápido | <kbd>F5</kbd> |

**Mandos.** Conecta un control de Xbox, PlayStation o cualquier *gamepad*
compatible: el navegador lo reconoce por la *Gamepad API* y aparece indicado
en la pantalla de juego. Los botones se pueden reasignar desde el engranaje
del emulador.

**Móviles.** En pantallas táctiles se dibujan controles virtuales sobre el
juego. Girando el teléfono en horizontal, el emulador ocupa toda la pantalla.

### Guardar partidas

Cada juego tiene **5 ranuras**: la 0 es el autoguardado (se actualiza sola cada
3 minutos) y las 1 a 4 son manuales. Cada ranura almacena un volcado binario
completo del estado del emulador, con su firma SHA-256 y el tiempo jugado.

Desde **Mis partidas** puedes continuar cualquiera de ellas en otro
dispositivo.

---

## Funcionamiento sin internet (opcional)

Por omisión, los núcleos de emulación se descargan del CDN oficial de
EmulatorJS. Para una presentación sin conexión fiable, instálalos en local:

1. Descarga la última versión desde
   [github.com/EmulatorJS/EmulatorJS/releases](https://github.com/EmulatorJS/EmulatorJS/releases).
2. Descomprime y copia la carpeta `data/` dentro de `public/emulatorjs/`, de
   modo que exista `public/emulatorjs/data/loader.js`.

**La aplicación lo detecta sola**: si ese archivo existe, usa la copia local;
si no, recurre al CDN. No hay que tocar ninguna configuración.

> El paquete completo pesa unos 300 MB porque incluye los núcleos de todas las
> consolas. Puedes borrar las carpetas de los sistemas que no uses.

---

## Documentación

- **[docs/documento-tecnico.md](docs/documento-tecnico.md)** — análisis,
  arquitectura, base de datos, seguridad y pruebas.
- **[docs/configuracion-ssl.md](docs/configuracion-ssl.md)** — HTTPS con
  certificado propio, paso a paso.

---

## Antes de entregar o publicar

- [ ] Cambiar las contraseñas de las cuentas de `seed.sql`
- [ ] Poner `APP_ENTORNO` en `'produccion'` dentro de `src/config.php`
- [ ] Borrar `public/diagnostico.php` o protegerlo con sesión de administrador
- [ ] Activar HTTPS y poner `FORZAR_HTTPS` en `true`
- [ ] Crear un usuario de MySQL con permisos limitados en lugar de `root`
- [ ] Verificar que el *document root* apunta a `public/`

---

## Créditos

- **EmulatorJS** — núcleos de emulación en WebAssembly ([GPL-3.0](https://github.com/EmulatorJS/EmulatorJS))
- **libretro / RetroArch** — implementación de los núcleos
- **Tipografías** — Inter, JetBrains Mono y Press Start 2P (Google Fonts, SIL OFL)

El código de RetroCloud es original y se desarrolló para esta asignatura.
