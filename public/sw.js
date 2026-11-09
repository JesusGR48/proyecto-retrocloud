/* =====================================================================
   RetroCloud · Service Worker
   =====================================================================
   Convierte RetroCloud en una PWA instalable y hace que la interfaz
   cargue al instante en visitas posteriores.

   QUE SE CACHEA Y QUE NO (decision de diseno, no descuido):

     SI  · CSS, JavaScript, iconos y el manifiesto.
           Son pequenos, cambian poco y son lo que hace que la app
           arranque rapido.

     NO  · Las ROMs (api/rom.php). Pesan decenas de megas y, sobre
           todo, requieren una sesion valida: cachearlas dejaria una
           copia accesible a cualquiera que abra el navegador despues.

     NO  · Las respuestas de la API ni el HTML generado por PHP.
           Llevan el token CSRF y datos por usuario; servir una version
           guardada daria datos de otra sesion o tokens caducados.

   Estrategias:
     · Estaticos  -> cache primero, red como respaldo (stale-while-revalidate)
     · Navegacion -> red primero, pagina sin conexion como respaldo
     · API y ROMs -> solo red, nunca cache
   ===================================================================== */

'use strict';

/* Al cambiar este numero, el navegador descarta la cache anterior.
   Hay que subirlo cada vez que se modifiquen el CSS o el JS. */
const VERSION = 'retrocloud-v4';
const CACHE_ESTATICOS = `${VERSION}-estaticos`;

/** Archivos que se guardan al instalar. */
const PRECARGA = [
  'assets/css/estilos.css',
  'assets/js/api.js',
  'assets/js/app.js',
  'assets/img/icono-192.png',
  'assets/img/icono-512.png',
  'manifest.webmanifest',
];

/* ---------------------------------------------------------------------
   Instalacion
   --------------------------------------------------------------------- */
self.addEventListener('install', (evento) => {
  evento.waitUntil(
    caches.open(CACHE_ESTATICOS)
      .then((cache) =>
        // addAll falla entera si un solo archivo falla; los agregamos
        // uno a uno para que una ruta equivocada no rompa la instalacion.
        Promise.allSettled(
          PRECARGA.map((ruta) => cache.add(new Request(ruta, { cache: 'reload' })))
        )
      )
      // Activa este worker sin esperar a que se cierren las pestanas.
      .then(() => self.skipWaiting())
  );
});

/* ---------------------------------------------------------------------
   Activacion: limpieza de caches viejas
   --------------------------------------------------------------------- */
self.addEventListener('activate', (evento) => {
  evento.waitUntil(
    caches.keys()
      .then((nombres) =>
        Promise.all(
          nombres
            .filter((n) => n.startsWith('retrocloud-') && !n.startsWith(VERSION))
            .map((n) => caches.delete(n))
        )
      )
      .then(() => self.clients.claim())
  );
});

/* ---------------------------------------------------------------------
   Interceptacion de peticiones
   --------------------------------------------------------------------- */
self.addEventListener('fetch', (evento) => {
  const peticion = evento.request;
  const url = new URL(peticion.url);

  // Solo gestionamos GET del propio origen.
  if (peticion.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }

  // --- Nunca cachear: API, ROMs, partidas guardadas ---
  if (url.pathname.includes('/api/')) {
    return;   // deja pasar a la red sin tocar nada
  }

  // --- Navegacion (documentos HTML): red primero ---
  if (peticion.mode === 'navigate') {
    evento.respondWith(
      fetch(peticion).catch(() =>
        new Response(paginaSinConexion(), {
          status: 503,
          headers: { 'Content-Type': 'text/html; charset=utf-8' },
        })
      )
    );
    return;
  }

  const esCodigo  = /\.(css|js|mjs|webmanifest)$/i.test(url.pathname);
  const esRecurso = /\.(png|jpg|jpeg|webp|gif|svg|woff2?)$/i.test(url.pathname);

  if (!esCodigo && !esRecurso) return;

  /* --- CODIGO (CSS y JavaScript): la red manda ------------------------
     Antes se servia primero desde la cache, y eso causo un problema
     real: al corregir un fallo, el telefono seguia ejecutando la version
     antigua durante dias y parecia que el arreglo no funcionaba.

     Ahora se pide siempre a la red y la cache queda como respaldo para
     cuando no hay conexion. El coste es minimo: estos archivos pesan
     pocos kilobytes y el servidor responde 304 Not Modified si no han
     cambiado. La correccion llega siempre. */
  if (esCodigo) {
    evento.respondWith(
      fetch(peticion)
        .then((respuesta) => {
          if (respuesta.ok && respuesta.status === 200) {
            const copia = respuesta.clone();
            caches.open(CACHE_ESTATICOS).then((c) => c.put(peticion, copia));
          }
          return respuesta;
        })
        // Sin conexion: se tira de lo ultimo que se guardo.
        .catch(() => caches.match(peticion))
    );
    return;
  }

  /* --- RECURSOS (imagenes y fuentes): la cache manda ------------------
     Aqui si conviene servir desde la cache: pesan mas, cambian poco y
     que una portada tarde un dia en actualizarse no molesta a nadie.
     La red refresca la copia en segundo plano para la proxima visita. */
  evento.respondWith(
    caches.match(peticion).then((enCache) => {
      const desdeRed = fetch(peticion)
        .then((respuesta) => {
          if (respuesta.ok && respuesta.status === 200) {
            const copia = respuesta.clone();
            caches.open(CACHE_ESTATICOS).then((c) => c.put(peticion, copia));
          }
          return respuesta;
        })
        .catch(() => enCache);

      return enCache ?? desdeRed;
    })
  );
});

/* ---------------------------------------------------------------------
   Pagina de respaldo sin conexion
   --------------------------------------------------------------------- */
function paginaSinConexion() {
  return `<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sin conexion · RetroCloud</title>
  <style>
    :root { color-scheme: dark; }
    body {
      margin: 0; min-height: 100vh;
      display: grid; place-items: center;
      font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
      background: #0d0d16; color: #ecedf5; padding: 24px;
      text-align: center;
    }
    .caja { max-width: 400px; }
    .icono {
      width: 72px; height: 72px; margin: 0 auto 20px;
      border-radius: 18px; display: grid; place-items: center;
      background: linear-gradient(135deg, #7d5fff, #00e5c0);
    }
    h1 { font-size: 1.4rem; margin: 0 0 8px; }
    p { color: #a2a3bd; margin: 0 0 22px; }
    button {
      padding: 11px 22px; border: none; border-radius: 8px;
      background: #7d5fff; color: #fff;
      font: inherit; font-weight: 600; cursor: pointer;
    }
    button:hover { background: #6b4ef0; }
  </style>
</head>
<body>
  <div class="caja">
    <div class="icono" aria-hidden="true">
      <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#fff"
           stroke-width="2" stroke-linecap="round">
        <rect x="2" y="8" width="20" height="11" rx="5"/>
        <path d="M7 13h2.5M8.25 11.75v2.5"/>
        <circle cx="16" cy="12.5" r="1.1" fill="#fff" stroke="none"/>
        <circle cx="18.5" cy="14.8" r="1.1" fill="#fff" stroke="none"/>
      </svg>
    </div>
    <h1>Sin conexion</h1>
    <p>
      RetroCloud necesita conexion con el servidor para cargar el catalogo
      y tus partidas guardadas.
    </p>
    <button type="button" onclick="location.reload()">Reintentar</button>
  </div>
</body>
</html>`;
}
