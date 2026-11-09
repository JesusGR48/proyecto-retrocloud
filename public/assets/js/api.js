/* =====================================================================
   RetroCloud · Capa de comunicacion con la API
   =====================================================================
   Un unico punto por el que pasan TODAS las peticiones al backend.
   Concentrar esto aqui da tres ventajas:

     1. El token CSRF se adjunta solo; ninguna pantalla puede olvidarlo.
     2. El manejo de errores es uniforme (401 -> al login, 429 -> espera).
     3. Las pantallas quedan limpias: solo piden datos y los pintan.

   Se usa JavaScript moderno (ES6+): modulos, clases, async/await,
   desestructuracion, parametros por defecto y campos privados (#).
   ===================================================================== */

'use strict';

/* ---------------------------------------------------------------------
   Prefijo de rutas
   Las paginas de /admin viven un nivel por debajo de la raiz publica,
   asi que 'api/juegos.php' resolveria a '/admin/api/juegos.php'. El
   servidor nos deja el prefijo correcto en window.RETROCLOUD_BASE
   ('' en la raiz, '../' en /admin).
   --------------------------------------------------------------------- */
const BASE = typeof window.RETROCLOUD_BASE === 'string'
  ? window.RETROCLOUD_BASE
  : '';

/**
 * Convierte una ruta relativa a la raiz publica en una utilizable
 * desde la pagina actual. Las absolutas y las externas se dejan igual.
 */
export function ruta(relativa) {
  const r = String(relativa ?? '');
  if (r === '' || r.startsWith('/') || /^[a-z]+:/i.test(r)) return r;
  return BASE + r;
}

/* ---------------------------------------------------------------------
   Token CSRF
   El servidor lo imprime en <meta name="csrf-token"> al renderizar la
   pagina. Se guarda en memoria y se renueva si el backend manda uno
   nuevo (por ejemplo tras iniciar sesion).
   --------------------------------------------------------------------- */
let tokenCsrf = document
  .querySelector('meta[name="csrf-token"]')
  ?.getAttribute('content') ?? '';

export const csrf = {
  obtener: () => tokenCsrf,
  fijar(nuevo) {
    if (typeof nuevo === 'string' && nuevo !== '') {
      tokenCsrf = nuevo;
      document
        .querySelector('meta[name="csrf-token"]')
        ?.setAttribute('content', nuevo);
    }
  },
};

/* ---------------------------------------------------------------------
   Error enriquecido
   Conserva el codigo HTTP y los errores por campo que manda el
   validador del servidor, para poder pintarlos junto a cada input.
   --------------------------------------------------------------------- */
export class ErrorApi extends Error {
  constructor(mensaje, codigo = 0, campos = {}) {
    super(mensaje);
    this.name = 'ErrorApi';
    this.codigo = codigo;
    this.campos = campos;
  }

  /** true cuando el fallo es de validacion y hay detalle por campo. */
  get esValidacion() {
    return this.codigo === 422 || Object.keys(this.campos).length > 0;
  }
}

/* ---------------------------------------------------------------------
   Peticion base
   --------------------------------------------------------------------- */

/**
 * Lanza una peticion contra la API y devuelve el contenido de `datos`.
 *
 * @param {string} url            ruta relativa, p. ej. 'api/juegos.php'
 * @param {object} [opciones]
 * @param {string} [opciones.metodo='GET']
 * @param {object|FormData|null} [opciones.cuerpo=null]
 * @param {number} [opciones.timeout=45000]
 * @returns {Promise<any>}
 */
