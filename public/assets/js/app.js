/* =====================================================================
   RetroCloud · Comportamiento compartido y catalogo
   =====================================================================
   Se carga en todas las paginas. Contiene:

     1. Tema claro/oscuro con preferencia persistente
     2. Menu de navegacion movil
     3. Cierre de sesion
     4. Catalogo: busqueda, filtros, orden, paginacion y favoritos
     5. Registro del Service Worker (PWA)
   ===================================================================== */

'use strict';

import { api, avisar, escapar, retardar, ruta } from './api.js';

/* =====================================================================
   1. TEMA
   ===================================================================== */
const CLAVE_TEMA = 'retrocloud:tema';

function aplicarTema(tema) {
  document.documentElement.dataset.tema = tema;
  const boton = document.getElementById('btn-tema');
  if (boton) {
    const esOscuro = tema !== 'claro';
    boton.setAttribute('aria-label', esOscuro ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro');
    boton.innerHTML = esOscuro
      // Icono de sol
      ? `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" aria-hidden="true">
           <circle cx="12" cy="12" r="4"/>
           <path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
         </svg>`
      // Icono de luna
      : `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" aria-hidden="true">
           <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
         </svg>`;
  }
}

/* El tema se decide antes de pintar para no provocar un destello
   blanco: si no hay preferencia guardada, seguimos la del sistema. */
(function iniciarTema() {
  let tema;
  try {
    tema = localStorage.getItem(CLAVE_TEMA);
  } catch {
    tema = null;   // modo privado puede bloquear localStorage
  }
  if (tema !== 'claro' && tema !== 'oscuro') {
    tema = window.matchMedia?.('(prefers-color-scheme: light)').matches
      ? 'claro'
      : 'oscuro';
  }
  aplicarTema(tema);
})();

document.getElementById('btn-tema')?.addEventListener('click', () => {
  const nuevo = document.documentElement.dataset.tema === 'claro' ? 'oscuro' : 'claro';
  aplicarTema(nuevo);
  try {
    localStorage.setItem(CLAVE_TEMA, nuevo);
  } catch { /* sin persistencia, pero el tema cambia igual */ }
});

/* =====================================================================
   2. NAVEGACION MOVIL
   ===================================================================== */
const botonMenu = document.getElementById('btn-menu');
const navegacion = document.getElementById('nav-principal');

botonMenu?.addEventListener('click', () => {
  const abierto = navegacion?.dataset.abierto === 'true';
  if (navegacion) navegacion.dataset.abierto = String(!abierto);
  botonMenu.setAttribute('aria-expanded', String(!abierto));
});

/* Cierra el menu al pulsar fuera o al tabular fuera de el. */
document.addEventListener('click', (e) => {
  if (navegacion?.dataset.abierto !== 'true') return;
  if (e.target.closest('#nav-principal, #btn-menu')) return;
  navegacion.dataset.abierto = 'false';
  botonMenu?.setAttribute('aria-expanded', 'false');
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && navegacion?.dataset.abierto === 'true') {
    navegacion.dataset.abierto = 'false';
    botonMenu?.setAttribute('aria-expanded', 'false');
    botonMenu?.focus();
  }
});

/* =====================================================================
   3. CERRAR SESION
   ===================================================================== */
document.getElementById('btn-logout')?.addEventListener('click', async (e) => {
  e.preventDefault();
  try {
    await api.auth.logout();
  } catch { /* si falla, la cookie caduca de todos modos */ }
  location.href = ruta('login.php');
});

/* =====================================================================
   4. CATALOGO
   Solo corre si la pagina tiene la rejilla.
   ===================================================================== */
const rejilla = document.getElementById('rejilla-juegos');

