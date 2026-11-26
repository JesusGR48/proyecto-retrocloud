# Guía rápida del código · RetroCloud

Chuleta para la exposición. **Si te preguntan X, abre el archivo Y.**

El proyecto son **20,753 líneas** repartidas en PHP, JavaScript, CSS y SQL.

---

## Las tres capas

```
NAVEGADOR                SERVIDOR                 BASE DE DATOS
public/assets/js/   →    src/ + public/api/   →   MySQL
(emulación WASM)         (PHP 8, PDO)             (InnoDB)
```

La regla que lo ordena todo: **el navegador emula, el servidor autoriza y guarda.**

---

## «¿Dónde se ejecuta el juego?»

**`public/jugar.php`** (530 líneas) — prepara la página del reproductor.
**`public/assets/js/emulador.js`** (1,326 líneas) — **aquí está el corazón.**

El juego **no corre en el servidor**. Corre en el navegador, con núcleos de
libretro compilados a WebAssembly. El servidor solo entrega el archivo.

Lo que hace `emulador.js`:

| Líneas | Qué hace |
|---|---|
| `EJS_*` | Configura el motor: núcleo, ROM, BIOS, controles |
| `EJS_cacheLimit` | Tope de caché. **Caso 14.25 del documento**: por omisión son 1 GB y hacía que el móvil se reiniciara |
| `terminarPartida()` | Guarda antes de salir |
| `alternarPantallaCompleta()` | Pantalla completa con prefijos de cada navegador |

---

## «¿Cómo se guardan las partidas?»

Esta es **la pieza que distingue al proyecto**. No es un CRUD.

1. **`public/assets/js/emulador.js`** — pide al emulador un *save state*: un
   volcado binario de la memoria de la consola en ese instante
2. Lo manda por `fetch` con `FormData`
3. **`public/api/saves.php`** (432 líneas) — lo recibe, calcula su **SHA-256**
   y lo guarda **fuera del document root**
4. **`src/Storage.php`** (559 líneas) — escribe el archivo y lo sirve después
   validando la sesión

**Tabla `save_states`**, con `UNIQUE (usuario_id, juego_id, slot)` — por eso
cada ranura tiene a lo sumo una partida, y por eso se puede hacer *upsert*.

---

## «¿Dónde está la seguridad?»

| Qué | Archivo |
|---|---|
| Contraseñas (bcrypt coste 12), sesiones, roles | **`src/Auth.php`** (420) |
| Tokens CSRF con `hash_equals` | **`src/Csrf.php`** |
| Anti fuerza bruta por IP **y** por cuenta | **`src/RateLimiter.php`** |
| Validación de toda entrada, escape anti-XSS | **`src/Validator.php`** (286) |
| Cabeceras, CSP, sesión endurecida | **`src/bootstrap.php`** (433) |
| Consultas preparadas de verdad | **`src/Database.php`** (179) |

**El dato que impresiona:** `Database.php` usa
`ATTR_EMULATE_PREPARES => false`. Son sentencias preparadas **reales**, no
emuladas por PDO. El valor nunca se concatena a la consulta, así que la
inyección SQL es imposible por construcción, no por filtrado.

**Las ROMs no son archivos públicos:** viven fuera del document root y se
sirven por `public/api/rom.php`, que comprueba la sesión. Sin sesión → 401.

---

## «¿Y la inteligencia artificial?»

**`src/Recomendador.php`** (1,058 líneas) — motor híbrido, escrito desde cero,
sin servicios externos ni claves de API.

- **Valoraciones implícitas:** el sitio no tiene estrellas, así que el gusto se
  deduce del comportamiento (favoritos ×5, partidas guardadas ×3, tiempo ×1.6)
- **Dos motores:** contenido (consola, género, década, estudio) y colaborativo
  (similitud del coseno ítem-ítem)
- **Mezcla adaptativa:** el peso de cada motor **se calcula**, no es fijo
- **Tres estrategias:** híbrido · popularidad (arranque en frío) · retomar
  (catálogo agotado)

**`src/Asistente.php`** (645) — la burbuja flotante. Clasificador de
intenciones por reglas. **No es un modelo de lenguaje, y es mejor así**: es
determinista, se puede probar automáticamente, no se inventa juegos y responde
en milisegundos sin internet.

---

## «¿Qué es el laboratorio?»

**`public/assets/js/chip8.js`** (779 líneas) — un **emulador de CHIP-8 escrito
desde cero**, no una librería.

Está construido al revés de un emulador normal: expone su estado interno para
poder enseñarlo. Registros, memoria, desensamblado y una explicación de cada
instrucción según se ejecuta.

Demuestra que entiendes el bucle que mueve a todos los demás:

```
buscar 2 bytes → decodificar → ejecutar → avanzar
```

**`public/assets/js/chip8-programas.js`** — cuatro programas ensamblados a
mano, byte por byte. No son ROMs descargadas.

---

## «¿Y la base de datos?»

**`database/schema.sql`** — 10 tablas, 3 vistas, 1 procedimiento, 1 disparador,
11 claves foráneas, todo InnoDB.

| Tabla | Para qué |
|---|---|
| `usuarios` | Cuentas y roles |
| `consolas` / `generos` | Catálogo maestro |
| `juegos` | Metadatos + hash y tamaño de la ROM |
| `save_states` | **El núcleo**: partidas sincronizadas |
| `sesiones_juego` | Telemetría de uso (alimenta la IA) |
| `favoritos` | Relación N:M |
| `intentos_login` | Anti fuerza bruta |

---

## Si te preguntan algo que no sabes

Di la verdad y lleva a terreno conocido:

> *"Eso no lo recuerdo de memoria, pero está documentado en la sección X del
> documento técnico. Lo que sí le puedo explicar es cómo resolví Y."*

Y tienes **35 problemas documentados** en la sección 14, con sus mediciones.
Esa sección es tu mejor carta: no es teoría, es lo que de verdad pasó.

---

## Los tres números que debes recordar

- **20,753 líneas** de código propio
- **35 problemas** documentados con su diagnóstico y solución
- **0 dependencias** de terceros en el backend: ni Composer, ni frameworks