async function peticion(url, { metodo = 'GET', cuerpo = null, timeout = 45000 } = {}) {
  const cabeceras = { Accept: 'application/json' };
  let payload = null;

  if (cuerpo instanceof FormData) {
    // Con FormData NO se fija Content-Type a mano: el navegador tiene
    // que generar el boundary del multipart por su cuenta.
    payload = cuerpo;
    if (tokenCsrf && !cuerpo.has('csrf_token')) {
      cuerpo.append('csrf_token', tokenCsrf);
    }
  } else if (cuerpo !== null) {
    cabeceras['Content-Type'] = 'application/json';
    payload = JSON.stringify(cuerpo);
  }

  if (metodo !== 'GET' && tokenCsrf) {
    cabeceras['X-CSRF-Token'] = tokenCsrf;
  }

  // AbortController: corta la peticion si el servidor no contesta.
  const abortador = new AbortController();
  const reloj = setTimeout(() => abortador.abort(), timeout);

  let respuesta;
  try {
    respuesta = await fetch(ruta(url), {
      method: metodo,
      headers: cabeceras,
      body: payload,
      credentials: 'same-origin',   // manda la cookie de sesion
      signal: abortador.signal,
    });
  } catch (e) {
    clearTimeout(reloj);
    if (e.name === 'AbortError') {
      throw new ErrorApi('El servidor tardo demasiado en responder.', 0);
    }
    throw new ErrorApi('Sin conexion con el servidor. Revisa que Apache este encendido.', 0);
  }
  clearTimeout(reloj);

  // El cuerpo puede no ser JSON si PHP murio con un error fatal.
  let json = null;
  const tipo = respuesta.headers.get('Content-Type') ?? '';
  if (tipo.includes('application/json')) {
    try {
      json = await respuesta.json();
    } catch {
      json = null;
    }
  }

  if (!respuesta.ok || json?.ok === false) {
    const mensaje = json?.error ?? `Error ${respuesta.status} del servidor.`;
    const error = new ErrorApi(mensaje, respuesta.status, json?.campos ?? {});

    // 401: la sesion caduco. Redirigimos conservando el destino.
    if (respuesta.status === 401 && !url.includes('accion=sesion')) {
      const volver = encodeURIComponent(location.pathname + location.search);
      location.href = `${ruta('login.php')}?volver=${volver}&expirada=1`;
    }

    throw error;
  }

  if (json?.datos?.csrf) {
    csrf.fijar(json.datos.csrf);
  }

  return json?.datos ?? null;
}

/** Construye una query string ignorando valores vacios. */
function query(parametros = {}) {
  const limpios = Object.entries(parametros).filter(
    ([, v]) => v !== null && v !== undefined && v !== ''
  );
  if (limpios.length === 0) return '';
  return '&' + new URLSearchParams(limpios).toString();
}

/* ---------------------------------------------------------------------
   API publica, agrupada por recurso
   --------------------------------------------------------------------- */
