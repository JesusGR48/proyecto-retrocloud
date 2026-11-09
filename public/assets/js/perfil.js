/* =====================================================================
   RetroCloud · Perfil del jugador
   =====================================================================
   Carga en paralelo las cuatro fuentes de datos del perfil y pinta cada
   seccion. Promise.allSettled en lugar de Promise.all: si una peticion
   falla, las demas secciones se muestran igual en lugar de dejar la
   pagina entera en blanco.
   ===================================================================== */

'use strict';

import {
  api, avisar, cargando, confirmar, ErrorApi,
  escapar, formato, pintarErrores,
} from './api.js';

/* ---------------------------------------------------------------------
   Utilidades locales
   --------------------------------------------------------------------- */
const $ = (sel) => document.querySelector(sel);

function iniciales(titulo) {
  return String(titulo)
    .split(/\s+/).filter(Boolean).slice(0, 2)
    .map((p) => p[0]).join('').toUpperCase() || '?';
}

function textoSobre(hex) {
  const h = String(hex ?? '#7d5fff').replace('#', '');
  if (h.length !== 6) return '#fff';
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
  return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#141428' : '#ffffff';
}

/** Portada: la imagen real o un bloque de color con las iniciales. */
function portada(url, titulo, color) {
  return url
    ? `<img src="${escapar(url)}" alt="" loading="lazy" decoding="async">`
    : `<div class="juego__sinportada" style="background-color:${escapar(color ?? '#7d5fff')}">
         ${escapar(iniciales(titulo))}
       </div>`;
}

function mensajeVacio(contenedor, titulo, texto, enlace = null) {
  contenedor.innerHTML = `
    <div class="vacio" style="grid-column:1/-1;padding:32px 16px">
      <h3 style="font-size:1rem">${escapar(titulo)}</h3>
      <p class="text-sm mb-0">${escapar(texto)}</p>
      ${enlace
        ? `<a class="btn btn--primario mt" href="${escapar(enlace)}">Ir al catalogo</a>`
        : ''}
    </div>`;
}

/* =====================================================================
   1. METRICAS Y LOGROS
   ===================================================================== */
function pintarMetricas(datos) {
  const p = datos.perfil;

  $('#metricas').innerHTML = `
    <div class="metrica">
      <div class="metrica__etiqueta">Tiempo jugado</div>
      <div class="metrica__valor">${escapar(p.tiempo_total)}</div>
      <div class="metrica__pie">${p.partidas} partida${p.partidas === 1 ? '' : 's'}</div>
    </div>
    <div class="metrica">
      <div class="metrica__etiqueta">Juegos probados</div>
      <div class="metrica__valor">${p.juegos_distintos}</div>
      <div class="metrica__pie">titulos distintos</div>
    </div>
    <div class="metrica">
      <div class="metrica__etiqueta">En la nube</div>
      <div class="metrica__valor">${p.partidas_guardadas}</div>
      <div class="metrica__pie">partidas guardadas</div>
    </div>
    <div class="metrica">
      <div class="metrica__etiqueta">Logros</div>
      <div class="metrica__valor">${datos.obtenidos}<span
        class="text-debil" style="font-size:1rem">/${datos.logros.length}</span></div>
      <div class="metrica__pie">desbloqueados</div>
    </div>`;
}

const ICONOS_LOGRO = {
  play:   '<path d="M8 5v14l11-7z"/>',
  repeat: '<path d="M17 2l4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 22l-4-4 4-4m14-1v2a4 4 0 0 1-4 4H3"/>',
  medal:  '<circle cx="12" cy="15" r="6"/><path d="M8.2 9.5 5 2h14l-3.2 7.5"/>',
  clock:  '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  fire:   '<path d="M12 2s4 4.5 4 9a4 4 0 0 1-8 0c0-1.5.5-2.5 1-3 0 2 1 3 2 3 0-3-3-4-3-7 0 0 4 1 4-2z"/><path d="M12 22a7 7 0 0 0 7-7c0-2-1-4-2-5"/>',
  grid:   '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
  star:   '<path d="m12 3 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.8 6.2 20.9l1.1-6.5-4.7-4.6 6.5-.9z"/>',
  cloud:  '<path d="M18 10h-1.3A7 7 0 1 0 4 15.5"/><path d="M18 10a4 4 0 0 1 0 8H7"/>',
  shield: '<path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z"/>',
};

