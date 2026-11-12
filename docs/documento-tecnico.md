# RetroCloud · Documento técnico

**Plataforma de emulación retro con sincronización de partidas en la nube**

Proyecto final · Desarrollo y Programación Web

---

## Índice

1. [Introducción](#1-introducción)
2. [Planteamiento del problema](#2-planteamiento-del-problema)
3. [Objetivos](#3-objetivos)
4. [Análisis de requerimientos](#4-análisis-de-requerimientos)
5. [Arquitectura del sistema](#5-arquitectura-del-sistema)
6. [Diseño de la base de datos](#6-diseño-de-la-base-de-datos)
7. [Desarrollo del frontend](#7-desarrollo-del-frontend)
8. [Desarrollo del backend](#8-desarrollo-del-backend)
9. [El motor de emulación](#9-el-motor-de-emulación)
10. [Sincronización de partidas](#10-sincronización-de-partidas)
11. [Seguridad](#11-seguridad)
12. [Servidor y despliegue](#12-servidor-y-despliegue)
13. [Pruebas realizadas](#13-pruebas-realizadas)
14. [Problemas encontrados y soluciones](#14-problemas-encontrados-y-soluciones)
15. [Conclusiones](#15-conclusiones)
16. [Referencias](#16-referencias)

---

## 1. Introducción

RetroCloud es una aplicación web que permite jugar títulos de consolas
clásicas directamente en el navegador y **conservar el progreso en un
servidor**, de manera que una partida iniciada en una computadora puede
continuarse más tarde desde un teléfono.

El proyecto integra las tres capas que exige la asignatura —cliente, servidor
y base de datos— alrededor de un problema que no se resuelve con un CRUD
convencional: el transporte, la validación y el versionado de **archivos
binarios** generados en tiempo real por un emulador que corre dentro del
navegador.

El componente técnico que vertebra el sistema es el **save state**: un volcado
completo de la memoria del emulador en un instante exacto. Ese archivo es lo
que viaja entre cliente y servidor, lo que se valida, se firma, se almacena
fuera del *document root* y se indexa en MySQL.

---

## 2. Planteamiento del problema

Los emuladores de consolas retro son abundantes y en su mayoría gratuitos,
pero comparten tres limitaciones prácticas:

1. **Requieren instalación.** Descargar un ejecutable, configurar rutas de
   BIOS y mapear controles es una barrera para el usuario ocasional, y resulta
   directamente imposible en un equipo ajeno o restringido.

2. **El progreso queda atado a la máquina.** Los archivos de guardado viven en
   el disco local. Cambiar de computadora significa perder la partida o
   copiarla a mano.

3. **No hay continuidad entre dispositivos.** Un emulador de escritorio y uno
   de teléfono no comparten nada, aunque ambos ejecuten el mismo juego.

Existen sitios que resuelven la primera limitación emulando en el navegador,
pero guardan el progreso en el almacenamiento local (`localStorage` o
IndexedDB). Eso lo ata al navegador: borrar la caché, usar modo privado o
cambiar de equipo equivale a perderlo todo.

**RetroCloud ataca las tres.** Emula en el navegador (sin instalación) y
guarda las partidas en el servidor, asociadas a una cuenta de usuario (con
continuidad real entre dispositivos).

---

## 3. Objetivos

### Objetivo general

Desarrollar una aplicación web completa que integre frontend, backend y base
de datos para ofrecer emulación de consolas retro en el navegador con
sincronización de partidas en la nube, aplicando diseño responsivo y medidas
de seguridad.

### Objetivos específicos

| # | Objetivo | Cumplimiento |
|---|---|---|
| 1 | Analizar una problemática resoluble mediante una aplicación web | Secciones 2 y 4 |
| 2 | Diseñar la estructura lógica y visual del sistema | Secciones 5 y 6 |
| 3 | Implementar interfaces con HTML5 y CSS3 | Sección 7 |
| 4 | Programar funcionalidades dinámicas con JavaScript | Secciones 7, 9 y 10 |
| 5 | Desarrollar la lógica del servidor con PHP | Sección 8 |
| 6 | Implementar y gestionar una base de datos | Sección 6 |
| 7 | Desplegar la aplicación en un servidor web | Sección 12 |
| 8 | Documentar el proceso de desarrollo | Este documento |

---

## 4. Análisis de requerimientos

### 4.1 Requerimientos funcionales

| ID | Requerimiento | Prioridad |
|---|---|---|
| RF-01 | Registro de usuarios con validación de datos | Alta |
| RF-02 | Inicio y cierre de sesión | Alta |
| RF-03 | Dos roles: jugador y administrador | Alta |
| RF-04 | Catálogo con búsqueda, filtros por consola y género, orden y paginación | Alta |
| RF-05 | Ejecución de juegos en el navegador | Alta |
| RF-06 | Guardar el estado de la partida en el servidor (5 ranuras) | Alta |
| RF-07 | Restaurar una partida desde cualquier dispositivo | Alta |
| RF-08 | Autoguardado periódico | Media |
| RF-09 | CRUD completo del catálogo (solo administradores) | Alta |
| RF-10 | Subida de ROMs y portadas con validación | Alta |
| RF-11 | Gestión de usuarios (rol, bloqueo, baja) | Media |
| RF-12 | Favoritos | Baja |
| RF-13 | Estadísticas de uso y ranking | Media |
| RF-14 | Logros por progreso | Baja |
| RF-15 | Soporte de mandos físicos y controles táctiles | Media |

### 4.2 Requerimientos no funcionales

| ID | Requerimiento |
|---|---|
| RNF-01 | Interfaz responsiva desde 320 px hasta pantallas de escritorio |
| RNF-02 | Contraseñas almacenadas exclusivamente como *hash* |
| RNF-03 | Inmunidad a inyección SQL mediante sentencias preparadas |
| RNF-04 | Protección contra CSRF en toda operación que modifique estado |
| RNF-05 | Las ROMs no deben ser accesibles sin sesión válida |
| RNF-06 | Soporte de HTTPS con cabeceras de seguridad |
| RNF-07 | Navegación por teclado y etiquetas para lectores de pantalla |
| RNF-08 | Funcionamiento en Chrome, Edge, Firefox y Safari recientes |

### 4.3 Actores

| Actor | Permisos |
|---|---|
| **Visitante** | Ver el catálogo. No puede jugar ni guardar |
| **Jugador** | Jugar, guardar y restaurar partidas, favoritos, perfil |
| **Administrador** | Todo lo anterior más CRUD del catálogo, gestión de usuarios y estadísticas |

---

## 5. Arquitectura del sistema

### 5.1 Visión general

```
┌─────────────────── NAVEGADOR (cliente) ───────────────────┐
│                                                            │
│   Interfaz          HTML5 · CSS3 · JavaScript ES6          │
│   ───────────────────────────────────────────────────────  │
│   Emulación         EmulatorJS → núcleo libretro (.wasm)   │
│                     Canvas/WebGL · Web Audio · Gamepad API │
│                                                            │
└──────────────────────────┬─────────────────────────────────┘
                           │
              fetch() / XHR · JSON y multipart
                           │  HTTPS
┌──────────────────────────▼─────────────────────────────────┐
│                  APACHE + PHP 8 (servidor)                 │
│                                                            │
│   public/     páginas y API REST     ← único dir expuesto  │
│   src/        lógica, seguridad, acceso a datos            │
│   storage/    ROMs, partidas, capturas, sesiones           │
│                                                            │
└──────────────────────────┬─────────────────────────────────┘
                           │  PDO · sentencias preparadas
┌──────────────────────────▼─────────────────────────────────┐
│                       MySQL / InnoDB                       │
│   10 tablas · 3 vistas · 1 procedimiento · 1 disparador    │
└────────────────────────────────────────────────────────────┘
```

### 5.2 Decisión de arquitectura: dónde se emula

El punto de diseño más importante del proyecto es que **la emulación ocurre
en el cliente, no en el servidor**.

La alternativa —emular en el servidor y transmitir vídeo al navegador— es
técnicamente viable (es lo que hacen los servicios de juego en la nube), pero
se descartó por tres razones:

| Criterio | Emular en el servidor | Emular en el cliente |
|---|---|---|
| Carga del servidor | Un proceso de emulación por usuario concurrente | Prácticamente nula |
| Latencia de entrada | Ida y vuelta por red en cada pulsación | Inmediata |
| Ancho de banda | Flujo de vídeo continuo | Solo la descarga inicial |
| Viabilidad en MAMP local | Ninguna | Total |

El servidor queda así dedicado a lo que un servidor hace bien: autenticar,
autorizar, validar, persistir y servir archivos de forma controlada.

### 5.3 Separación de directorios

Solo `public/` es accesible desde el navegador. `src/`, `storage/`,
`database/` y `logs/` quedan por encima del *document root*:

```
retrocloud/
├── public/     ← document root
├── src/        ← código, configuración y credenciales
├── storage/    ← ROMs, partidas guardadas, sesiones
├── database/   ← scripts SQL
└── logs/       ← registro de errores
```

El beneficio es concreto: aunque alguien adivine la ruta exacta de una ROM, el
servidor web no tiene forma de entregarla. El único camino es
`api/rom.php`, que antes verifica la sesión. Esto se comprobó empíricamente
(sección 13).

### 5.4 Flujo de una petición

```
  Petición HTTP
       │
       ▼
  bootstrap.php     carga configuración · registra autocargador
       │            manejadores de error · cabeceras de seguridad
       │            redirección HTTPS · sesión endurecida
       ▼
  Punto de entrada  página PHP o endpoint de la API
       │
       ▼
  Csrf::exigir()    si el método no es GET
       │
       ▼
  Auth::exigir*()   comprueba sesión y rol
       │
       ▼
  Validator         valida y sanea la entrada
       │
       ▼
  Database          consulta con sentencia preparada
       │
       ▼
  Response / HTML   JSON uniforme o plantilla
```

---

## 6. Diseño de la base de datos

### 6.1 Modelo entidad-relación

```
   ┌──────────────┐                      ┌──────────────┐
   │   usuarios   │                      │   consolas   │
   ├──────────────┤                      ├──────────────┤
   │ id (PK)      │                      │ id (PK)      │
   │ nombre_usuario (UQ)                 │ nombre       │
   │ correo (UQ)  │                      │ slug (UQ)    │
   │ hash_password│                      │ core_emulador│
   │ rol          │                      │ extensiones  │
   │ activo       │                      │ color_hex    │
   └──┬────┬────┬─┘                      └──────┬───────┘
      │    │    │                               │ 1
      │    │    │                               │
      │    │    │         ┌─────────────┐       │ N
      │    │    │         │   generos   │   ┌───▼──────────┐
      │    │    │         ├─────────────┤   │    juegos    │
      │    │    │         │ id (PK)     │ 1 ├──────────────┤
      │    │    │         │ nombre      ├───┤ id (PK)      │
      │    │    │         └─────────────┘ N │ consola_id FK│
      │    │    │                           │ genero_id FK │
      │    │    │                           │ titulo       │
      │    │    │                           │ slug (UQ)    │
      │    │    │                           │ archivo_rom  │
      │    │    │                           │ rom_hash (UQ)│
      │    │    │                           │ publicado    │
      │    │    │                           └──┬───┬───┬───┘
      │    │    │                              │   │   │
      │ 1  │ 1  │ 1                          N │ N │ N │
      │    │    │                              │   │   │
   ┌──▼────▼────▼──────────────────────────────▼───▼───▼──┐
   │                                                       │
   │  save_states          sesiones_juego      favoritos   │
   │  ───────────          ──────────────      ─────────   │
   │  id (PK)              id (PK)             usuario_id  │
   │  usuario_id FK        usuario_id FK        + juego_id │
   │  juego_id FK          juego_id FK          (PK comp.) │
   │  slot                 iniciada_en                     │
   │  archivo              finalizada_en                   │
   │  hash                 segundos_jugados                │
   │  bytes                                                │
   │  segundos_juego                                       │
   │  UQ(usuario,juego,slot)                               │
   └───────────────────────────────────────────────────────┘

   ┌──────────────┐   ┌─────────────────┐   ┌───────────────┐
   │    logros    │   │ usuario_logros  │   │ intentos_login│
   ├──────────────┤ 1 ├─────────────────┤ N ├───────────────┤
   │ id (PK)      ├───┤ usuario_id FK   │   │ id (PK)       │
   │ codigo (UQ)  │   │ logro_id FK     │   │ identificador │
   │ tipo         │   │ obtenido_en     │   │ ip            │
   │ umbral       │   │ (PK compuesta)  │   │ exito         │
   └──────────────┘   └─────────────────┘   │ creado_en     │
                                            └───────────────┘
```

### 6.2 Tablas

| Tabla | Propósito | Filas de arranque |
|---|---|---|
| `usuarios` | Cuentas, credenciales y rol | 2 (seed) |
| `consolas` | Plataformas emulables y su núcleo | 12 (seed) |
| `generos` | Clasificación de juegos | 12 (seed) |
| `juegos` | Catálogo y metadatos de la ROM | 0 (se llenan desde el panel) |
| `save_states` | Partidas guardadas | 0 |
| `sesiones_juego` | Telemetría de uso | 0 |
| `favoritos` | Relación N:M usuario↔juego | 0 |
| `intentos_login` | Registro para limitar fuerza bruta | 0 |
| `logros` | Definición de logros | 9 (seed) |
| `usuario_logros` | Logros conseguidos | 0 |

### 6.3 Decisiones de diseño

**Normalización.** El esquema está en tercera forma normal. `consolas` y
`generos` se extrajeron a tablas propias en lugar de guardarse como texto en
`juegos`: así cambiar el nombre de una consola es un solo `UPDATE` y no hay
posibilidad de que convivan "Game Boy" y "GameBoy" como valores distintos.

**Claves foráneas con comportamiento explícito.** Cada relación declara qué
debe ocurrir al borrar el registro padre:

| Relación | Regla | Razón |
|---|---|---|
| `juegos` → `consolas` | `RESTRICT` | Impide borrar una consola que aún tiene juegos |
| `juegos` → `generos` | `SET NULL` | Borrar un género no debe borrar juegos |
| `save_states` → `usuarios` | `CASCADE` | Al borrar una cuenta se van sus partidas |
| `save_states` → `juegos` | `CASCADE` | Al borrar un juego se van sus partidas |
| `juegos` → `usuarios` (autor) | `SET NULL` | El juego sobrevive a la baja de quien lo subió |

**Restricción única sobre la ranura.** `UNIQUE (usuario_id, juego_id, slot)`
garantiza que cada ranura contenga como mucho una partida, y permite resolver
guardar/sobrescribir con una sola sentencia `INSERT ... ON DUPLICATE KEY
UPDATE` en lugar de un `SELECT` seguido de un `INSERT` o un `UPDATE`.

**Hash único de la ROM.** `UNIQUE (rom_hash)` sobre el SHA-256 impide subir el
mismo archivo dos veces aunque se renombre. La detección es por contenido, no
por nombre.

**Índices.** Además de las claves, se indexaron las columnas que aparecen en
los filtros del catálogo (`publicado`, `titulo`, `consola_id`, `genero_id`) y
las usadas por el limitador de intentos (`ip + creado_en`,
`identificador + creado_en`).

### 6.4 Vistas

Tres vistas encapsulan las consultas recurrentes:

- **`vista_catalogo`** — une `juegos`, `consolas` y `generos`. Evita repetir el
  mismo `JOIN` en el listado, el detalle y el panel.
- **`vista_ranking`** — agrega tiempo total y jugadores únicos por juego.
- **`vista_perfil`** — reúne las cinco métricas del perfil en una sola fila.

### 6.5 Procedimiento almacenado

`sp_cerrar_sesion` cierra una sesión de juego y actualiza el contador del
juego en una única llamada, en lugar de dos viajes desde PHP:

```sql
CREATE PROCEDURE sp_cerrar_sesion (
  IN p_sesion_id INT UNSIGNED,
  IN p_usuario_id INT UNSIGNED,
  IN p_segundos INT UNSIGNED
)
BEGIN
  UPDATE sesiones_juego
     SET finalizada_en = NOW(), segundos_jugados = p_segundos
   WHERE id = p_sesion_id AND usuario_id = p_usuario_id;

  UPDATE juegos j JOIN sesiones_juego s ON s.juego_id = j.id
     SET j.veces_jugado = j.veces_jugado + 1
   WHERE s.id = p_sesion_id;
END
```

Nótese el filtro `AND usuario_id = p_usuario_id`: la autorización se aplica
también dentro del procedimiento, no solo en PHP.

### 6.6 Disparador

`tr_save_slot_valido` rechaza en la propia base de datos cualquier inserción
con una ranura fuera de rango. Es una red de seguridad: aunque un fallo en PHP
dejara pasar un valor inválido, el motor lo detiene.

---

## 7. Desarrollo del frontend

### 7.1 HTML5

Se emplea marcado semántico (`<header>`, `<nav>`, `<main>`, `<section>`,
`<article>`, `<aside>`, `<footer>`) en lugar de `<div>` genéricos, lo que
mejora tanto la accesibilidad como la legibilidad del código.

Elementos de HTML5 aprovechados:

- Tipos de campo `email`, `password`, `number`, `search`, `file`
- Atributos de validación `required`, `pattern`, `minlength`, `maxlength`, `accept`
- `<datalist>` para sugerencias de licencia
- `<canvas>` como superficie de render del emulador
- API de arrastrar y soltar en la subida de archivos
- `<template>` implícito mediante bloques `<script type="application/json">`
  para pasar configuración del servidor al cliente

### 7.2 CSS3

La hoja de estilos (`assets/css/estilos.css`) está organizada en once
secciones numeradas. Técnicas aplicadas:

| Técnica | Uso en el proyecto |
|---|---|
| Custom properties | Paleta y medidas centralizadas; permiten el tema claro/oscuro |
| Flexbox | Barra de navegación, filtros, barras de acciones |
| Grid | Rejilla del catálogo, panel de administración, métricas |
| Media queries | Puntos de ruptura en 620, 860, 980, 1100 y 1600 px |
| `clamp()` | Tipografía fluida sin escalonar media queries |
| `aspect-ratio` | Reserva el espacio del emulador y evita saltos de layout |
| Animaciones `@keyframes` | Notificaciones, cargadores, esqueletos |
| Gradientes | Fondo, botones, insignias |
| `backdrop-filter` | Barra superior traslúcida |
| `color-mix()` | Variantes de color derivadas, con alternativa en `@supports` |

Se incluyen además técnicas de **CSS2** —selectores de descendencia,
pseudoclases `:hover` y `:focus`, `float`, `position`, modelo de caja y
`z-index`— conforme pide el temario.

### 7.3 Diseño responsivo

El enfoque es **mobile first**: los estilos base sirven al móvil y las media
queries añaden complejidad conforme crece la pantalla.

| Rango | Adaptación |
|---|---|
| < 620 px | Rejilla de 2 columnas, modales a pantalla completa desde abajo, favoritos siempre visibles (no hay *hover*) |
| < 860 px | Navegación colapsada en menú hamburguesa |
| < 980 px | Panel de administración en una columna |
| ≥ 1100 px | Reproductor con barra lateral de partidas |
| ≥ 1600 px | Contenedor más ancho |
| Móvil horizontal | El emulador ocupa el alto completo de la ventana |

La rejilla del catálogo usa `repeat(auto-fill, minmax(min(100%, 185px), 1fr))`,
que **adapta el número de columnas sin necesidad de media queries**.

### 7.4 Accesibilidad

- Enlace "saltar al contenido" visible al tabular
- `aria-label`, `aria-pressed`, `aria-current`, `aria-live`, `aria-busy`
- Foco visible y consistente mediante `:focus-visible`
- Foco atrapado dentro de los diálogos modales, que se cierran con `Escape`
- Soporte de `prefers-reduced-motion` y `prefers-contrast`
- Contraste de texto verificado sobre los colores de consola calculando la
  luminancia percibida en tiempo de ejecución

### 7.5 JavaScript

Código organizado en **módulos ES6** con responsabilidades separadas:

| Archivo | Responsabilidad |
|---|---|
| `api.js` | Capa de comunicación; CSRF automático, manejo uniforme de errores, utilidades |
| `app.js` | Tema, navegación móvil, catálogo, Service Worker |
| `auth.js` | Formularios de acceso y medidor de fuerza de contraseña |
| `emulador.js` | Reproductor, ranuras y telemetría |
| `perfil.js` | Perfil, partidas, favoritos y logros |
| `admin-dashboard.js` | Métricas y gráficas |
| `admin-juegos.js` | CRUD del catálogo con progreso de subida |
| `admin-usuarios.js` | Gestión de cuentas |

Características de ES6+ empleadas: módulos, clases, `async/await`,
desestructuración, parámetros por defecto, plantillas literales, encadenamiento
opcional, operador de coalescencia nula, `Map`, `Set`, `Promise.allSettled` y
`AbortController`.

Patrones aplicados:

- **Delegación de eventos.** Un solo *listener* por contenedor, que sigue
  funcionando tras repintar el HTML.
- **Antirrebote.** La búsqueda espera 350 ms tras la última tecla.
- **Carreras descartadas.** Cada petición del catálogo lleva un número de
  secuencia; si llega una respuesta vieja, se ignora.
- **Actualización optimista.** El icono de favorito cambia al instante y se
  revierte si el servidor rechaza.
- **Estados de carga.** Esqueletos animados en lugar de pantallas vacías.

---

## 8. Desarrollo del backend

### 8.1 Organización

El backend evita un `require` disperso por cada archivo. Todo punto de entrada
incluye `src/bootstrap.php`, que registra un **autocargador PSR-4
simplificado**:

```php
spl_autoload_register(static function (string $clase): void {
    $prefijo = 'RetroCloud\\';
    if (!str_starts_with($clase, $prefijo)) return;
    $archivo = __DIR__ . '/' . str_replace('\\', '/', substr($clase, strlen($prefijo))) . '.php';
    if (is_file($archivo)) require_once $archivo;
});
```

### 8.2 Clases del núcleo

| Clase | Responsabilidad |
|---|---|
| `Database` | Conexión PDO única y sentencias preparadas |
| `Auth` | Registro, login, roles y guardianes de acceso |
| `Csrf` | Generación y verificación de tokens |
| `Validator` | Validación de entrada, escapado y formateo |
| `RateLimiter` | Registro de intentos y bloqueo temporal |
| `Response` | Respuestas JSON uniformes y códigos HTTP |
| `Storage` | Archivos binarios, rutas seguras y streaming |

### 8.3 API REST

Ocho endpoints bajo `public/api/`, todos con el mismo contrato:

```json
{ "ok": true,  "datos": { } }
{ "ok": false, "error": "mensaje", "campos": { "campo": "detalle" } }
```

| Endpoint | Acciones |
|---|---|
| `auth.php` | `registro`, `login`, `logout`, `password`, `sesion` |
| `juegos.php` | `listar`, `detalle`, `consolas`, `generos`, `crear`, `actualizar`, `eliminar` |
| `rom.php` | Entrega controlada del archivo |
| `saves.php` | `listar`, `mis_partidas`, `guardar`, `descargar`, `captura`, `eliminar` |
| `sesiones.php` | `iniciar`, `latido`, `cerrar` |
| `favoritos.php` | `listar`, `alternar` |
| `usuarios.php` | `listar`, `rol`, `activo`, `eliminar` |
| `stats.php` | `perfil`, `ranking`, `resumen`, `dashboard` |

Los códigos HTTP se usan con su significado: `200` correcto, `201` creado,
`401` sin sesión, `403` sin permisos, `404` inexistente, `405` método
incorrecto, `409` conflicto, `419` token CSRF inválido, `422` validación
fallida, `429` demasiados intentos, `500` error interno.

### 8.4 Formularios dinámicos

El formulario de alta de juegos ilustra el enfoque:

- La consola seleccionada determina las extensiones aceptadas, que se
  reflejan al vuelo en el atributo `accept` y en el texto de ayuda.
- La ROM y la portada admiten arrastrar y soltar.
- La portada se previsualiza con `URL.createObjectURL()`.
- La subida muestra **progreso real**. Esto obligó a usar `XMLHttpRequest` en
  lugar de `fetch()`: la API `fetch` no expone eventos de progreso de carga, y
  una ROM de decenas de megabytes sin barra de progreso parece un cuelgue.
- Los errores del validador del servidor se pintan junto a cada campo.

---

## 9. El motor de emulación

### 9.1 Cómo funciona

RetroCloud usa **EmulatorJS**, que empaqueta los núcleos de emulación del
proyecto **libretro/RetroArch** compilados a **WebAssembly**.

WebAssembly es un formato binario que los navegadores ejecutan a velocidad
cercana a la nativa. Permite tomar un emulador escrito en C/C++ —décadas de
trabajo de ingeniería inversa sobre el hardware original— y correrlo dentro de
una pestaña sin modificarlo sustancialmente.

Secuencia de arranque:

```
1. El navegador descarga loader.js
2. loader.js descarga el núcleo de la consola (.wasm)      ej. fceumm → NES
3. Se solicita la ROM a api/rom.php (con sesión validada)
4. El núcleo se instancia y recibe la ROM en memoria
5. Cada fotograma se dibuja en un <canvas> por WebGL
6. El audio sale por la Web Audio API
7. La entrada llega por teclado, Gamepad API o controles táctiles
```

### 9.2 Consolas configuradas

| Consola | Núcleo | Consola | Núcleo |
|---|---|---|---|
| NES | `nes` | Nintendo DS | `nds` |
| Super Nintendo | `snes` | PlayStation | `psx` |
| Game Boy / Color | `gb` | Sega Genesis | `segaMD` |
| Game Boy Advance | `gba` | Sega Master System | `segaMS` |
| Nintendo 64 | `n64` | Virtual Boy | `vb` |
| Atari 2600 | `atari2600` | Arcade | `arcade` |

El núcleo es un dato de la tabla `consolas`, no una constante del código:
añadir una consola es insertar una fila.

### 9.3 Mandos y controles táctiles

El soporte de mandos físicos proviene de la **Gamepad API** del navegador,
estándar desde hace años. El sistema operativo expone el control, el navegador
lo normaliza a un esquema de ejes y botones, y el emulador lo consume. No hay
drivers ni configuración: funciona con controles de Xbox, PlayStation y
genéricos.

La interfaz detecta la conexión y muestra el nombre del mando, usando los
eventos `gamepadconnected` y `gamepaddisconnected`. Chrome no dispara el
primero hasta que se pulsa un botón, así que se sondea un par de veces al
inicio.

En dispositivos táctiles, EmulatorJS dibuja un mando virtual superpuesto al
canvas.

### 9.4 Instalación local opcional

Por omisión los núcleos se descargan del CDN oficial. El código detecta si
existe `public/emulatorjs/data/loader.js` y, en ese caso, usa la copia local:

```php
$usaLocal  = is_file(__DIR__ . '/emulatorjs/data/loader.js');
$rutaDatos = $usaLocal ? 'emulatorjs/data/' : 'https://cdn.emulatorjs.org/stable/data/';
```

Esto permite una demostración sin conexión sin cambiar una línea de
configuración.

---

## 10. Sincronización de partidas

Esta es la funcionalidad central del proyecto y la que justifica el backend.

### 10.1 Qué es un save state

Un *save state* no es la "partida guardada" que ofrece el juego desde su menú.
Es un **volcado binario completo del estado del emulador** en un instante
exacto: memoria RAM, registros de la CPU, estado del chip de vídeo y del de
sonido. Al restaurarlo, la ejecución continúa en el mismo fotograma.

Su tamaño va de unos kilobytes (NES) a varios megabytes (PlayStation).

### 10.2 Flujo completo

```
   NAVEGADOR                                 SERVIDOR
   ─────────                                 ────────

   emulador.getState()
     → Uint8Array
          │
   new Blob([datos])
   FormData + fetch POST ───────────────────►  saves.php?accion=guardar
                                                 │
                                                 ├─ Auth::exigirApi()
                                                 ├─ Csrf::exigir()
                                                 ├─ validar tamaño
                                                 ├─ calcular SHA-256
                                                 ├─ escribir en storage/saves/
                                                 └─ INSERT ... ON DUPLICATE KEY UPDATE
                                                 │
          ◄──────────────────────────────────────┘
            { slot, bytes, hash, guardado_en }


   fetch GET descargar ─────────────────────►  saves.php?accion=descargar
                                                 │
                                                 ├─ filtrar por usuario_id
                                                 ├─ resolver ruta segura
                                                 └─ streaming con soporte Range
          ◄──────────────────────────────────────┘
     → ArrayBuffer
   emulador.loadState(bytes)
```

### 10.3 Decisiones de implementación

**Nombre de archivo determinista.** Cada ranura escribe siempre en
`u{usuario}_j{juego}_s{slot}.state`. Sobrescribir una ranura reutiliza el
archivo en lugar de acumular huérfanos en disco.

**UPSERT en una sentencia.** Apoyándose en la restricción única, guardar y
sobrescribir son la misma operación:

```sql
INSERT INTO save_states (usuario_id, juego_id, slot, ...)
VALUES (:u, :j, :s, ...)
ON DUPLICATE KEY UPDATE
   archivo = VALUES(archivo), bytes = VALUES(bytes),
   hash = VALUES(hash), actualizado_en = NOW()
```

**Firma SHA-256.** Permite verificar que el archivo no se corrompió y se
expone abreviada en la interfaz como dato de integridad.

**Miniatura.** Al guardar se captura el fotograma actual del canvas, se
reescala a 192 px de ancho y se envía como PNG en base64. El servidor verifica
la firma del formato PNG antes de escribirlo.

**Autoguardado.** Cada 3 minutos, en la ranura 0, y solo si la pestaña está
visible. Falla en silencio: un problema de red pasajero no debe interrumpir el
juego con un mensaje de error.

**Telemetría resistente al cierre.** El cliente reporta tiempo jugado cada 30
segundos. Al abandonar la página usa `navigator.sendBeacon()`, que el navegador
entrega aunque la pestaña ya se haya cerrado —algo que `fetch()` no garantiza.
Se escucha `pagehide` en lugar de `beforeunload` porque en móviles el navegador
puede congelar la pestaña sin disparar el segundo.

**Cronómetro honesto.** El tiempo solo avanza con la pestaña visible: dejar el
juego de fondo no debe inflar las estadísticas. El servidor además recorta
cualquier valor superior a 24 horas, por si un cliente manipulado intentara
alterar el ranking.

---

## 11. Seguridad

### 11.1 Contraseñas

Se almacenan como *hash* **bcrypt** con coste 12, mediante `password_hash()`.
Bcrypt incorpora su propia sal aleatoria y es deliberadamente lento, lo que
encarece enormemente un ataque por diccionario. La contraseña en claro no se
guarda ni se registra en ningún punto.

La verificación usa `password_verify()`, que compara en tiempo constante.
Cuando el usuario no existe se ejecuta igualmente una verificación contra un
*hash* señuelo, para que el tiempo de respuesta no delate qué cuentas existen
(**enumeración de usuarios**).

El sistema también re-cifra de forma transparente si sube el coste
configurado:

```php
if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => BCRYPT_COSTE])) {
    // actualiza el hash con el nuevo coste
}
```

### 11.2 Inyección SQL

**Todas** las consultas usan sentencias preparadas con parámetros. Los valores
viajan separados del SQL, de modo que el motor nunca los interpreta como
código. No existe en el proyecto ninguna consulta construida por concatenación
de datos del usuario.

Se desactivó además la emulación de PDO:

```php
PDO::ATTR_EMULATE_PREPARES => false
```

Esto fuerza sentencias preparadas **reales del servidor MySQL** en lugar del
escapado que PDO hace en el cliente.

Los dos puntos donde no cabe un parámetro se resolvieron así:

- **`ORDER BY`** — el usuario elige una *clave* de una lista blanca; nunca
  escribe SQL:
  ```php
  $ordenes = ['recientes' => 'creado_en DESC', 'titulo' => 'titulo ASC', ...];
  $orderBy = $ordenes[$orden] ?? $ordenes['recientes'];
  ```
- **`LIMIT` / `OFFSET`** — se convierten con `(int)` y se acotan antes de
  interpolarse, de modo que solo pueden ser enteros dentro de rango.

### 11.3 XSS

Toda salida HTML pasa por `htmlspecialchars()` con `ENT_QUOTES`:

```php
public static function e(mixed $texto): string {
    return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

En el cliente existe una segunda barrera (`escapar()` en `api.js`) para todo
dato que se inyecte con `innerHTML`.

La configuración que el servidor pasa al reproductor viaja dentro de un bloque
`<script type="application/json">` codificado con `JSON_HEX_TAG`, que convierte
`<` y `>` en secuencias de escape: un título que contuviera `</script>` no
puede cerrar la etiqueta ni inyectar código.

Se emite además una **Content-Security-Policy** que restringe los orígenes
ejecutables. Incluye `'wasm-unsafe-eval'` porque sin ese permiso el navegador
bloquearía los núcleos WebAssembly del emulador.

### 11.4 CSRF

Cada sesión genera un token aleatorio de 32 bytes que se incrusta en los
formularios y en una etiqueta `<meta>`. El JavaScript lo adjunta
automáticamente a toda petición que no sea `GET`.

La comparación usa `hash_equals()` y no `===`, para que el tiempo de respuesta
no revele cuántos caracteres del token eran correctos (**ataque de
temporización**).

La cookie de sesión lleva además `SameSite=Lax`, que impide al navegador
enviarla en peticiones originadas desde otro sitio.

### 11.5 Sesiones

| Medida | Efecto |
|---|---|
| `HttpOnly` | La cookie es invisible para JavaScript; un XSS no puede robarla |
| `SameSite=Lax` | No viaja en peticiones de terceros |
| `Secure` (con HTTPS) | No viaja por HTTP |
| `use_strict_mode` | Rechaza identificadores de sesión inventados por el cliente |
| `use_only_cookies` | El identificador nunca aparece en la URL |
| Regeneración al entrar | Anula cualquier identificador fijado de antemano (*session fixation*) |
| Regeneración cada 15 min | Reduce la ventana útil de una cookie robada |
| Caducidad por inactividad | 8 horas |
| Directorio propio | Los archivos de sesión viven en `storage/sesiones`, fuera del *document root* |

El último punto merece explicación: en un *hosting* compartido, las sesiones de
todos los sitios acaban por omisión en la misma carpeta temporal del sistema,
legibles entre ellos.

> **Nota de diseño.** Se implementó y después se retiró la práctica de atar la
> sesión a una huella del cliente (IP + User-Agent). La sección 14 detalla por
> qué: resultaba expulsar a usuarios legítimos sin frenar a un atacante real.

### 11.6 Control de acceso

Tres niveles de guardián:

```php
Auth::exigirApi()          // 401 si no hay sesión
Auth::exigirAdminApi()     // 403 si no es administrador
Auth::exigirPagina()       // redirige al login conservando el destino
Auth::exigirAdminPagina()  // página 403
```

Las consultas de datos privados **filtran por `usuario_id` en el propio
`WHERE`**. Ese filtro *es* la autorización: pedir el identificador de otra
persona simplemente no devuelve fila. Así se cierra la clase de fallo conocida
como **IDOR** (*Insecure Direct Object Reference*).

La redirección tras el login valida el destino para impedir que
`?volver=https://sitio-malo.com` convierta el formulario en un trampolín hacia
otro dominio (**open redirect**).

### 11.7 Subida de archivos

| Control | Implementación |
|---|---|
| Código de error de PHP | Se comprueba antes que nada, con mensaje específico por caso |
| Origen del archivo | `is_uploaded_file()` confirma que viene del mecanismo de subida |
| Tamaño | Se mide en disco, no se confía en lo que declara el cliente |
| Extensión | Lista blanca por consola, tomada de la base de datos |
| Contenido de imágenes | `getimagesize()`; un PHP renombrado a `.png` se rechaza |
| Firma PNG | Las capturas se validan por sus 8 primeros bytes |
| Nombre | **Se descarta el del usuario** y se genera uno aleatorio |
| Ubicación | Fuera del *document root* |
| Duplicados | SHA-256, detección por contenido |

El descarte del nombre original elimina de raíz la posibilidad de colar rutas
como `../../index.php` o nombres como `shell.php`.

### 11.8 Path traversal

Toda ruta de archivo se resuelve con `realpath()` y se comprueba que siga
dentro del directorio base:

```php
$real     = realpath($base . DIRECTORY_SEPARATOR . basename($nombre));
$baseReal = realpath($base);
if ($real === false || !str_starts_with($real, $baseReal)) {
    return null;
}
```

### 11.9 Fuerza bruta

Cada intento de login se registra en `intentos_login`. Tras 5 fallos en 15
minutos se bloquea durante 15 minutos más. El recuento es **doble**:

- **por IP** — frena a quien ataca muchas cuentas
- **por cuenta** — frena a quien ataca una cuenta desde muchas IP

Deliberadamente **no** se confía en la cabecera `X-Forwarded-For`, porque
cualquiera puede falsificarla y burlar el limitador.

El mensaje de error es vago a propósito ("Usuario o contraseña incorrectos"):
no revela cuál de los dos falló.

### 11.10 Cabeceras de seguridad

| Cabecera | Función |
|---|---|
| `Content-Security-Policy` | Restringe los orígenes ejecutables |
| `X-Content-Type-Options: nosniff` | Impide que el navegador adivine el tipo MIME |
| `X-Frame-Options: SAMEORIGIN` | Bloquea el sitio dentro de un `<iframe>` ajeno (*clickjacking*) |
| `Referrer-Policy` | No filtra la URL completa a sitios externos |
| `Permissions-Policy` | Desactiva cámara, micrófono y geolocalización |
| `Strict-Transport-Security` | Con HTTPS, impide volver a HTTP |

Se emiten desde PHP (`bootstrap.php`) y se repiten en `.htaccess` para que
cubran también los archivos estáticos, que no pasan por PHP.

---

## 12. Servidor y despliegue

### 12.1 Entorno

| Componente | Versión |
|---|---|
| Servidor web | Apache 2.4 |
| PHP | 8.1 |
| Base de datos | MySQL 5.7 / MariaDB |
| Entorno integrado | MAMP |

### 12.2 Configuración de Apache

El archivo `public/.htaccess` establece:

- Desactivación del listado de directorios
- Bloqueo de archivos sensibles (`.sql`, `.log`, `.ini`, `.md`)
- Cabeceras de seguridad para archivos estáticos
- Tipos MIME, incluido `application/wasm` —sin él, el navegador rechaza
  compilar los núcleos en streaming y el emulador no arranca
- Compresión selectiva (se excluyen ROMs e imágenes, ya comprimidas)
- Políticas de caché: un año para los núcleos, un mes para los estáticos, nada
  para el HTML generado por PHP
- Revalidación obligatoria del Service Worker
- Límites de subida
- Bloque preparado para forzar HTTPS

### 12.3 HTTPS

Documentado en detalle en **[configuracion-ssl.md](configuracion-ssl.md)**,
incluyendo qué resuelve TLS, el *handshake* paso a paso, la generación del
certificado con OpenSSL, la configuración del *virtual host* y la transición a
Let's Encrypt en producción.

### 12.4 Aplicación web progresiva

El proyecto es instalable como aplicación mediante `manifest.webmanifest` y un
Service Worker.

La política de caché es deliberadamente selectiva:

| Se cachea | No se cachea |
|---|---|
| CSS, JavaScript, iconos, manifiesto | ROMs (pesadas y requieren sesión) |
| | Respuestas de la API (datos por usuario) |
| | HTML generado por PHP (contiene el token CSRF) |

Cachear una ROM dejaría una copia accesible a cualquiera que abriera el
navegador después; cachear HTML serviría tokens caducados.

### 12.5 Diagnóstico

`public/diagnostico.php` ejecuta más de treinta comprobaciones automáticas
sobre el entorno: versión de PHP, extensiones, conexión a MySQL, presencia de
tablas, vistas y procedimientos, permisos de escritura, ubicación de `storage/`
respecto al *document root*, límites de subida y estado de HTTPS.

La propia página advierte que debe eliminarse antes de publicar.

---

## 13. Pruebas realizadas

### 13.1 Verificación estática

| Prueba | Resultado |
|---|---|
| Sintaxis de PHP (`php -l`) sobre 29 archivos | 0 errores |
| Sintaxis de JavaScript (`node --check`) sobre 9 archivos | 0 errores |
| Validez del manifiesto JSON | Correcto |
| Marcadores SQL repetidos (76 consultas analizadas) | 0 incidencias |

### 13.2 Base de datos

| Prueba | Resultado |
|---|---|
| Importación de `schema.sql` | 10 tablas y 3 vistas creadas |
| Importación de `seed.sql` | 2 usuarios, 12 consolas, 12 géneros, 9 logros |
| Claves foráneas registradas | 11 |
| Procedimiento `sp_cerrar_sesion` | Registrado y funcional |
| Disparador con ranura inválida (`slot = 9`) | Rechazado: `ERROR 1644 · Slot invalido` |

### 13.3 Autenticación

| Prueba | Resultado esperado | Resultado |
|---|---|---|
| Login con credenciales válidas | Sesión abierta | Correcto |
| Persistencia entre peticiones `fetch` | Sesión conservada | Correcto |
| Persistencia en navegación completa | Sesión conservada | Correcto |
| Petición sin token CSRF | 419 | Correcto |

### 13.4 Subida de ROMs

Se generó una ROM de prueba sintética (cabecera iNES válida, 24 592 bytes,
SHA-256 `8a4e303b…`).

| Prueba | Resultado |
|---|---|
| Subida con consola correcta | Creada; hash calculado coincide con el original |
| Subida del mismo archivo otra vez | Rechazada: *"Esa ROM ya está en el catálogo"* |
| Subida de `.nes` declarando consola SNES | Rechazada: *"Extensión .nes no válida… Se aceptan: sfc, smc, swc, fig, zip"* |
| Nombre interno asignado | Aleatorio; el original se descarta |
| Integridad en disco | SHA-256 idéntico al de origen |

### 13.5 Entrega controlada de ROMs

| Prueba | Resultado esperado | Resultado |
|---|---|---|
| `api/rom.php?id=1` sin sesión | 401 | **401** |
| `api/rom.php?id=1` con sesión | 200 y archivo íntegro | **200**, SHA-256 idéntico |
| Acceso directo a `/storage/roms/…` | Inaccesible | **404** |
| Acceso con `../` en la ruta | Inaccesible | **404** |

### 13.6 Sincronización de partidas (prueba de extremo a extremo)

Se simuló un volcado de 65 536 bytes.

| Paso | Resultado |
|---|---|
| Iniciar sesión de juego | `sesion_id` devuelto |
| Guardar en la ranura 2 | 64,0 KB registrados, hash `2eea186e948a` |
| Listar ranuras | Ranura 2 ocupada, el resto vacías |
| Descargar la partida | **Byte a byte idéntica al original** |
| Cerrar sesión de juego (procedimiento almacenado) | Tiempo registrado |

### 13.7 Pruebas de seguridad

| Ataque simulado | Resultado esperado | Resultado |
|---|---|---|
| Descargar la partida de otro usuario (IDOR) | Denegado | **404** |
| Borrar la partida de otro usuario | Denegado | *"no es tuya"* |
| Jugador accediendo al panel de administración | Denegado | **403** |
| Jugador listando usuarios | Denegado | **403** |
| Petición sin token CSRF | Denegado | **419** |
| Inyección `' OR 1=1 --` en la búsqueda | Tratado como texto | 0 resultados |
| Inyección `; DROP TABLE juegos;--` en el orden | Ignorado | Tablas intactas |

### 13.8 Borrado en cascada

| Prueba | Resultado |
|---|---|
| Eliminar un juego con partidas asociadas | Fila, ROM y partidas eliminadas |
| Archivos huérfanos en `storage/` | Ninguno |

### 13.9 Interfaz

| Prueba | Resultado |
|---|---|
| Carga del catálogo | Datos obtenidos de MySQL vía API |
| Errores en consola del navegador | Ninguno |
| Filtros de consola y género | Poblados desde la base de datos |
| Menú hamburguesa bajo 860 px | Correcto |
| Tema claro/oscuro con persistencia | Correcto |

### 13.10 Limitaciones de la validación

Por transparencia conviene dejar constancia de lo que **no** se verificó:

- La **ejecución de un juego real** no se probó de extremo a extremo, porque el
  catálogo se entrega vacío por decisión de licencias. Se verificó toda la
  cadena que rodea al emulador —entrega de la ROM, API de partidas, integridad
  binaria—, pero la carga efectiva de un núcleo con un título jugable queda
  sujeta a la primera ROM que se cargue en el panel.
- El soporte de **mandos físicos** y los **controles táctiles** provienen de
  EmulatorJS y de APIs estándar del navegador; no se dispuso de hardware para
  comprobarlos en esta fase.
- El rendimiento se evaluó solo en entorno local, con un único usuario.

---

## 14. Problemas encontrados y soluciones

Se documentan los treinta y cinco fallos reales detectados durante el
desarrollo, por su valor formativo. Cada uno sigue la misma estructura:
**síntoma** (lo que se observó), **diagnóstico** (lo que se midió, cuando hizo
falta medir), **causa** (por qué ocurría de verdad) y **solución**.

No se incluyen erratas ni despistes de tecleo: solo los problemas cuya causa no
era evidente y cuya resolución enseñó algo. Varios de ellos —14.22, 14.25— se
resolvieron leyendo el código fuente del motor de emulación, porque el
comportamiento no está documentado en ninguna parte. La sección se cierra con
las lecciones transversales que se repiten a lo largo de todos ellos.

### 14.1 Marcadores nombrados repetidos en PDO

**Síntoma.** El login fallaba con `SQLSTATE[HY093]: Invalid parameter number`.

**Causa.** La consulta reutilizaba el mismo marcador:

```sql
WHERE nombre_usuario = :ident OR correo = :ident
```

Con `ATTR_EMULATE_PREPARES` desactivado, PDO traduce los nombres a posiciones
y MySQL recibe dos marcadores donde solo se envió un valor.

**Solución.** Un marcador distinto por ocurrencia. Se revisaron después las 76
consultas del proyecto con un script que detecta el patrón; no quedaban más
casos.

### 14.2 Las sesiones no persistían

**Síntoma.** El login devolvía éxito pero la siguiente petición llegaba sin
sesión.

**Causa.** `session.save_path` estaba vacío en la configuración de PHP. Al no
haber ruta definida, PHP intentaba escribir en `/tmp`, que no existe en
Windows. `session_start()` fallaba en silencio.

**Solución.** La aplicación define su propio directorio de sesiones dentro de
`storage/`, con reserva a la carpeta temporal del sistema. Esto la vuelve
independiente de la configuración del *hosting* y aporta aislamiento.

### 14.3 Huella de cliente demasiado frágil

**Síntoma.** Tras corregir lo anterior, la sesión seguía perdiéndose, pero solo
al navegar entre páginas; las peticiones `fetch` la conservaban.

**Diagnóstico.** Se instrumentó el servidor para comparar la huella guardada
con la calculada en cada petición:

```
huella_sesion:   e6dbc79711296736   (calculada durante un fetch)
huella_actual:   58ea08bf0a63f1a1   (calculada durante una navegación)
huellas_iguales: false
```

**Causa.** La sesión se ataba a un *hash* del User-Agent. Con la reducción de
User-Agent y los *client hints* de los navegadores actuales, **la cadena que
llega en una navegación de página difiere de la que llega en una petición
`fetch`**. Antes se había descartado `Accept-Language` por el mismo motivo.

**Solución.** Se retiró la huella por completo. El análisis es que esta
práctica, muy difundida, expulsaba a usuarios legítimos sin frenar a un
atacante real: quien controla la cookie puede copiar esas cabeceras con la
misma facilidad. Las defensas efectivas —`HttpOnly`, `SameSite`, regeneración
del identificador, caducidad y HTTPS— permanecen activas. La decisión quedó
documentada en el propio código.

### 14.4 `setcookie()` con la salida ya enviada

**Síntoma.** La página se cortaba a media cabecera, sin mensaje de error.

**Causa.** Al detectar una sesión inválida mientras se renderizaba la
cabecera, `cerrarSesion()` llamaba a `setcookie()` con el HTML ya enviado. El
*warning* resultante se convertía en excepción por el manejador de errores
estricto del proyecto, abortando el renderizado.

**Solución.** Guardar la llamada tras `headers_sent()`. Destruir la sesión en
el servidor es suficiente: la cookie que quede en el navegador ya no apunta a
ningún dato.

### 14.5 Rutas relativas rotas en el panel

**Síntoma.** Las páginas de `/admin` pedían `/admin/api/juegos.php`.

**Solución.** El servidor expone el prefijo correcto en
`window.RETROCLOUD_BASE` y la capa de comunicación lo antepone a toda ruta
relativa.

### 14.6 Configuración incorrecta en MAMP

Se detectó que el `php.ini` de PHP 8.1 de la instalación tenía
`extension_dir` apuntando al directorio de **PHP 5.5**, lo que impedía cargar
`mysqli`, `pdo_mysql`, `mbstring`, `gd` y `openssl`. Es un fallo del entorno,
no del proyecto, pero conviene corregirlo:

```ini
extension_dir = "C:\MAMP\bin\php\php8.1.0\ext\"
```

### 14.7 La rotación del identificador de sesión expulsaba usuarios

**Síntoma.** De forma intermitente, y sobre todo al cargar una página con
varias peticiones simultáneas, aparecía «tu sesión expiró» sin haber tocado
nada.

**Causa.** La rotación periódica usaba `session_regenerate_id(true)`, que
**borra el archivo de sesión anterior de inmediato**. Apache atiende varias
peticiones en paralelo: mientras una rotaba el identificador, las otras —ya en
vuelo con la cookie vieja— encontraban su archivo eliminado y se quedaban sin
sesión.

**Solución.** Pasar a `session_regenerate_id(false)`. Lo que frustra el ataque
de fijación es que el identificador **cambie**, no que el archivo viejo
desaparezca al instante; además, ese archivo ya no contiene credenciales
útiles. El fallo no se reproducía con el servidor integrado de PHP, que es
monohilo y atiende las peticiones de una en una.

### 14.8 La sesión ignoraba los cambios hechos en la base de datos

**Síntoma.** El asistente del catálogo saludó por su nombre a una cuenta de
pruebas **borrada media hora antes**.

**Causa.** `autenticado()`, `rol()` y `esAdmin()` leían exclusivamente del
array de sesión, escrito una sola vez al iniciar sesión. Mientras esa sesión
viviera, los cambios en la base de datos no surtían ningún efecto:

| Acción del administrador | Efecto real |
|---|---|
| Borrar un usuario | Seguía dentro |
| Desactivar un usuario | Conservaba el acceso completo |
| **Quitar el rol de administrador** | **Seguía siendo administrador** |

El tercer caso es el grave: el panel podía degradar a alguien y esa persona
conservaba sus permisos hasta que cerrara sesión por su cuenta. Revocar un
permiso debe surtir efecto aunque el afectado no colabore.

**Solución.** `Auth::revalidar()` confronta la sesión con la base de datos cada
60 segundos y refresca el rol. Dos decisiones deliberadas:

- **No se comprueba en cada petición**, porque añadiría una consulta a cada
  carga, incluidas las de la API durante una partida. Con un minuto, la ventana
  máxima de un permiso revocado es de 60 segundos.
- **No se cierra sesión si la base de datos falla.** Un corte de MySQL
  expulsaría a todos a la vez y, al volver, nadie podría entrar porque el
  *login* también necesita la base. El fallo de disponibilidad sería peor que
  la ventana de riesgo.

### 14.9 Cerrar la sesión a media petición dejaba el token CSRF sin dónde vivir

**Síntoma.** Tras corregir lo anterior, el asistente saludaba correctamente
pero el primer mensaje fallaba con `419 token inválido`. Todo `POST` posterior
hacía lo mismo.

**Causa.** La revalidación llamaba a `cerrarSesion()`, que destruye la sesión y
borra la cookie. Eso es correcto cuando el usuario pulsa «Salir» y lo siguiente
es una respuesta JSON, pero aquí ocurría durante el arranque y **la página
seguía renderizándose**: `Csrf::meta()` generaba entonces un token que ya no
tenía dónde guardarse, porque la sesión estaba destruida.

**Solución.** Degradar a visitante en lugar de destruir: vaciar el array y
rotar el identificador deja una sesión viva y anónima, con la que la persona
puede seguir navegando y los formularios siguen funcionando. Aquí sí se pide
borrar el archivo anterior, porque ocurre una sola vez y lo que se busca es
precisamente que la sesión previa deje de servir.

### 14.10 La política de seguridad de contenido bloqueaba el motor de emulación

**Síntoma.** El reproductor mostraba «no se pudo cargar el motor» y la consola
registraba violaciones de CSP.

**Causa.** La CSP declaraba `default-src 'self'`, pero los núcleos de
emulación, sus *workers* y sus recursos se descargan de `cdn.emulatorjs.org`.

**Solución.** Añadir ese origen a las directivas que lo necesitan
(`script-src`, `worker-src`, `child-src`, `connect-src`, `img-src`,
`media-src`, `font-src`, `style-src`), y **solo a esas**. El origen se compone
desde una constante de configuración, de modo que al servir el emulador en
local la CSP se cierra sola.

### 14.11 WebAssembly necesita `'unsafe-eval'`

**Síntoma.** Resuelto lo anterior, el núcleo seguía sin arrancar.

**Causa.** El código que Emscripten genera para cargar WebAssembly construye
funciones a partir de cadenas de texto, algo que `'unsafe-eval'` prohíbe.

**Solución.** Conceder `'unsafe-eval'` **únicamente en la página del
reproductor**, mediante una constante que esa página define antes de incluir
la cabecera. El resto del sitio —catálogo, panel de administración,
formularios de sesión, que son las superficies realmente atacables— conserva
la política estricta. Es un ejemplo de principio de mínimo privilegio aplicado
a la CSP: no se relaja la política global para resolver un caso concreto.

### 14.12 Un atributo `hidden` que no ocultaba nada

**Síntoma.** La capa de carga del emulador no desaparecía nunca y, al quedar
encima, interceptaba todos los clics del juego.

**Causa.** El atributo `hidden` de HTML equivale a `display: none`, pero
cualquier regla CSS con `display` lo sobrescribe. La capa tenía
`display: grid` para centrar su contenido, y esa regla ganaba.

**Solución.** Una regla global `[hidden] { display: none !important }`. Es uno
de los poquísimos usos justificados de `!important`: restituye el
comportamiento que el navegador ya debería garantizar.

### 14.13 Un selector demasiado amplio deformaba la barra del emulador

**Síntoma.** La barra de controles del emulador aparecía estirada hasta ocupar
media pantalla.

**Diagnóstico.** Se midió el elemento: **677 px de alto** donde debía medir
menos de 60.

**Causa.** Una regla `#juego > div { height: 100% }` pensada para el lienzo
alcanzaba también a `.ejs_menu_bar`, que es hermana suya.

**Solución.** Acotar el selector a `.ejs_canvas_parent`. Tras el cambio la
barra midió **57 px**.

### 14.14 El panel de administración desbordaba en ventanas estrechas

**Síntoma.** Al reducir la ventana, el menú lateral se montaba sobre la tabla
y aparecía barra de desplazamiento horizontal.

**Diagnóstico.** Se midió el contenido: **957 px de ancho dentro de una
ventana de 774 px**.

**Causa.** Dos problemas a la vez. El menú llevaba `position: sticky` también
en móvil, donde no hay columna lateral; y los elementos de una rejilla CSS
tienen `min-width: auto` por omisión, así que una tabla ancha empuja su
columna en lugar de recortarse.

**Solución.** Mover `sticky` dentro de `@media (min-width: 980px)` y declarar
`.admin > * { min-width: 0 }`. El desbordamiento pasó a **0 px**.

### 14.15 Una caché de un mes impedía ver los cambios

**Síntoma.** Correcciones de CSS y JavaScript que no aparecían en el navegador,
repetidamente, durante días.

**Causa.** El `.htaccess` declaraba `Cache-Control: max-age=2592000` (30 días)
para los recursos estáticos. El navegador no volvía a pedirlos.

**Solución.** Tres medidas combinadas:

1. Un ayudante `recurso()` que añade a cada URL la fecha de modificación del
   archivo (`?v=` más `filemtime` en hexadecimal). Al cambiar el archivo cambia
   la URL, y el navegador la trata como un recurso nuevo.
2. `Cache-Control: no-cache, must-revalidate` para `css`, `js` y `mjs`.
3. El *service worker* pasó a estrategia **red primero** para el código, y
   mantiene **caché primero** solo para recursos que no cambian.

La lección: una caché larga es correcta, pero exige una forma de invalidarla.

### 14.16 `100vh` no es la altura visible en el móvil

**Síntoma.** En el teléfono, la parte inferior del juego quedaba cortada bajo
la barra del navegador.

**Causa.** `100vh` mide la ventana **sin** las barras del navegador, que en
iOS y Android aparecen y desaparecen al desplazarse.

**Solución.** Usar `100dvh` (*dynamic viewport height*), que se ajusta a la
altura realmente visible, dentro de una consulta `(hover: none)` para aplicarlo
solo en dispositivos táctiles.

### 14.17 El juego salía estirado en pantalla completa

**Síntoma.** En pantalla completa la imagen se veía deformada, más ancha de lo
que debía.

**Diagnóstico.** Se midieron el lienzo y su contenido:

```
lienzo HTML :  907 × 679 px   (relación 1.34)
dibujo real :  774 × 646 px   (relación 1.20)
```

**Causa.** El lienzo se estiraba para llenar el contenedor, y el contenido se
deformaba con él.

**Solución.** `object-fit: contain` sobre el lienzo, más
`image-rendering: pixelated` para que el escalado no difumine el pixel art.
Tras el cambio el dibujo midió **774 × 580 px**, relación **1.33**, que es la
correcta.

### 14.18 El fondo animado parpadeaba una vez y se congelaba

**Síntoma.** El fondo decorativo del reproductor se animaba un instante y se
quedaba quieto.

**Causa.** La animación modificaba `backdrop-filter`, propiedad que el
navegador no puede animar en la tarjeta gráfica: cada fotograma obliga a
recalcular el desenfoque de todo lo que hay detrás.

**Solución.** Sustituirla por manchas con gradiente radial animadas con
`transform`, que sí se resuelven en la tarjeta gráfica. Regla general: animar
solo `transform` y `opacity`.

### 14.19 El texto del botón de inicio salía desplazado

**Síntoma.** La palabra del botón de arranque aparecía 16 px por debajo de su
centro.

**Causa.** El estilo original del emulador centraba el texto con
`line-height: 45px`, contando con un botón de esa altura exacta. Al añadir
`padding: 16px 38px` el botón creció, pero la altura de línea siguió fija.

**Solución.** Centrar con `display: flex` y `line-height: 1`, que no depende de
la altura del contenedor.

### 14.20 El `php.ini` que se editaba no era el que mandaba

**Síntoma.** Los límites de subida no cambiaban por mucho que se editara la
configuración.

**Diagnóstico.** Dos descubrimientos encadenados. Primero, Apache cargaba
**PHP 8.0.1** mientras se editaba el `php.ini` de **PHP 8.1.0**. Y después,
corregido eso, los límites seguían sin moverse.

**Causa real.** El propio `.htaccess` del proyecto fijaba los valores:

```apache
php_value upload_max_filesize 128M
php_value post_max_size       130M
```

Cuando PHP corre como módulo de Apache, las directivas `php_value` del
`.htaccess` **tienen prioridad sobre el `php.ini`**.

**Solución.** Subir los límites en el `.htaccess`, que es donde mandan, y
declararlos tanto para `mod_php` como para `mod_php7` para no depender de la
versión instalada. Lección: antes de editar configuración, comprobar **qué
configuración se está aplicando de verdad**.

### 14.21 Un formulario «incompleto» que estaba completo

**Síntoma.** Al subir una ROM de PlayStation de 170 MB, el servidor respondía
«El campo título es obligatorio», con el campo claramente relleno.

**Causa.** Cuando el cuerpo de una petición supera `post_max_size`, PHP lo
**descarta entero antes de que el script arranque**: `$_POST` y `$_FILES`
llegan vacíos y no queda ni un código de error al que agarrarse. El validador,
al no encontrar el título, daba el mensaje que le correspondía. El mensaje era
correcto y engañoso a la vez.

**Solución.** Un guardián en el arranque que detecta la situación por sus tres
señales simultáneas: petición `multipart`, `CONTENT_LENGTH` mayor que cero y
`$_POST`/`$_FILES` vacíos. En ese caso responde **413** con un mensaje que
explica lo que pasó y cuál es el límite real. Verificado después con una subida
de **178 258 521 bytes**, que llegó completa.

### 14.22 Neo Geo: tres fallos encadenados

El soporte de Neo Geo descubrió tres problemas distintos, uno detrás de otro.
Es el caso más instructivo del proyecto, porque cada corrección destapaba el
siguiente fallo.

**(a) «Romset is unknown».** El emulador rechazaba el juego. Los núcleos de
arcade identifican el conjunto de ROMs **por el nombre del archivo ZIP**, no
por su contenido, y el proyecto servía las ROMs con un nombre derivado del
título. Se añadió la columna `archivo_original` para conservar el nombre con el
que se subió el archivo y servirlo con él.

**(b) El BIOS se descomprimía solo.** Resuelto lo anterior, el emulador seguía
fallando. Leyendo el código del motor se encontró que, por omisión, extrae el
archivo de BIOS; pero el núcleo de arcade **necesita el `neogeo.zip` intacto**.
Se activó `EJS_dontExtractBIOS` para los núcleos de esa familia.

**(c) Una URL usada como ruta de archivo.** El arranque se quedaba congelado al
100 % con un `ErrnoError`. El motor escribe el BIOS en su sistema de archivos
virtual con `FS.writeFile(url, datos)`, es decir, **usa la URL completa como
nombre de archivo**. Con barras dentro, la ruta no existía. Se pasó a servir el
BIOS con un nombre plano (`neogeo.zip`) mediante una reescritura en
`.htaccess`.

**Verificación.** Con los tres corregidos, el juego arrancó. Se comprobó que
estaba ejecutándose de verdad midiendo su memoria: un estado de **414 144
bytes**, con **267 bytes cambiando cada 2,5 segundos**.

### 14.23 Una regresión propia dejó PlayStation sin funcionar

**Síntoma.** Un juego de PlayStation que funcionaba perfectamente dejó de
arrancar tras el trabajo de Neo Geo.

**Causa.** Al generalizar el soporte de BIOS se marcó la consola PlayStation
con `bios_requerido = 1`. Pero el núcleo `pcsx_rearmed` **emula el BIOS por
software** y no necesita el archivo original; al exigirlo, el juego dejaba de
cargar.

**Solución.** Devolver la bandera a 0 para esa consola. Caso de libro de
regresión: una función nueva rompió otra que ya funcionaba, y solo se detectó
porque se volvió a probar lo anterior.

### 14.24 Un BIOS subido a la consola equivocada

**Síntoma.** Un segundo juego de Neo Geo fallaba pese a existir ya el BIOS.

**Causa.** El juego se había dado de alta en «Arcade (FBNeo)» y el BIOS en «Neo
Geo». Son entradas distintas en la base de datos, aunque compartan núcleo de
emulación.

**Solución.** Si la consola del juego no tiene BIOS propio, se busca uno
registrado en **otra consola que use el mismo núcleo**. Corrige el error humano
sin obligar a subir el archivo dos veces.

### 14.25 El teléfono se reiniciaba a mitad de partida

**Síntoma.** En el móvil, la descarga terminaba pero el juego no arrancaba; y
si arrancaba, al rato la página se recargaba sola.

**Causa.** `EJS_cacheLimit` vale por omisión **1 073 741 824 bytes** (1 GB).
Con ese tope, el motor guarda una copia de cada ROM en IndexedDB **además** de
la que tiene en memoria. Con discos de PlayStation de varios cientos de
megabytes, el navegador móvil agotaba la memoria y mataba la pestaña.

**Solución.** Bajar el tope a 32 MB. Los cartuchos pequeños (NES, SNES, Game
Boy, GBA) se siguen cacheando y cargan al instante; los pesados se descargan
cada vez pero no duplican memoria.

> **Nota para un despliegue remoto.** Este compromiso es correcto sirviendo las
> ROMs desde la misma máquina, donde «descargar» es leer del disco local. Si
> las ROMs pasaran a servirse desde un almacenamiento externo, los títulos
> grandes se volverían a transferir **en cada partida**. Convendría entonces
> elevar el tope en escritorio y mantenerlo bajo en móvil.

### 14.26 Dos instalaciones de MAMP y un PHP inesperado

**Síntoma.** El sitio dejó de servirse por completo, con error 500 en todas las
páginas.

**Diagnóstico.** Apache estaba cargando **PHP 7.4.16**, mientras que el
proyecto exige PHP 8 (usa `match`, `str_contains` y tipos de unión).

**Causa.** Había **dos MAMP instalados**: la versión gratuita y MAMP PRO. El
servicio de MAMP PRO llevaba semanas ocupando los puertos con su propia
configuración, y además apuntaba a un directorio de datos de MySQL distinto,
sin la base del proyecto.

**Solución.** Detener MAMP PRO y arrancar la versión que tiene la configuración
del proyecto. Lección operativa: cuando el entorno falla de forma inexplicable,
verificar **qué proceso está escuchando realmente** antes de tocar nada.

### 14.27 Bucle infinito de redirecciones tras un proxy inverso

**Síntoma.** Al desplegar en el *hosting* gratuito, el sitio quedó inaccesible:
el navegador informaba de demasiadas redirecciones.

**Causa.** `bootstrap.php` decidía si la petición venía por HTTPS mirando solo
`$_SERVER['HTTPS']`. En un *hosting* compartido ese valor llega **siempre
vacío**, porque el cifrado lo termina un proxy delante de PHP. Con
`FORZAR_HTTPS` activo, PHP creía que cada petición era insegura y redirigía a
HTTPS indefinidamente. El mismo error causaba otros dos daños silenciosos: la
cabecera HSTS no se emitía nunca y la cookie de sesión no se marcaba `Secure`.

**Solución.** Normalizar `$_SERVER['HTTPS']` a partir de `X-Forwarded-Proto`,
**solo cuando `CONFIAR_PROXY` está activo**, porque un cliente puede falsificar
esa cabecera si no hay un proxy delante que la filtre.

**Verificación.** Con el sitio servido tras un proxy simulado: sin la cabecera
responde 301 hacia HTTPS, y con ella responde 200 —antes era el bucle— emitiendo
HSTS y la cookie con `Secure`.

### 14.28 El esquema no se podía importar en *hosting* compartido

**Síntoma.** `schema.sql` fallaba con «Access denied» al importarlo.

**Causa.** El archivo empieza con `CREATE DATABASE` y `USE retrocloud`. En un
*hosting* compartido el nombre de la base lo asigna el panel y el usuario no
tiene permiso para crear bases.

**Solución.** Versiones `schema-hosting.sql` y `seed-hosting.sql` sin esas
sentencias. El *seed* de *hosting* además regenera las contraseñas de las
cuentas de prueba: las del *seed* original están documentadas en texto claro en
un repositorio público, de modo que desplegarlo tal cual dejaba la cuenta de
administrador abierta a cualquiera que encontrara el sitio.

### 14.29 Las carpetas necesarias no llegaban al clon

**Síntoma.** Un clon recién hecho del repositorio no arrancaba.

**Causa.** El `.gitignore` ignora `logs/` y `storage/roms/`, y después intenta
conservar sus `.gitkeep` con `!`. Pero **git no rescata archivos que están
dentro de una carpeta ya ignorada**: ninguno de los siete `.gitkeep` llegó a
versionarse, así que las carpetas no existían al clonar.

**Solución.** Un guion de preparación (`deploy/preparar-servidor.sh`) que crea
las carpetas, protege `logs/` con su propio `.htaccess` y genera
`src/config.php` desde la plantilla.

### 14.30 `php_flag` devuelve error 500 con PHP-FPM

**Síntoma.** Error 500 en todo el sitio tras subir los archivos al *hosting*.

**Causa.** `storage/.htaccess` usaba `php_flag` para desactivar la ejecución de
PHP en esa carpeta. Esa directiva **solo existe cuando PHP corre como módulo de
Apache**; con PHP-FPM o CGI, que es lo habitual en *hosting* compartido, Apache
no la reconoce y aborta.

**Solución.** Envolverla en `<IfModule mod_php.c>`. La protección real de esa
carpeta no depende de ella: el contenido vive fuera del *document root* y se
sirve siempre a través de un script que valida la sesión.

### 14.31 El emulador didáctico se colgaba al ir paso a paso

**Síntoma.** En el laboratorio de CHIP-8, el programa de la pelota no avanzaba
del primer fotograma al recorrerlo con el botón «Paso».

**Causa.** Los temporizadores de CHIP-8 solo se decrementaban mientras el
emulador estaba en marcha. El programa espera al temporizador dando vueltas a
un bucle de tres instrucciones, de modo que paso a paso se quedaba ahí para
siempre.

**Solución.** En el hardware real los temporizadores cuentan por reloj de pared
aunque el procesador esté detenido. Ahora bajan en cada fotograma, con
independencia de si la ejecución está activa.

**Verificación.** Tras el cambio, el programa completó **2 823 instrucciones en
4 segundos**, invirtiendo el sentido en los cuatro bordes.

### 14.32 El panel de código secuestraba el desplazamiento de la página

**Síntoma.** Con el emulador didáctico en marcha era imposible leer nada por
encima del panel de desensamblado: la página se devolvía sola hacia abajo.

**Causa.** El seguimiento de la línea activa usaba `scrollIntoView()`, que
**desplaza todos los contenedores hasta la ventana**, no solo el panel. Con el
emulador corriendo, la página se reposicionaba 60 veces por segundo. Además, el
panel se reconstruía entero en cada fotograma —34 filas mediante `innerHTML`—,
y reemplazar el HTML destruye la posición de desplazamiento.

**Solución.** Calcular el desplazamiento a mano sobre el `scrollTop` del panel,
que no toca la página; construir la lista una sola vez al cargar el programa y
después solo mover la clase del resaltado; y forzar `scroll-behavior: auto` en
ese panel, que heredaba el desplazamiento suave de la página y convertía cada
ajuste en una animación que la siguiente interrumpía.

**Verificación.** Comparando las dos situaciones con el mismo salto: con el
emulador **detenido** la página derivaba de 610 a 684 px (era la animación de
`scroll-behavior: smooth` frenando, no el emulador); **en marcha** a 700
instrucciones por segundo, ocho muestras consecutivas durante 2,4 s dieron
exactamente **600 px**.

### 14.33 Un recomendador sin nada que recomendar

**Síntoma.** Con los datos reales, la sección «Para ti» no aparecía para el
usuario principal.

**Causa.** Ese usuario ya había jugado **los 15 juegos publicados**. El motor
descarta lo ya jugado, así que no quedaba ningún candidato y devolvía una lista
vacía.

**Solución.** Es el problema del **agotamiento del catálogo**, en el extremo
opuesto al arranque en frío. La respuesta no es una lista vacía sino cambiar de
pregunta: ya no «qué probar» sino «qué retomar». La nota mezcla cuánto gustó
cada juego con cuánto hace que no se abre, con olvido logarítmico por la misma
razón que el tiempo jugado: la diferencia entre 1 y 7 días importa, la que hay
entre 60 y 90 ya no.

### 14.34 Un porcentaje de afinidad que no era una afinidad

**Síntoma.** Sin sesión iniciada, las seis tarjetas recomendadas mostraban
todas **100 %**.

**Causa.** En la estrategia de popularidad la nota es `log(1 + veces_jugado)`,
que no está en la escala 0-1 y quedaba topada en 1 al formatearla.

**Solución.** No enviar el valor en ese modo y no pintar la insignia. Mostrar
una cifra que no significa nada es exactamente lo que critica el panel de
transparencia del propio sistema. Verificado: **0 insignias** sin sesión, y
92 / 80 / 55 / 51 % con historial.

### 14.35 Claves numéricas convertidas en enteros

**Síntoma.** El asistente del catálogo abortaba con un `TypeError` al escribir
cualquier frase.

**Causa.** La tabla de décadas usaba claves como `'80'` y `'2000'`. **PHP
convierte en enteros las claves de array que parecen números**, así que
`str_contains()` recibía un `int` donde esperaba una cadena.

**Solución.** Convertir explícitamente a cadena al recorrer la tabla. Es una
peculiaridad del lenguaje que no avisa hasta que algo la toca.

---

### Lecciones transversales

Repasando los treinta y cinco casos anteriores, se repiten cinco patrones que
merecen enunciarse por separado:

1. **Medir antes de corregir.** Los fallos de interfaz (14.13, 14.14, 14.17) se
   resolvieron en minutos una vez medidos, después de intentos infructuosos a
   ciegas. Un número —677 px, 957 px, relación 1.34 frente a 1.20— convierte una
   impresión en un diagnóstico.

2. **El mensaje de error correcto puede ser engañoso.** En 14.21 el validador
   decía la verdad: el campo faltaba. Lo que no podía saber era *por qué*. La
   corrección no fue cambiar el mensaje sino detectar la causa real un nivel
   más arriba.

3. **Verificar qué se está ejecutando de verdad.** En 14.20 se editó la
   configuración de una versión de PHP distinta de la activa; en 14.26 había dos
   servidores instalados. Ambos costaron horas de buscar en el sitio
   equivocado.

4. **Toda corrección es una regresión en potencia.** El caso 14.23 lo muestra
   sin adornos: generalizar el soporte de BIOS rompió una consola que
   funcionaba, y solo se detectó por volver a probar lo anterior.

5. **Leer la fuente cuando la documentación no alcanza.** Los tres fallos de
   Neo Geo (14.22) y el de la caché en móvil (14.25) se resolvieron leyendo el
   código del motor de emulación. `dontExtractBIOS`, `FS.writeFile(t, …)` y el
   valor por omisión `1073741824` no están documentados en ninguna parte.


---

## 15. Conclusiones

El proyecto cumple los objetivos planteados e integra las tres capas exigidas
en una aplicación funcional.

**Sobre la elección del problema.** Plantear la sincronización de partidas en
lugar de un CRUD convencional obligó a resolver cuestiones que no aparecen en
un formulario de altas y bajas: transporte de datos binarios, verificación de
integridad, entrega controlada de archivos, versionado por ranuras y
telemetría resistente al cierre abrupto del navegador. El emulador es el gancho
visible; la ingeniería está en la capa que lo rodea.

**Sobre la separación cliente/servidor.** Situar la emulación en el cliente
mantuvo el servidor en su papel natural. Un servidor que no emula puede
dedicarse a lo que hace bien: autenticar, autorizar, validar y persistir.

**Sobre la seguridad.** Tratarla como requisito desde el primer archivo y no
como un añadido final tuvo consecuencias de diseño concretas: la estructura de
directorios, el filtro por `usuario_id` dentro del `WHERE` y el descarte de
los nombres de archivo originales son decisiones arquitectónicas, no parches.

**Sobre el valor de los errores.** Los cuatro fallos de la sección 14 fueron
más instructivos que las partes que funcionaron a la primera. El de la huella
de cliente resultó especialmente revelador: una práctica recomendada en
numerosos tutoriales resultó ser, al medirla, perjudicial para los usuarios
legítimos y prácticamente inútil frente a un atacante. La lección es que una
medida de seguridad sin verificación empírica puede ser peor que su ausencia,
porque genera una confianza que no está justificada.

### Líneas de trabajo futuras

- Partida a dos jugadores en red mediante WebRTC
- Historial de versiones por ranura, con posibilidad de retroceder
- Importación y exportación masiva del catálogo
- Pruebas automatizadas de la API
- Migración de las sesiones a Redis para despliegues con varios servidores

---

## 16. Referencias

**Documentación técnica**

- PHP Manual — [php.net/manual/es](https://www.php.net/manual/es/)
- PDO y sentencias preparadas — [php.net/manual/es/pdo.prepared-statements.php](https://www.php.net/manual/es/pdo.prepared-statements.php)
- MDN Web Docs — [developer.mozilla.org/es](https://developer.mozilla.org/es/)
- Gamepad API — [developer.mozilla.org/en-US/docs/Web/API/Gamepad_API](https://developer.mozilla.org/en-US/docs/Web/API/Gamepad_API)
- WebAssembly — [webassembly.org](https://webassembly.org/)
- MySQL Reference Manual — [dev.mysql.com/doc](https://dev.mysql.com/doc/)

**Seguridad**

- OWASP Top Ten — [owasp.org/www-project-top-ten](https://owasp.org/www-project-top-ten/)
- OWASP Cheat Sheet Series — [cheatsheetseries.owasp.org](https://cheatsheetseries.owasp.org/)
- OWASP Session Management Cheat Sheet
- OWASP File Upload Cheat Sheet

**Emulación**

- EmulatorJS — [emulatorjs.org](https://emulatorjs.org/) · [github.com/EmulatorJS/EmulatorJS](https://github.com/EmulatorJS/EmulatorJS) (GPL-3.0)
- libretro — [libretro.com](https://www.libretro.com/)

**Herramientas**

- MAMP — [mamp.info](https://www.mamp.info/)
- Apache HTTP Server Documentation — [httpd.apache.org/docs](https://httpd.apache.org/docs/)
- Let's Encrypt — [letsencrypt.org](https://letsencrypt.org/)