export const api = {

  /* ---- Autenticacion ---- */
  auth: {
    sesion: () => peticion('api/auth.php?accion=sesion'),

    registro: (datos) =>
      peticion('api/auth.php?accion=registro', { metodo: 'POST', cuerpo: datos }),

    login: (usuario, password) =>
      peticion('api/auth.php?accion=login', {
        metodo: 'POST',
        cuerpo: { usuario, password },
      }),

    logout: () => peticion('api/auth.php?accion=logout', { metodo: 'POST' }),

    cambiarPassword: (actual, nueva, nueva2) =>
      peticion('api/auth.php?accion=password', {
        metodo: 'POST',
        cuerpo: { actual, nueva, nueva2 },
      }),
  },

  /* ---- Catalogo ---- */
  juegos: {
    listar: (filtros = {}) =>
      peticion('api/juegos.php?accion=listar' + query(filtros)),

    detalle: (id) => peticion(`api/juegos.php?accion=detalle&id=${Number(id)}`),

    consolas: () => peticion('api/juegos.php?accion=consolas'),

    generos: () => peticion('api/juegos.php?accion=generos'),

    // Las altas y ediciones viajan como FormData porque incluyen archivos.
    crear: (formData) =>
      peticion('api/juegos.php?accion=crear', {
        metodo: 'POST',
        cuerpo: formData,
        timeout: 300000,            // 5 min: las ROMs pueden ser grandes
      }),

    actualizar: (formData) =>
      peticion('api/juegos.php?accion=actualizar', {
        metodo: 'POST',
        cuerpo: formData,
        timeout: 300000,
      }),

    eliminar: (id) =>
      peticion('api/juegos.php?accion=eliminar', {
        metodo: 'POST',
        cuerpo: { id: Number(id) },
      }),
  },

  /* ---- Partidas guardadas ---- */
  saves: {
    listar: (juegoId) =>
      peticion(`api/saves.php?accion=listar&juego_id=${Number(juegoId)}`),

    misPartidas: () => peticion('api/saves.php?accion=mis_partidas'),

    /**
     * Sube un volcado binario del emulador.
     * @param {number} juegoId
     * @param {number} slot      0 = autoguardado, 1..4 = manual
     * @param {Uint8Array|ArrayBuffer} datos
     * @param {object} [extra]   { nombre, captura, segundos }
     */
    guardar(juegoId, slot, datos, extra = {}) {
      const fd = new FormData();
      fd.append('juego_id', String(juegoId));
      fd.append('slot', String(slot));

      // Blob + nombre de archivo: asi PHP lo recibe en $_FILES.
      const blob = datos instanceof Blob
        ? datos
        : new Blob([datos], { type: 'application/octet-stream' });
      fd.append('estado', blob, `slot${slot}.state`);

      if (extra.nombre)   fd.append('nombre', extra.nombre);
      if (extra.captura)  fd.append('captura', extra.captura);
      if (extra.segundos) fd.append('segundos', String(Math.round(extra.segundos)));

      return peticion('api/saves.php?accion=guardar', {
        metodo: 'POST',
        cuerpo: fd,
        timeout: 120000,
      });
    },

    /** Descarga el volcado como ArrayBuffer, listo para loadState(). */
    async descargar(saveId) {
      const r = await fetch(ruta(`api/saves.php?accion=descargar&id=${Number(saveId)}`), {
        credentials: 'same-origin',
      });
      if (!r.ok) {
        throw new ErrorApi('No se pudo descargar la partida guardada.', r.status);
      }
      return r.arrayBuffer();
    },

    eliminar: (id) =>
      peticion('api/saves.php?accion=eliminar', {
        metodo: 'POST',
        cuerpo: { id: Number(id) },
      }),
  },

  /* ---- Favoritos ---- */
  favoritos: {
    listar: () => peticion('api/favoritos.php?accion=listar'),

    alternar: (juegoId) =>
      peticion('api/favoritos.php?accion=alternar', {
        metodo: 'POST',
        cuerpo: { juego_id: Number(juegoId) },
      }),
  },

  /* ---- Telemetria de sesiones de juego ---- */
  sesiones: {
    iniciar: (juegoId) =>
      peticion('api/sesiones.php?accion=iniciar', {
        metodo: 'POST',
        cuerpo: { juego_id: Number(juegoId) },
      }),

    latido: (sesionId, segundos) =>
      peticion('api/sesiones.php?accion=latido', {
        metodo: 'POST',
        cuerpo: { sesion_id: Number(sesionId), segundos: Math.round(segundos) },
      }),

    cerrar: (sesionId, segundos) =>
      peticion('api/sesiones.php?accion=cerrar', {
        metodo: 'POST',
        cuerpo: { sesion_id: Number(sesionId), segundos: Math.round(segundos) },
      }),

    /**
     * Cierre al abandonar la pagina.
     * fetch() normal se cancela cuando el documento se descarga, asi que
     * aqui usamos sendBeacon(): el navegador se encarga de entregar la
     * peticion aunque la pestana ya se haya cerrado.
     */
    cerrarAlSalir(sesionId, segundos) {
      if (!navigator.sendBeacon) return false;
      const fd = new FormData();
      fd.append('accion', 'cerrar');
      fd.append('sesion_id', String(sesionId));
      fd.append('segundos', String(Math.round(segundos)));
      fd.append('csrf_token', tokenCsrf);
      return navigator.sendBeacon(ruta('api/sesiones.php'), fd);
    },
  },

  /* ---- Consolas y su BIOS (solo admin) ---- */
  consolas: {
    listar: () => peticion('api/consolas.php?accion=listar'),

    /**
     * Sube la firmware de sistema de una consola.
     * Viaja como FormData porque es un archivo binario.
     */
    subirBios(consolaId, archivo) {
      const fd = new FormData();
      fd.append('consola_id', String(consolaId));
      fd.append('bios', archivo, archivo.name);
      return peticion('api/consolas.php?accion=subir_bios', {
        metodo: 'POST',
        cuerpo: fd,
        timeout: 120000,
      });
    },

    borrarBios: (consolaId) =>
      peticion('api/consolas.php?accion=borrar_bios', {
        metodo: 'POST',
        cuerpo: { consola_id: Number(consolaId) },
      }),

    exigirBios: (consolaId, requerido) =>
      peticion('api/consolas.php?accion=exigir_bios', {
        metodo: 'POST',
        cuerpo: { consola_id: Number(consolaId), requerido: !!requerido },
      }),
  },

  /* ---- Usuarios (solo admin) ---- */
  usuarios: {
    listar: (filtros = {}) =>
      peticion('api/usuarios.php?accion=listar' + query(filtros)),

    rol: (id, rol) =>
      peticion('api/usuarios.php?accion=rol', {
        metodo: 'POST',
        cuerpo: { id: Number(id), rol },
      }),

    activo: (id, activo) =>
      peticion('api/usuarios.php?accion=activo', {
        metodo: 'POST',
        cuerpo: { id: Number(id), activo: !!activo },
      }),

    eliminar: (id) =>
      peticion('api/usuarios.php?accion=eliminar', {
        metodo: 'POST',
        cuerpo: { id: Number(id) },
      }),
  },

  /* ---- Estadisticas ---- */
  stats: {
    resumen:   () => peticion('api/stats.php?accion=resumen'),
    perfil:    () => peticion('api/stats.php?accion=perfil'),
    ranking:   (limite = 10) => peticion(`api/stats.php?accion=ranking&limite=${Number(limite)}`),
    dashboard: () => peticion('api/stats.php?accion=dashboard'),
  },
};