function pintarLogros(datos) {
  const contenedor = $('#lista-logros');
  if (!contenedor) return;

  $('#conteo-logros').textContent =
    `${datos.obtenidos} de ${datos.logros.length}`;

  contenedor.innerHTML = datos.logros.map((l) => `
    <div class="logro ${l.obtenido ? 'logro--obtenido' : 'logro--bloqueado'}">
      <div class="logro__icono">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true">
          ${ICONOS_LOGRO[l.icono] ?? ICONOS_LOGRO.star}
        </svg>
      </div>
      <div class="logro__cuerpo">
        <div class="logro__nombre">${escapar(l.nombre)}</div>
        <div class="logro__desc">${escapar(l.descripcion)}</div>
        ${l.obtenido
          ? `<div class="text-xs" style="color:var(--exito);margin-top:4px">
               Conseguido ${escapar(formato.relativo(l.obtenido_en))}
             </div>`
          : `<div class="progreso"><div class="progreso__relleno"
                  style="width:${l.progreso}%"></div></div>`}
      </div>
    </div>`).join('');
}

function pintarHistorial(datos) {
  const contenedor = $('#lista-historial');
  if (!contenedor) return;

  if (!datos.historial || datos.historial.length === 0) {
    mensajeVacio(contenedor, 'Todavia no has jugado nada',
      'Elige un juego del catalogo y empieza.', 'index.php');
    return;
  }

  contenedor.innerHTML = datos.historial.map((h) => `
    <article class="juego">
      <div class="juego__portada">
        ${portada(h.url_portada, h.titulo, h.color_hex)}
        <span class="insignia insignia--consola juego__consola"
              style="background:${escapar(h.color_hex)};color:${textoSobre(h.color_hex)}">
          ${escapar(h.consola)}
        </span>
      </div>
      <div class="juego__info">
        <h3 class="juego__titulo">${escapar(h.titulo)}</h3>
        <div class="juego__meta">${escapar(h.tiempo)} · ${h.veces} partidas</div>
      </div>
      <a class="juego__enlace" href="${escapar(h.url_jugar)}">
        <span class="solo-lector">Seguir jugando ${escapar(h.titulo)}</span>
      </a>
    </article>`).join('');
}

/* =====================================================================
   2. PARTIDAS GUARDADAS
   ===================================================================== */
function pintarPartidas(datos) {
  const contenedor = $('#lista-partidas');
  if (!contenedor) return;

  $('#conteo-partidas').textContent = datos.total === 0
    ? 'Ninguna'
    : `${datos.total} ranura${datos.total === 1 ? '' : 's'}`;

  if (datos.total === 0) {
    mensajeVacio(contenedor, 'Sin partidas en la nube',
      'Mientras juegas, pulsa "Guardar partida" y aparecera aqui.', 'index.php');
    return;
  }

  contenedor.innerHTML = datos.partidas.map((s) => `
    <article class="juego" data-save="${s.id}">
      <div class="juego__portada">
        ${s.url_captura
          ? `<img src="${escapar(s.url_captura)}" alt="Captura de la partida" loading="lazy">`
          : portada(s.url_portada, s.titulo, s.color_hex)}
        <span class="insignia insignia--consola juego__consola"
              style="background:${escapar(s.color_hex)};color:${textoSobre(s.color_hex)}">
          ${s.slot === 0 ? 'Auto' : `Ranura ${s.slot}`}
        </span>
      </div>
      <div class="juego__info">
        <h3 class="juego__titulo">${escapar(s.titulo)}</h3>
        <div class="juego__meta">
          ${escapar(s.tiempo)} · ${escapar(s.peso)}
        </div>
        <div class="juego__meta text-xs">
          ${escapar(formato.relativo(s.actualizado_en))}
        </div>
        <div class="flex gap-sm" style="margin-top:9px">
          <a class="btn btn--sm btn--acento" style="flex:1"
             href="${escapar(s.url_continuar)}">Continuar</a>
          <button type="button" class="btn btn--sm btn--peligro"
                  data-borrar-save="${s.id}"
                  aria-label="Borrar partida de ${escapar(s.titulo)}">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
              <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>
            </svg>
          </button>
        </div>
      </div>
    </article>`).join('');
}

/* Borrado delegado: sobrevive al repintado de la lista. */
$('#lista-partidas')?.addEventListener('click', async (e) => {
  const boton = e.target.closest('[data-borrar-save]');
  if (!boton) return;

  const ok = await confirmar({
    titulo: 'Borrar partida guardada',
    mensaje: 'La partida se eliminara del servidor. Esta accion no se puede deshacer.',
    textoOk: 'Borrar',
    peligro: true,
  });
  if (!ok) return;

  boton.disabled = true;
  try {
    const r = await api.saves.eliminar(Number(boton.dataset.borrarSave));
    avisar(r.mensaje ?? 'Partida borrada.', 'exito');
    cargarTodo();
  } catch (err) {
    boton.disabled = false;
    avisar(err.message, 'error');
  }
});

/* =====================================================================
   3. FAVORITOS
   ===================================================================== */