if (rejilla) {
  const filtros = {
    q: '',
    consola: '',
    genero: '',
    orden: 'recientes',
    pagina: 1,
    por_pagina: 24,
  };

  const elementos = {
    buscador:  document.getElementById('buscador'),
    consolas:  document.getElementById('fila-consolas'),
    orden:     document.getElementById('selector-orden'),
    genero:    document.getElementById('selector-genero'),
    paginacion: document.getElementById('paginacion'),
    conteo:    document.getElementById('conteo-resultados'),
  };

  /* ---- Sincronizacion con la URL ----
     Asi el usuario puede compartir o recargar manteniendo sus filtros,
     y el boton "atras" del navegador funciona como se espera. */
  function leerUrl() {
    const p = new URLSearchParams(location.search);
    filtros.q       = p.get('q') ?? '';
    filtros.consola = p.get('consola') ?? '';
    filtros.genero  = p.get('genero') ?? '';
    filtros.orden   = p.get('orden') ?? 'recientes';
    filtros.pagina  = Math.max(1, Number(p.get('pagina') ?? 1));

    if (elementos.buscador) elementos.buscador.value = filtros.q;
    if (elementos.orden)    elementos.orden.value = filtros.orden;
    if (elementos.genero)   elementos.genero.value = filtros.genero;
  }

  function escribirUrl() {
    const p = new URLSearchParams();
    if (filtros.q)       p.set('q', filtros.q);
    if (filtros.consola) p.set('consola', filtros.consola);
    if (filtros.genero)  p.set('genero', filtros.genero);
    if (filtros.orden !== 'recientes') p.set('orden', filtros.orden);
    if (filtros.pagina > 1) p.set('pagina', String(filtros.pagina));

    const nueva = p.toString() ? `?${p}` : location.pathname;
    history.replaceState(null, '', nueva);
  }

  /* ---- Color legible sobre el color de la consola ---- */
  function textoSobre(hex) {
    const h = String(hex ?? '#7d5fff').replace('#', '');
    if (h.length !== 6) return '#fff';
    const r = parseInt(h.slice(0, 2), 16);
    const g = parseInt(h.slice(2, 4), 16);
    const b = parseInt(h.slice(4, 6), 16);
    // Luminancia percibida (formula estandar de contraste).
    return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#141428' : '#ffffff';
  }

  /* ---- Iniciales para la portada generada ---- */
  function iniciales(titulo) {
    return String(titulo)
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((p) => p[0])
      .join('')
      .toUpperCase() || '?';
  }

  /* ---- Tarjeta de juego ---- */
  function tarjetaJuego(j) {
    const color = j.color_hex ?? '#7d5fff';

    const portada = j.url_portada
      ? `<img src="${escapar(j.url_portada)}" alt="Portada de ${escapar(j.titulo)}"
              loading="lazy" decoding="async">`
      /* Sin portada: patron geometrico con las iniciales. Ninguna
         imagen de terceros, todo generado en el cliente. */
      : `<div class="juego__sinportada"
              style="background-color:${escapar(color)}">
           ${escapar(iniciales(j.titulo))}
         </div>`;

    const meta = [
      j.anio ? escapar(String(j.anio)) : null,
      j.genero ? escapar(j.genero) : null,
      j.veces_jugado > 0 ? `${j.veces_jugado} partidas` : null,
    ].filter(Boolean).join(' · ');

    return `
      <article class="juego" data-id="${j.id}">
        <div class="juego__portada">
          ${portada}
          <span class="insignia insignia--consola juego__consola"
                style="background:${escapar(color)};color:${textoSobre(color)}">
            ${escapar(j.consola)}
          </span>
          <button type="button" class="juego__fav" data-fav="${j.id}"
                  aria-pressed="${j.favorito ? 'true' : 'false'}"
                  aria-label="${j.favorito ? 'Quitar de' : 'Agregar a'} favoritos">
            <svg width="16" height="16" viewBox="0 0 24 24"
                 fill="${j.favorito ? 'currentColor' : 'none'}"
                 stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M19 14c1.5-1.5 3-3.3 3-5.5A5.5 5.5 0 0 0 12 5.5 5.5 5.5 0 0 0 2 8.5c0 2.2 1.5 4 3 5.5l7 7z"/>
            </svg>
          </button>
          <div class="juego__play" aria-hidden="true">
            <span>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M8 5v14l11-7z"/>
              </svg>
            </span>
          </div>
        </div>
        <div class="juego__info">
          <h3 class="juego__titulo">${escapar(j.titulo)}</h3>
          <div class="juego__meta">${meta || escapar(j.peso ?? '')}</div>
        </div>
        <a class="juego__enlace" href="${escapar(j.url_jugar)}">
          <span class="solo-lector">Jugar ${escapar(j.titulo)}</span>
        </a>
      </article>`;
  }

  /* ---- Esqueletos de carga ---- */
  function pintarEsqueletos(n = 12) {
    rejilla.innerHTML = Array.from({ length: n }, () => `
      <div class="juego" aria-hidden="true">
        <div class="esqueleto" style="aspect-ratio:4/3"></div>
        <div class="juego__info">
          <div class="esqueleto" style="height:14px;width:85%"></div>
          <div class="esqueleto" style="height:11px;width:55%;margin-top:7px"></div>
        </div>
      </div>`).join('');
  }

  /* ---- Paginacion ----
     Ventana deslizante de paginas alrededor de la actual, con puntos
     suspensivos: una lista de 40 botones no sirve a nadie. */
  function pintarPaginacion(p) {
    if (!elementos.paginacion) return;

    if (p.paginas <= 1) {
      elementos.paginacion.innerHTML = '';
      return;
    }

    const actual = p.pagina;
    const total = p.paginas;
    const partes = [];

    partes.push(`
      <button type="button" data-pagina="${actual - 1}" ${actual === 1 ? 'disabled' : ''}
              aria-label="Pagina anterior">&lsaquo;</button>`);

    const numeros = new Set([1, total, actual, actual - 1, actual + 1]);
    const visibles = [...numeros]
      .filter((n) => n >= 1 && n <= total)
      .sort((a, b) => a - b);

    let anterior = 0;
    for (const n of visibles) {
      if (n - anterior > 1) {
        partes.push('<button type="button" disabled>...</button>');
      }
      partes.push(`
        <button type="button" data-pagina="${n}"
                ${n === actual ? 'aria-current="page"' : ''}>${n}</button>`);
      anterior = n;
    }

    partes.push(`
      <button type="button" data-pagina="${actual + 1}" ${actual === total ? 'disabled' : ''}
              aria-label="Pagina siguiente">&rsaquo;</button>`);

    elementos.paginacion.innerHTML = partes.join('');
  }

  /* ---- Carga del catalogo ---- */
  let peticionActual = 0;

  async function cargarCatalogo({ conEsqueletos = true } = {}) {
    const miPeticion = ++peticionActual;
    if (conEsqueletos) pintarEsqueletos(filtros.por_pagina > 12 ? 12 : filtros.por_pagina);

    try {
      const r = await api.juegos.listar(filtros);

      // Si el usuario siguio tecleando, esta respuesta ya es vieja.
      if (miPeticion !== peticionActual) return;

      if (!r.juegos || r.juegos.length === 0) {
        const hayFiltros = filtros.q || filtros.consola || filtros.genero;
        rejilla.innerHTML = `
          <div class="vacio" style="grid-column:1/-1">
            <div class="vacio__icono">
              <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="1.8" stroke-linecap="round">
                <rect x="2" y="7" width="20" height="13" rx="3"/>
                <path d="M7 12h3m-1.5-1.5v3M15 12h.01M18 14h.01"/>
              </svg>
            </div>
            <h3>${hayFiltros ? 'Sin resultados' : 'El catalogo esta vacio'}</h3>
            <p>${hayFiltros
              ? 'Prueba con otra busqueda o quita los filtros.'
              : 'Entra al panel de administracion y agrega tu primer juego.'}</p>
            ${hayFiltros
              ? '<button type="button" class="btn btn--fantasma" id="btn-limpiar">Quitar filtros</button>'
              : ''}
          </div>`;

        document.getElementById('btn-limpiar')?.addEventListener('click', () => {
          filtros.q = '';
          filtros.consola = '';
          filtros.genero = '';
          filtros.pagina = 1;
          if (elementos.buscador) elementos.buscador.value = '';
          if (elementos.genero) elementos.genero.value = '';
          elementos.consolas?.querySelectorAll('[aria-pressed="true"]')
            .forEach((b) => b.setAttribute('aria-pressed', 'false'));
          escribirUrl();
          cargarCatalogo();
        });
      } else {
        rejilla.innerHTML = r.juegos.map(tarjetaJuego).join('');
      }

      pintarPaginacion(r.paginacion);

      if (elementos.conteo) {
        const t = r.paginacion.total;
        elementos.conteo.textContent = t === 0
          ? 'Sin juegos'
          : `${t} juego${t === 1 ? '' : 's'}`;
      }
    } catch (e) {
      if (miPeticion !== peticionActual) return;
      rejilla.innerHTML = `
        <div class="vacio" style="grid-column:1/-1">
          <h3>No se pudo cargar el catalogo</h3>
          <p>${escapar(e.message)}</p>
          <button type="button" class="btn btn--primario"
                  onclick="location.reload()">Reintentar</button>
        </div>`;
    }
  }

  /* ---- Fila de consolas ---- */
  async function cargarConsolas() {
    if (!elementos.consolas) return;

    try {
      const r = await api.juegos.consolas();
      const chips = [`
        <button type="button" class="chip-consola" data-consola=""
                aria-pressed="${filtros.consola === '' ? 'true' : 'false'}">
          Todas
        </button>`];

      for (const c of r.consolas) {
        // Ocultamos las consolas sin juegos: no aportan nada al filtro.
        if (c.total_juegos === 0 && filtros.consola !== c.slug) continue;
        chips.push(`
          <button type="button" class="chip-consola" data-consola="${escapar(c.slug)}"
                  aria-pressed="${filtros.consola === c.slug ? 'true' : 'false'}"
                  style="${filtros.consola === c.slug
                    ? `background:${escapar(c.color_hex)};border-color:${escapar(c.color_hex)};color:${textoSobre(c.color_hex)}`
                    : ''}">
            ${escapar(c.nombre)}
            <span class="chip-consola__conteo">${c.total_juegos}</span>
          </button>`);
      }

      elementos.consolas.innerHTML = chips.join('');
    } catch {
      elementos.consolas.innerHTML = '';
    }
  }

  /* ---- Generos ---- */
  async function cargarGeneros() {
    if (!elementos.genero) return;
    try {
      const r = await api.juegos.generos();
      const opciones = ['<option value="">Todos los generos</option>'];
      for (const g of r.generos) {
        opciones.push(
          `<option value="${escapar(g.nombre)}"
            ${filtros.genero === g.nombre ? 'selected' : ''}>${escapar(g.nombre)}</option>`
        );
      }
      elementos.genero.innerHTML = opciones.join('');
    } catch { /* el filtro de genero es opcional */ }
  }

  /* ---- Eventos ---- */

  // Busqueda con retardo: una peticion cuando el usuario para de teclear.
  elementos.buscador?.addEventListener('input', retardar((e) => {
    filtros.q = e.target.value.trim();
    filtros.pagina = 1;
    escribirUrl();
    cargarCatalogo();
  }, 350));

  // Enter no debe recargar la pagina.
  elementos.buscador?.closest('form')?.addEventListener('submit', (e) => e.preventDefault());

  elementos.consolas?.addEventListener('click', (e) => {
    const chip = e.target.closest('[data-consola]');
    if (!chip) return;

    filtros.consola = chip.dataset.consola;
    filtros.pagina = 1;
    escribirUrl();
    cargarConsolas();
    cargarCatalogo();
  });

  elementos.orden?.addEventListener('change', (e) => {
    filtros.orden = e.target.value;
    filtros.pagina = 1;
    escribirUrl();
    cargarCatalogo();
  });

  elementos.genero?.addEventListener('change', (e) => {
    filtros.genero = e.target.value;
    filtros.pagina = 1;
    escribirUrl();
    cargarCatalogo();
  });

  elementos.paginacion?.addEventListener('click', (e) => {
    const boton = e.target.closest('[data-pagina]');
    if (!boton || boton.disabled) return;

    filtros.pagina = Number(boton.dataset.pagina);
    escribirUrl();
    cargarCatalogo();
    rejilla.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  /* Favoritos: delegado en la rejilla para que siga vivo tras repintar.
     Se actualiza el icono al instante y se revierte si el servidor
     rechaza (actualizacion optimista). */
  rejilla.addEventListener('click', async (e) => {
    const boton = e.target.closest('[data-fav]');
    if (!boton) return;

    e.preventDefault();
    e.stopPropagation();

    const id = Number(boton.dataset.fav);
    const antes = boton.getAttribute('aria-pressed') === 'true';

    boton.setAttribute('aria-pressed', String(!antes));
    boton.querySelector('svg')?.setAttribute('fill', !antes ? 'currentColor' : 'none');
    boton.disabled = true;

    try {
      const r = await api.favoritos.alternar(id);
      boton.setAttribute('aria-pressed', String(r.favorito));
      boton.querySelector('svg')?.setAttribute('fill', r.favorito ? 'currentColor' : 'none');
      boton.setAttribute(
        'aria-label',
        `${r.favorito ? 'Quitar de' : 'Agregar a'} favoritos`
      );
    } catch (err) {
      boton.setAttribute('aria-pressed', String(antes));
      boton.querySelector('svg')?.setAttribute('fill', antes ? 'currentColor' : 'none');
      if (err.codigo === 401) {
        avisar('Inicia sesion para guardar favoritos.', 'info');
      } else {
        avisar(err.message, 'error');
      }
    } finally {
      boton.disabled = false;
    }
  });

  /* Atajo: "/" enfoca el buscador, como en GitHub. */
  document.addEventListener('keydown', (e) => {
    if (e.key === '/' && !e.target.matches('input, textarea, select')) {
      e.preventDefault();
      elementos.buscador?.focus();
    }
  });

  /* ---- Arranque ---- */
  leerUrl();
  cargarConsolas();
  cargarGeneros();
  cargarCatalogo();

  window.addEventListener('popstate', () => {
    leerUrl();
    cargarConsolas();
    cargarCatalogo();
  });
}

/* =====================================================================
   5. SERVICE WORKER (PWA)
   Permite instalar RetroCloud como aplicacion y que la interfaz abra
   sin conexion. Las ROMs no se cachean: pesan demasiado y requieren
   sesion valida en el servidor.
   ===================================================================== */
if ('serviceWorker' in navigator && location.protocol !== 'file:') {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register(ruta('sw.js')).catch((e) => {
      console.warn('[RetroCloud] Service Worker no registrado:', e.message);
    });
  });
}