/* =====================================================================
   Utilidades de interfaz compartidas
   ===================================================================== */

/* ---- Notificaciones flotantes ---- */
const ICONOS = {
  exito: '<path d="M20 6 9 17l-5-5"/>',
  error: '<path d="M18 6 6 18M6 6l12 12"/>',
  info:  '<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/>',
};

/**
 * Muestra un aviso flotante abajo a la derecha.
 * @param {string} mensaje
 * @param {'exito'|'error'|'info'} [tipo='info']
 * @param {number} [ms=4000]
 */
export function avisar(mensaje, tipo = 'info', ms = 4000) {
  let pila = document.querySelector('.notificaciones');
  if (!pila) {
    pila = document.createElement('div');
    pila.className = 'notificaciones';
    pila.setAttribute('role', 'status');
    // aria-live: el lector de pantalla anuncia los avisos nuevos.
    pila.setAttribute('aria-live', 'polite');
    document.body.appendChild(pila);
  }

  const nodo = document.createElement('div');
  nodo.className = `notificacion notificacion--${tipo}`;
  nodo.innerHTML = `
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2.2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      ${ICONOS[tipo] ?? ICONOS.info}
    </svg>
    <div>${escapar(mensaje)}</div>`;

  pila.appendChild(nodo);

  setTimeout(() => {
    nodo.classList.add('saliendo');
    nodo.addEventListener('animationend', () => nodo.remove(), { once: true });
    // Red de seguridad si la animacion no dispara (reduced-motion).
    setTimeout(() => nodo.remove(), 400);
  }, ms);
}

/**
 * Escapa texto antes de inyectarlo con innerHTML.
 * Segunda linea de defensa contra XSS: el servidor ya valida y escapa,
 * pero el cliente nunca debe confiar en que un dato llegue limpio.
 */
export function escapar(texto) {
  const d = document.createElement('div');
  d.textContent = String(texto ?? '');
  return d.innerHTML;
}

/** Pinta los errores por campo que devolvio el validador del servidor. */
export function pintarErrores(formulario, campos = {}) {
  formulario.querySelectorAll('[data-error-de]').forEach((n) => { n.textContent = ''; });
  formulario.querySelectorAll('[aria-invalid]').forEach((n) => {
    n.removeAttribute('aria-invalid');
  });

  let primero = null;
  for (const [campo, mensaje] of Object.entries(campos)) {
    const destino = formulario.querySelector(`[data-error-de="${campo}"]`);
    if (destino) destino.textContent = mensaje;

    const input = formulario.querySelector(`[name="${campo}"]`);
    if (input) {
      input.setAttribute('aria-invalid', 'true');
      primero ??= input;
    }
  }
  primero?.focus();
}

/** Pone/quita el estado de carga de un boton sin perder su texto. */
export function cargando(boton, activo, textoCargando = 'Procesando...') {
  if (!boton) return;
  if (activo) {
    boton.dataset.textoOriginal ??= boton.innerHTML;
    boton.setAttribute('aria-busy', 'true');
    boton.disabled = true;
    boton.innerHTML = `<span class="girador"></span><span>${escapar(textoCargando)}</span>`;
  } else {
    boton.removeAttribute('aria-busy');
    boton.disabled = false;
    if (boton.dataset.textoOriginal) {
      boton.innerHTML = boton.dataset.textoOriginal;
      delete boton.dataset.textoOriginal;
    }
  }
}

