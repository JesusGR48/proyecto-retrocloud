/**
 * RetroCloud · Asistente flotante (cliente)
 * =====================================================================
 * Burbuja de ayuda abajo a la derecha. Habla con /api/asistente.php,
 * que entiende lo que se le pide y busca en el catalogo.
 *
 * El widget se inyecta a si mismo: no hace falta añadir marcado a cada
 * pagina, basta con cargar este modulo desde el pie. Asi vive en todo
 * el sitio sin tocar ninguna plantilla.
 *
 * Lo que NO hace, a proposito:
 *   · No se carga en el reproductor (jugar.php). Un boton flotante
 *     encima del juego estorba, y en el movil tapa los controles.
 *   · No guarda la conversacion. Al recargar, empieza de cero: no hay
 *     nada que valga la pena persistir y asi tampoco hay datos del
 *     usuario tirados en el navegador.
 */

'use strict';

const base = window.RETROCLOUD_BASE ?? '';

/* El reproductor no lleva asistente. */
if (!/\/jugar\.php/.test(window.location.pathname)) {
  arrancar();
}

function arrancar() {
  const CLAVE_PISTA = 'retrocloud:asistente-pista-vista';

  /** Escapa texto antes de meterlo en HTML. Defensa contra XSS. */
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  // -------------------------------------------------------------------
  // Marcado
  // -------------------------------------------------------------------
  const raiz = document.createElement('div');
  raiz.className = 'asis';
  raiz.innerHTML = `
    <div class="asis__pista" id="asis-pista" hidden>
      <button type="button" class="asis__pista-cerrar" aria-label="Cerrar sugerencia">&times;</button>
      ¿Necesitas ayuda para escoger un juego?
    </div>

    <button type="button" class="asis__boton" id="asis-boton"
            aria-expanded="false" aria-controls="asis-panel"
            aria-label="Abrir el asistente de catalogo">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
      </svg>
    </button>

    <section class="asis__panel" id="asis-panel" hidden
             role="dialog" aria-label="Asistente de catalogo">

      <header class="asis__cabecera">
        <div>
          <strong>Asistente</strong>
          <span class="asis__estado">Te ayudo a escoger</span>
        </div>
        <button type="button" class="asis__cerrar" id="asis-cerrar"
                aria-label="Cerrar el asistente">&times;</button>
      </header>

      <div class="asis__hilo" id="asis-hilo" role="log" aria-live="polite"></div>

      <div class="asis__opciones" id="asis-opciones"></div>

      <form class="asis__form" id="asis-form">
        <input type="text" id="asis-entrada" autocomplete="off"
               placeholder="Escribe: peleas, PlayStation, dos jugadores..."
               aria-label="Escribe tu peticion" maxlength="200">
        <button type="submit" class="asis__enviar" aria-label="Enviar">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/>
          </svg>
        </button>
      </form>

      <p class="asis__pie">
        Busca en el catalogo real. No inventa juegos.
      </p>
    </section>`;

  document.body.appendChild(raiz);

  const $ = (id) => raiz.querySelector('#' + id);
  const boton    = $('asis-boton');
  const panel    = $('asis-panel');
  const hilo     = $('asis-hilo');
  const opciones = $('asis-opciones');
  const form     = $('asis-form');
  const entrada  = $('asis-entrada');
  const pista    = $('asis-pista');

  let filtros = {};
  let arrancado = false;
  let enVuelo = false;

  // -------------------------------------------------------------------
  // Pintado
  // -------------------------------------------------------------------

  function burbuja(texto, quien) {
    const div = document.createElement('div');
    div.className = 'asis__msg asis__msg--' + quien;
    div.textContent = texto;
    hilo.appendChild(div);
    alFinal();
    return div;
  }

  function pensando() {
    const div = document.createElement('div');
    div.className = 'asis__msg asis__msg--bot asis__pensando';
    div.innerHTML = '<i></i><i></i><i></i>';
    hilo.appendChild(div);
    alFinal();
    return div;
  }

  function pintarJuegos(juegos) {
    if (!juegos.length) return;

    const cont = document.createElement('div');
    cont.className = 'asis__juegos';
    cont.innerHTML = juegos.map((j) => {
      const meta = [j.consola, j.anio].filter(Boolean).map(esc).join(' · ');
      const pct = (j.afinidad === null || j.afinidad === undefined)
        ? ''
        : `<span class="asis__pct">${esc(String(j.afinidad))}%</span>`;

      return `
        <a class="asis__juego" href="${esc(base + 'jugar.php?id=' + j.id)}">
          <span class="asis__juego-txt">
            <strong>${esc(j.titulo)}</strong>
            <span>${meta}</span>
          </span>
          ${pct}
        </a>`;
    }).join('');

    hilo.appendChild(cont);
    alFinal();
  }

  function pintarOpciones(lista) {
    opciones.innerHTML = '';
    for (const o of lista ?? []) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'asis__chip';
      b.textContent = o.etiqueta;
      b.addEventListener('click', () => enviar(o.enviar, o.etiqueta));
      opciones.appendChild(b);
    }
  }

  /* Solo se mueve el scroll interno del hilo. Igual que en el
     laboratorio: scrollIntoView() arrastraria la pagina entera. */
  function alFinal() {
    hilo.scrollTop = hilo.scrollHeight;
  }

  // -------------------------------------------------------------------
  // Conversacion
  // -------------------------------------------------------------------

  async function enviar(mensaje, etiquetaVisible) {
    if (enVuelo) return;
    enVuelo = true;

    if (etiquetaVisible !== '') {
      burbuja(etiquetaVisible ?? mensaje, 'yo');
    }
    opciones.innerHTML = '';
    const espera = pensando();

    let json;
    try {
      const res = await fetch(base + 'api/asistente.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ mensaje, filtros }),
      });
      json = await res.json();
    } catch (e) {
      json = null;
    }

    espera.remove();
    enVuelo = false;

    if (!json?.ok) {
      burbuja(
        json?.error ?? 'No pude conectar con el servidor. Intenta de nuevo.',
        'bot'
      );
      pintarOpciones([{ etiqueta: 'Reintentar', enviar: mensaje }]);
      return;
    }

    const d = json.datos;
    filtros = d.filtros ?? {};
    burbuja(d.respuesta, 'bot');
    pintarJuegos(d.juegos ?? []);
    pintarOpciones(d.opciones);
  }

  // -------------------------------------------------------------------
  // Abrir y cerrar
  // -------------------------------------------------------------------

  function abrir() {
    panel.hidden = false;
    boton.setAttribute('aria-expanded', 'true');
    raiz.classList.add('is-abierto');
    ocultarPista(true);

    if (!arrancado) {
      arrancado = true;
      enviar('hola', '');   // cadena vacia: el saludo no se pinta como mensaje del usuario
    }

    /* El foco al campo solo en pantallas grandes: en el movil abriria
       el teclado de golpe y taparia la conversacion recien abierta. */
    if (window.matchMedia('(min-width: 720px)').matches) {
      setTimeout(() => entrada.focus(), 120);
    }
  }

  function cerrar() {
    panel.hidden = true;
    boton.setAttribute('aria-expanded', 'false');
    raiz.classList.remove('is-abierto');
    boton.focus();
  }

  function ocultarPista(recordar) {
    pista.hidden = true;
    if (recordar) {
      try { localStorage.setItem(CLAVE_PISTA, '1'); } catch (e) { /* bloqueado */ }
    }
  }

  boton.addEventListener('click', () => (panel.hidden ? abrir() : cerrar()));
  $('asis-cerrar').addEventListener('click', cerrar);
  pista.querySelector('.asis__pista-cerrar').addEventListener('click', (ev) => {
    ev.stopPropagation();
    ocultarPista(true);
  });
  pista.addEventListener('click', abrir);

  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && !panel.hidden) cerrar();
  });

  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    const txt = entrada.value.trim();
    if (!txt) return;
    entrada.value = '';
    enviar(txt);
  });

  /* La pista aparece una sola vez por navegador, y con retraso: saltar
     encima del usuario nada mas cargar es molesto. */
  let yaVista = false;
  try { yaVista = localStorage.getItem(CLAVE_PISTA) === '1'; } catch (e) { /* bloqueado */ }

  if (!yaVista) {
    setTimeout(() => {
      if (panel.hidden) pista.hidden = false;
    }, 2600);
  }
}