function pintarFavoritos(datos) {
  const contenedor = $('#lista-favoritos');
  if (!contenedor) return;

  if (!datos.favoritos || datos.favoritos.length === 0) {
    mensajeVacio(contenedor, 'Sin favoritos',
      'Pulsa el corazon en cualquier juego del catalogo.', 'index.php');
    return;
  }

  contenedor.innerHTML = datos.favoritos.map((f) => `
    <article class="juego">
      <div class="juego__portada">
        ${portada(f.url_portada, f.titulo, f.color_hex)}
        <span class="insignia insignia--consola juego__consola"
              style="background:${escapar(f.color_hex)};color:${textoSobre(f.color_hex)}">
          ${escapar(f.consola)}
        </span>
        <button type="button" class="juego__fav" data-quitar-fav="${f.id}"
                aria-pressed="true" aria-label="Quitar de favoritos">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"
               stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M19 14c1.5-1.5 3-3.3 3-5.5A5.5 5.5 0 0 0 12 5.5 5.5 5.5 0 0 0 2 8.5c0 2.2 1.5 4 3 5.5l7 7z"/>
          </svg>
        </button>
      </div>
      <div class="juego__info">
        <h3 class="juego__titulo">${escapar(f.titulo)}</h3>
        <div class="juego__meta">${f.anio ?? ''}</div>
      </div>
      <a class="juego__enlace" href="${escapar(f.url_jugar)}">
        <span class="solo-lector">Jugar ${escapar(f.titulo)}</span>
      </a>
    </article>`).join('');
}

$('#lista-favoritos')?.addEventListener('click', async (e) => {
  const boton = e.target.closest('[data-quitar-fav]');
  if (!boton) return;

  e.preventDefault();
  e.stopPropagation();
  boton.disabled = true;

  try {
    await api.favoritos.alternar(Number(boton.dataset.quitarFav));
    boton.closest('.juego')?.remove();

    if ($('#lista-favoritos').querySelectorAll('.juego').length === 0) {
      pintarFavoritos({ favoritos: [] });
    }
    avisar('Quitado de favoritos.', 'info', 2000);
  } catch (err) {
    boton.disabled = false;
    avisar(err.message, 'error');
  }
});

/* =====================================================================
   4. CARGA
   ===================================================================== */
async function cargarTodo() {
  const [perfil, partidas, favoritos] = await Promise.allSettled([
    api.stats.perfil(),
    api.saves.misPartidas(),
    api.favoritos.listar(),
  ]);

  if (perfil.status === 'fulfilled') {
    pintarMetricas(perfil.value);
    pintarLogros(perfil.value);
    pintarHistorial(perfil.value);
  } else {
    $('#metricas').innerHTML =
      `<div class="aviso aviso--error" style="grid-column:1/-1">
         <div class="aviso__cuerpo">${escapar(perfil.reason.message)}</div>
       </div>`;
  }

  if (partidas.status === 'fulfilled') {
    pintarPartidas(partidas.value);
  } else {
    mensajeVacio($('#lista-partidas'), 'Error',
      partidas.reason.message);
  }

  if (favoritos.status === 'fulfilled') {
    pintarFavoritos(favoritos.value);
  } else {
    mensajeVacio($('#lista-favoritos'), 'Error', favoritos.reason.message);
  }
}

cargarTodo();

/* =====================================================================
   5. MODAL DE CAMBIO DE CONTRASENA
   ===================================================================== */
const modal = $('#modal-password');
const formPassword = $('#form-password');
let focoPrevio = null;

function abrirModal() {
  focoPrevio = document.activeElement;
  modal.hidden = false;
  $('#pw-actual')?.focus();
}

function cerrarModal() {
  modal.hidden = true;
  formPassword?.reset();
  pintarErrores(formPassword, {});
  focoPrevio?.focus?.();
}

$('#btn-abrir-password')?.addEventListener('click', abrirModal);

modal?.addEventListener('click', (e) => {
  if (e.target === modal || e.target.closest('[data-cerrar-modal]')) {
    cerrarModal();
  }
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && modal && !modal.hidden) cerrarModal();
});

formPassword?.addEventListener('submit', async (e) => {
  e.preventDefault();
  pintarErrores(formPassword, {});

  const actual = formPassword.actual.value;
  const nueva  = formPassword.nueva.value;
  const nueva2 = formPassword.nueva2.value;

  if (nueva !== nueva2) {
    pintarErrores(formPassword, { nueva2: 'Las contrasenas no coinciden.' });
    return;
  }

  const boton = formPassword.querySelector('button[type="submit"]');
  cargando(boton, true, 'Guardando...');

  try {
    const r = await api.auth.cambiarPassword(actual, nueva, nueva2);
    cargando(boton, false);
    cerrarModal();
    avisar(r.mensaje ?? 'Contrasena actualizada.', 'exito');
  } catch (err) {
    cargando(boton, false);
    if (err instanceof ErrorApi && err.esValidacion) {
      pintarErrores(formPassword, err.campos);
      if (Object.keys(err.campos).length === 0) avisar(err.message, 'error');
    } else {
      pintarErrores(formPassword, { actual: err.message });
    }
  }
});