/** Retrasa la ejecucion hasta que el usuario deja de teclear. */
export function retardar(fn, ms = 320) {
  let reloj;
  return (...args) => {
    clearTimeout(reloj);
    reloj = setTimeout(() => fn(...args), ms);
  };
}

/** Dialogo de confirmacion accesible, construido sobre el modal del CSS. */
export function confirmar({
  titulo = 'Confirmar',
  mensaje = '',
  textoOk = 'Confirmar',
  peligro = false,
} = {}) {
  return new Promise((resolver) => {
    const previo = document.activeElement;

    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.innerHTML = `
      <div class="modal__caja" role="document">
        <div class="modal__cabecera"><h3>${escapar(titulo)}</h3></div>
        <div class="modal__cuerpo"><p class="mb-0">${escapar(mensaje)}</p></div>
        <div class="modal__pie">
          <button type="button" class="btn btn--fantasma" data-no>Cancelar</button>
          <button type="button" class="btn ${peligro ? 'btn--peligro' : 'btn--primario'}" data-si>
            ${escapar(textoOk)}
          </button>
        </div>
      </div>`;

    const cerrar = (valor) => {
      modal.remove();
      document.removeEventListener('keydown', alTeclear);
      previo?.focus?.();
      resolver(valor);
    };

    const alTeclear = (e) => {
      if (e.key === 'Escape') cerrar(false);
      // Mantiene el foco dentro del dialogo mientras este abierto.
      if (e.key === 'Tab') {
        const focos = modal.querySelectorAll('button');
        const primero = focos[0];
        const ultimo = focos[focos.length - 1];
        if (e.shiftKey && document.activeElement === primero) {
          e.preventDefault();
          ultimo.focus();
        } else if (!e.shiftKey && document.activeElement === ultimo) {
          e.preventDefault();
          primero.focus();
        }
      }
    };

    modal.querySelector('[data-si]').addEventListener('click', () => cerrar(true));
    modal.querySelector('[data-no]').addEventListener('click', () => cerrar(false));
    modal.addEventListener('click', (e) => { if (e.target === modal) cerrar(false); });
    document.addEventListener('keydown', alTeclear);

    document.body.appendChild(modal);
    modal.querySelector('[data-si]').focus();
  });
}

/* ---- Formato ---- */
export const formato = {
  bytes(n) {
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let v = Number(n) || 0;
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return `${i === 0 ? Math.round(v) : v.toFixed(1)} ${u[i]}`;
  },

  tiempo(segundos) {
    const s = Math.max(0, Math.round(Number(segundos) || 0));
    if (s < 60) return `${s} s`;
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    if (h === 0) return `${m} min`;
    return m === 0 ? `${h} h` : `${h} h ${m} min`;
  },

  reloj(segundos) {
    const s = Math.max(0, Math.round(Number(segundos) || 0));
    const h = String(Math.floor(s / 3600)).padStart(2, '0');
    const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
    const g = String(s % 60).padStart(2, '0');
    return h === '00' ? `${m}:${g}` : `${h}:${m}:${g}`;
  },

  fecha(iso) {
    if (!iso) return '-';
    const d = new Date(String(iso).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(iso);
    return d.toLocaleDateString('es-MX', {
      day: '2-digit', month: 'short', year: 'numeric',
    });
  },

  fechaHora(iso) {
    if (!iso) return '-';
    const d = new Date(String(iso).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(iso);
    return d.toLocaleString('es-MX', {
      day: '2-digit', month: 'short', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
    });
  },

  /** "Hace 5 minutos", "Hace 2 dias"... */
  relativo(iso) {
    if (!iso) return '-';
    const d = new Date(String(iso).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(iso);

    const seg = Math.floor((Date.now() - d.getTime()) / 1000);
    if (seg < 60)    return 'Hace unos segundos';
    if (seg < 3600)  return `Hace ${Math.floor(seg / 60)} min`;
    if (seg < 86400) return `Hace ${Math.floor(seg / 3600)} h`;
    if (seg < 2592000) return `Hace ${Math.floor(seg / 86400)} d`;
    return this.fecha(iso);
  },
};
