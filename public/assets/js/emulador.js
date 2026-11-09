/* =====================================================================
   RetroCloud · Reproductor y sincronizacion de partidas
   =====================================================================
   COMO FUNCIONA LA EMULACION (resumen para la documentacion)

   RetroCloud no ejecuta los juegos en el servidor. Usa EmulatorJS, que
   empaqueta los nucleos de emulacion del proyecto libretro/RetroArch
   compilados a WebAssembly (.wasm). El flujo es:

     1. El navegador descarga el nucleo de la consola (p. ej. fceumm
        para NES) como modulo WebAssembly.
     2. Descarga la ROM desde api/rom.php, que valida la sesion antes de
        entregar un solo byte.
     3. El nucleo corre dentro del navegador a velocidad casi nativa y
        dibuja cada fotograma en un <canvas> por WebGL.
     4. El audio sale por la Web Audio API.
     5. La entrada llega por teclado, por la Gamepad API (mandos de
        Xbox, PlayStation y genericos) o por el mando virtual tactil que
        EmulatorJS dibuja encima del canvas en moviles.

   LO QUE APORTA RETROCLOUD: el estado del emulador se puede volcar a un
   arreglo de bytes (saveState) y restaurar despues (loadState). Ese
   volcado se sube a nuestro backend PHP y se guarda en MySQL + disco,
   de modo que la partida se recupera desde cualquier dispositivo.
   ===================================================================== */

'use strict';

import { api, avisar, cargando, confirmar, escapar, formato } from './api.js';

/* ---------------------------------------------------------------------
   Configuracion que la pagina jugar.php deja en el HTML
   --------------------------------------------------------------------- */
const config = JSON.parse(
  document.getElementById('config-juego')?.textContent ?? '{}'
);

const {
  juegoId   = 0,
  titulo    = 'Juego',
  core      = 'nes',
  urlRom    = '',
  rutaDatos = 'https://cdn.emulatorjs.org/stable/data/',
  slotInicial = null,
  // Firmware de la consola. Vease la seccion 1 para el detalle.
  urlBios       = null,
  biosRequerido = false,
  consola       = 'la consola',
  romBytes      = 0,
  romPeso       = '',
} = config;

/* ---------------------------------------------------------------------
   Estado del reproductor
   --------------------------------------------------------------------- */
const estado = {
  listo: false,
  sesionId: null,
  segundosJugados: 0,
  relojJuego: null,
  relojLatido: null,
  relojAuto: null,
  guardando: false,
  ranuras: [],
};

/* Referencias al DOM */
const $ = (sel) => document.querySelector(sel);

const dom = {
  cargando:  $('#cargando'),
  progreso:  $('#progreso-relleno'),
  mensaje:   $('#mensaje-carga'),
  ranuras:   $('#lista-ranuras'),
  mando:     $('#indicador-mando'),
  cronometro: $('#cronometro'),
};

/* =====================================================================
   1. ARRANQUE DEL EMULADOR
   ===================================================================== */

function arrancarEmulador() {
  // EmulatorJS se configura mediante variables globales que lee su
  // cargador al inicializarse. No es la API mas elegante, pero es la
  // que expone la libreria.
  window.EJS_player     = '#juego';
  window.EJS_core       = core;
  window.EJS_gameUrl    = urlRom;
  window.EJS_pathtodata = rutaDatos;
  window.EJS_gameName   = titulo;
  window.EJS_language   = 'es-ES';

  /*
   * Limite para guardar la ROM en la base de datos del navegador.
   *
   * EmulatorJS cachea en IndexedDB toda ROM menor de 1 GB (ese es su
   * valor por omision). La intencion es buena: la segunda vez que
   * juegas no hay que descargarla. El problema es el precio en un
   * telefono, donde acaba habiendo DOS copias del mismo archivo: la que
   * usa el emulador en memoria y la guardada en el navegador. Con un
   * romset de arcade de 80 MB o un disco de PlayStation de 600 MB eso
   * basta para que el sistema mate la pestana, que se recarga sola a
   * mitad de partida.
   *
   * Con el tope en 32 MB se queda lo mejor de los dos lados: los
   * cartuchos pequenos (NES, SNES, Game Boy, GBA) se siguen cacheando y
   * cargan al instante, y los pesados se descargan cada vez pero no
   * duplican memoria.
   */
  window.EJS_cacheLimit = 32 * 1024 * 1024;

  /*
   * Firmware de sistema.
   *
   * Solo se declara cuando la consola tiene una cargada. Si se pasara
   * una URL vacia, EmulatorJS intentaria descargarla igualmente y
   * fallaria con un error poco claro, asi que es mejor no definir la
   * variable que definirla a null.
   */
  if (urlBios) {
    window.EJS_biosUrl = urlBios;

    /*
     * EmulatorJS DESCOMPRIME el BIOS por defecto y escribe sus archivos
     * sueltos en el sistema de archivos del emulador.
     *
     * Eso le va bien a consolas cuyo BIOS es un volcado unico (la .bin
     * de PlayStation, por ejemplo), pero rompe los nucleos de arcade:
     * FBNeo carga los romsets DESDE EL ZIP y busca `neogeo.zip` como
     * archivo. Si lo recibe descomprimido no encuentra el romset y
     * responde que le faltan sp-s3.sp1, sm1.sm1, sfix.sfix y 000-lo.lo
     * (que son justamente los que van dentro de ese zip).
     *
     * Con dontExtractBIOS el archivo se conserva intacto y con su
     * nombre, que es como FBNeo lo espera.
     */
    const nucleosQueQuierenElZip = ['arcade', 'mame2003', 'fbneo', 'neogeo'];
    if (nucleosQueQuierenElZip.includes(core)) {
      window.EJS_dontExtractBIOS = true;
    }
  }

  // No arrancar solo: queremos que el usuario pulse para que el
  // navegador permita el audio (politica de autoplay).
  window.EJS_startOnLoaded = false;

  // Botones de la barra del emulador. Desactivamos los de guardado
  // local porque RetroCloud ofrece los suyos contra el servidor.
  window.EJS_Buttons = {
    playPause:      true,
    restart:        true,
    mute:           true,
    settings:       true,
    /*
     * Se desactiva el boton de pantalla completa de EmulatorJS.
     *
     * Tenia el suyo en el menu hamburguesa y RetroCloud tiene el propio,
     * y eso creaba dos problemas:
     *
     *  1. El de EmulatorJS amplia SU contenedor, no nuestro #escenario,
     *     asi que nuestro boton flotante de "Salir" nunca llegaba a
     *     mostrarse y el usuario se quedaba sin forma visible de volver.
     *
     *  2. En iPhone falla en silencio (Safari no amplia un <div>), de
     *     modo que parecia que el boton simplemente no hacia nada.
     *
     * Con uno solo, el nuestro, el comportamiento es predecible y en
     * iOS cae al modo simulado con su boton de salida.
     */
    fullscreen:     false,
    saveState:      false,
    loadState:      false,
    screenRecord:   false,
    gamepad:        true,     // remapeo de mandos
    cheat:          false,
    volume:         true,
    saveSavFiles:   false,
    loadSavFiles:   false,
    quickSave:      false,
    quickLoad:      false,
    screenshot:     true,
    cacheManager:   false,
    exitEmulation:  false,
  };

  // Mando virtual tactil: se dibuja solo en pantallas con tactil.
  window.EJS_VirtualGamepadSettings = undefined;  // deja los valores por defecto

  // Callback de progreso de descarga del nucleo y la ROM.
  window.EJS_onLoadSetting = () => {};

  /* El cargador llama a EJS_ready cuando el nucleo ya esta en memoria. */
  window.EJS_ready = () => {
    estado.listo = true;
    ocultarCargando();
    detectarMandos();
    cargarRanuras();
    // Ya existe el contenedor .ejs_game, asi que se puede decorar.
    dibujarDestellos();
    avisarJuegoPesadoEnMovil();

    // Si llegamos con ?slot=N, ofrecemos continuar esa partida.
    if (slotInicial !== null) {
      setTimeout(() => ofrecerContinuar(Number(slotInicial)), 700);
    }
  };

  /* Se dispara cuando el juego empieza a correr de verdad. */
  window.EJS_onGameStart = () => {
    // Se apagan los destellos: a partir de aqui la CPU es del emulador.
    detenerDestellos();
    iniciarSesionDeJuego();
    arrancarCronometro();
    programarAutoguardado();
  };

  /* Carga del script del cargador de EmulatorJS. */
  const script = document.createElement('script');
  script.src = rutaDatos + 'loader.js';
  script.async = true;

  /*
   * onerror no distingue la causa: un fallo de red y un bloqueo por
   * Content-Security-Policy llegan igual. Por eso el mensaje enumera
   * las posibilidades en lugar de afirmar una, y recuerda que la
   * consola del navegador dice cual fue.
   */
  script.onerror = () => {
    mostrarFalloCarga(
      'No se pudo cargar el motor de emulacion.',
      rutaDatos.startsWith('http')
        ? 'El navegador no pudo descargar los nucleos del CDN de EmulatorJS. '
          + 'Puede ser falta de conexion, un bloqueador de anuncios, o que la '
          + 'Content-Security-Policy del servidor no autorice ese origen. '
          + 'La consola del navegador (F12) indica la causa exacta.'
        : 'No se encontro la instalacion local de EmulatorJS. '
          + 'Debe existir el archivo public/emulatorjs/data/loader.js.'
    );
  };

  document.body.appendChild(script);

  // Vigila el texto de carga de EmulatorJS para alimentar la barra de
  // progreso real (la que se ve una vez pulsado "Iniciar juego").
  engancharProgresoEmulador();

  // Barra de progreso simulada: EmulatorJS no expone un evento de
  // progreso fiable, asi que avanzamos de forma asintotica hasta que
  // EJS_ready la completa. Es honesto en lo que comunica (actividad),
  // no finge conocer el porcentaje exacto.
  let pct = 0;
  const avance = setInterval(() => {
    if (estado.listo) {
      clearInterval(avance);
      return;
    }
    pct = Math.min(92, pct + (92 - pct) * 0.08 + 0.4);
    if (dom.progreso) dom.progreso.style.width = `${pct}%`;
  }, 220);
}

function ocultarCargando() {
  if (dom.progreso) dom.progreso.style.width = '100%';
  setTimeout(() => dom.cargando?.setAttribute('hidden', ''), 260);
}

/* ---------------------------------------------------------------------
   Barra de progreso del emulador
   ---------------------------------------------------------------------
   EmulatorJS SI calcula el porcentaje de descarga, pero lo unico que
   hace con el es escribirlo dentro de su texto de carga:
   "Descargando BIOS del Juego 42%". No expone ningun evento ni ninguna
   propiedad publica con ese dato.

   Asi que se lee de donde esta: un MutationObserver vigila el texto y,
   cada vez que cambia, extrae el numero y mueve la barra. Observar el
   DOM es la via razonable cuando una libreria no ofrece un gancho; es
   barato porque solo reacciona a cambios reales.

   Cuando el texto no trae porcentaje (al descomprimir, por ejemplo) la
   barra pasa a modo indeterminado y recorre de lado a lado, en vez de
   quedarse clavada en cero aparentando que nada avanza.
   --------------------------------------------------------------------- */
/* ---------------------------------------------------------------------
   Destellos de la pantalla de arranque
   ---------------------------------------------------------------------
   Particulas blancas que flotan y parpadean sobre el fondo de colores,
   dibujadas en un <canvas> propio. Escrito a mano y sin dependencias:
   las librerias de particulas que circulan por ahi vienen atadas a
   React, Tailwind y un empaquetador, que es justo lo que este proyecto
   no usa. Son unas setenta lineas, no compensaba.

   Tres decisiones que importan:

     · Se detiene en cuanto arranca el juego. Un bucle de animacion
       compitiendo con el emulador le roba fotogramas, y el emulador los
       necesita todos.
     · Respeta `prefers-reduced-motion`: a quien pidio menos movimiento
       en su sistema no se le dibuja nada.
     · Las posiciones se guardan de 0 a 1 y se multiplican por el tamano
       al pintar, asi un cambio de tamano no descoloca las particulas.
   --------------------------------------------------------------------- */
let detenerDestellos = () => {};

function dibujarDestellos() {
  const contenedor = $('#juego')?.querySelector('.ejs_game');
  if (!contenedor || contenedor.querySelector('.destellos')) return;

  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

  const lienzo = document.createElement('canvas');
  lienzo.className = 'destellos';
  lienzo.setAttribute('aria-hidden', 'true');
  contenedor.appendChild(lienzo);

  const ctx = lienzo.getContext('2d');
  if (!ctx) return;

  // En pantallas de alta densidad se dibuja al doble para que no se vea
  // pixelado, pero se corta ahi: mas resolucion no se nota y cuesta.
  const dpr = Math.min(window.devicePixelRatio || 1, 2);

  let ancho = 0;
  let alto = 0;
  let particulas = [];
  let animacion = null;

  const crear = (cuantas) => Array.from({ length: cuantas }, () => ({
    x: Math.random(),
    y: Math.random(),
    radio: 0.4 + Math.random() * 1.4,
    vx: (Math.random() - 0.5) * 0.00018,
    vy: -(0.00012 + Math.random() * 0.00045),   // suben despacio
    fase: Math.random() * Math.PI * 2,
    ritmo: 0.6 + Math.random() * 1.8,           // velocidad del parpadeo
  }));

  const medir = () => {
    const caja = contenedor.getBoundingClientRect();
    ancho = Math.max(1, Math.round(caja.width));
    alto  = Math.max(1, Math.round(caja.height));

    lienzo.width  = ancho * dpr;
    lienzo.height = alto * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    // Densidad proporcional al area, con topes para que ni se vea vacio
    // ni se dispare en pantallas grandes.
    const cuantas = Math.round(Math.min(200, Math.max(45, (ancho * alto) / 5200)));
    if (particulas.length !== cuantas) particulas = crear(cuantas);
  };

  const inicio = performance.now();

  const pintar = (ahora) => {
    const t = (ahora - inicio) / 1000;
    ctx.clearRect(0, 0, ancho, alto);
    ctx.fillStyle = '#ffffff';

    for (const p of particulas) {
      p.x += p.vx;
      p.y += p.vy;

      // Reaparecen por abajo al salir por arriba.
      if (p.y < -0.03) { p.y = 1.03; p.x = Math.random(); }
      if (p.x < -0.03) p.x = 1.03;
      if (p.x > 1.03)  p.x = -0.03;

      // Parpadeo: una onda suave entre casi apagado y brillante.
      ctx.globalAlpha = 0.18 + 0.72 * (0.5 + 0.5 * Math.sin(t * p.ritmo + p.fase));
      ctx.beginPath();
      ctx.arc(p.x * ancho, p.y * alto, p.radio, 0, Math.PI * 2);
      ctx.fill();
    }

    ctx.globalAlpha = 1;
    animacion = requestAnimationFrame(pintar);
  };

  medir();
  animacion = requestAnimationFrame(pintar);

  const observador = new ResizeObserver(medir);
  observador.observe(contenedor);

  detenerDestellos = () => {
    if (animacion !== null) cancelAnimationFrame(animacion);
    animacion = null;
    observador.disconnect();
    lienzo.remove();
    detenerDestellos = () => {};
  };
}

/* ---------------------------------------------------------------------
   Aviso de juego pesado en moviles
   ---------------------------------------------------------------------
   Un telefono tiene bastante menos memoria disponible por pestana que
   una computadora, y el navegador la mata sin preguntar cuando se pasa.
   Visto desde fuera parece un fallo aleatorio: el juego descarga todo
   pero no arranca, o se recarga solo a mitad de partida.

   No hay forma de evitarlo desde aqui -el emulador necesita la memoria
   que necesita-, asi que al menos se avisa y se recuerda guardar. Mas
   vale explicar un limite que dejar al usuario pensando que la pagina
   esta rota.
   --------------------------------------------------------------------- */
const LIMITE_COMODO_MOVIL = 60 * 1024 * 1024;   // 60 MB

function avisarJuegoPesadoEnMovil() {
  if (romBytes <= LIMITE_COMODO_MOVIL) return;

  // Pantalla tactil sin raton: la senal mas fiable de telefono o tableta.
  const esMovil = window.matchMedia?.('(hover: none) and (pointer: coarse)').matches;
  if (!esMovil) return;

  setTimeout(() => {
    avisar(
      `Este juego ocupa ${romPeso}. En telefono puede tardar o recargarse `
      + 'solo si falta memoria. Guarda tu partida a menudo.',
      'info',
      8000
    );
  }, 2000);
}

function engancharProgresoEmulador() {
  const escenario = $('#escenario');
  const juego = $('#juego');
  if (!escenario || !juego) return;

  const caja = document.createElement('div');
  caja.className = 'progreso-emulador';
  caja.innerHTML = `
    <div class="progreso-emulador__pista">
      <div class="progreso-emulador__relleno"></div>
    </div>
    <div class="progreso-emulador__cifra"></div>`;
  escenario.appendChild(caja);

  const relleno = caja.querySelector('.progreso-emulador__relleno');
  const cifra   = caja.querySelector('.progreso-emulador__cifra');

  const actualizar = () => {
    const texto = juego.querySelector('.ejs_loading_text');

    // Sin texto de carga no hay nada que mostrar: el juego ya arranco
    // (EmulatorJS elimina ese elemento al empezar) o aun no se ha
    // pulsado "Iniciar juego".
    if (!texto) {
      caja.classList.remove('visible', 'indeterminada');
      return;
    }

    caja.classList.add('visible');

    const encontrado = (texto.textContent || '').match(/(\d{1,3})\s*%/);

    if (encontrado) {
      const pct = Math.min(100, Math.max(0, Number(encontrado[1])));
      caja.classList.remove('indeterminada');
      relleno.style.width = `${pct}%`;
      cifra.textContent = `${pct}%`;
    } else {
      caja.classList.add('indeterminada');
      relleno.style.width = '';   // deja mandar a la animacion del CSS
      cifra.textContent = '';
    }
  };

  new MutationObserver(actualizar).observe(juego, {
    childList: true,
    subtree: true,
    characterData: true,
  });

  actualizar();
}

function mostrarFalloCarga(titulo_, detalle) {
  if (!dom.cargando) return;
  dom.cargando.removeAttribute('hidden');
  dom.cargando.innerHTML = `
    <div class="contenido">
      <div class="vacio__icono" style="color:var(--error)">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round"><path d="M12 9v4m0 4h.01"/>
          <circle cx="12" cy="12" r="9"/></svg>
      </div>
      <h3 style="margin:0">${escapar(titulo_)}</h3>
      <p class="text-tenue text-sm" style="max-width:40ch;margin:0">${escapar(detalle)}</p>
      <button type="button" class="btn btn--primario" onclick="location.reload()">
        Reintentar
      </button>
    </div>`;
}

/* =====================================================================
   2. ACCESO AL EMULADOR

   La instancia vive en window.EJS_emulator. Las versiones de EmulatorJS
   han movido de sitio los metodos de guardado, asi que en lugar de
   asumir una ruta concreta probamos las conocidas en orden. Es mas
   codigo, pero sobrevive a una actualizacion del CDN.
   ===================================================================== */

function emulador() {
  return window.EJS_emulator ?? null;
}

function enMarcha() {
  const e = emulador();
  return !!(e && (e.gameManager || e.started));
}

/** Obtiene el volcado binario del estado actual. */
async function volcarEstado() {
  const e = emulador();
  if (!e) throw new Error('El emulador todavia no esta listo.');

  const candidatos = [
    () => e.gameManager?.getState?.(),
    () => e.gameManager?.saveState?.(),
    () => e.getState?.(),
    () => e.saveState?.(),
  ];

  for (const intento of candidatos) {
    try {
      const r = await intento();
      if (r && (r.byteLength > 0 || r.length > 0)) {
        return r instanceof Uint8Array ? r : new Uint8Array(r);
      }
    } catch {
      /* probamos el siguiente */
    }
  }

  throw new Error('Este nucleo no permite guardar el estado de la partida.');
}

/** Restaura un volcado binario. */
async function restaurarEstado(bytes) {
  const e = emulador();
  if (!e) throw new Error('El emulador todavia no esta listo.');

  const datos = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);

  const candidatos = [
    () => e.gameManager?.loadState?.(datos),
    () => e.loadState?.(datos),
    () => e.gameManager?.setState?.(datos),
  ];

  for (const intento of candidatos) {
    try {
      const r = intento();
      if (r !== undefined || true) {
        await r;
        return true;
      }
    } catch {
      /* siguiente */
    }
  }

  throw new Error('No se pudo restaurar la partida en este nucleo.');
}

/**
 * Captura una miniatura del fotograma actual.
 *
 * El canvas de WebGL borra su buffer tras cada pintado, asi que
 * toDataURL() puede devolver una imagen en negro. Lo intentamos y, si
 * sale vacio, devolvemos null en lugar de guardar una miniatura inutil.
 */
function capturarMiniatura() {
  try {
    const e = emulador();
    const canvas = e?.canvas ?? document.querySelector('#juego canvas');
    if (!canvas || !canvas.width) return null;

    // Reescalamos a 192 px de ancho: la miniatura no necesita mas y
    // asi la peticion no carga con cientos de kilobytes de base64.
    const ancho = 192;
    const alto = Math.round((canvas.height / canvas.width) * ancho) || 144;

    const mini = document.createElement('canvas');
    mini.width = ancho;
    mini.height = alto;

    const ctx = mini.getContext('2d');
    if (!ctx) return null;
    ctx.drawImage(canvas, 0, 0, ancho, alto);

    const url = mini.toDataURL('image/png');
    // Un PNG de 192x144 completamente vacio ronda los 300 bytes.
    return url.length > 800 ? url : null;
  } catch {
    return null;
  }
}

/* =====================================================================
   3. GUARDAR Y CARGAR EN LA NUBE
   ===================================================================== */

/**
 * Sube el estado actual a la ranura indicada.
 * @param {number} slot 0 = autoguardado, 1..4 = manual
 * @param {boolean} silencioso true para el autoguardado
 */
async function guardarEnNube(slot, silencioso = false) {
  if (!enMarcha()) {
    if (!silencioso) avisar('Inicia el juego antes de guardar.', 'info');
    return;
  }
  if (estado.guardando) return;

  estado.guardando = true;
  marcarRanuraOcupada(slot, true);

  try {
    const bytes = await volcarEstado();
    const captura = capturarMiniatura();

    const r = await api.saves.guardar(juegoId, slot, bytes, {
      nombre: slot === 0 ? 'Autoguardado' : `Ranura ${slot}`,
      captura,
      segundos: estado.segundosJugados,
    });

    if (!silencioso) {
      avisar(r.mensaje ?? 'Partida guardada en la nube.', 'exito');
    }
    await cargarRanuras();
  } catch (e) {
    // El autoguardado falla en silencio: no interrumpimos el juego por
    // un problema de red pasajero.
    if (!silencioso) {
      avisar(e.message ?? 'No se pudo guardar la partida.', 'error', 6000);
    } else {
      console.warn('[RetroCloud] autoguardado fallido:', e.message);
    }
  } finally {
    estado.guardando = false;
    marcarRanuraOcupada(slot, false);
  }
}

/** Descarga una ranura del servidor y la restaura en el emulador. */
async function cargarDeNube(saveId, slot) {
  if (!enMarcha()) {
    avisar('Inicia el juego antes de cargar una partida.', 'info');
    return;
  }

  marcarRanuraOcupada(slot, true);

  try {
    const buffer = await api.saves.descargar(saveId);
    if (!buffer || buffer.byteLength === 0) {
      throw new Error('La partida guardada llego vacia.');
    }

    await restaurarEstado(buffer);
    avisar(
      slot === 0
        ? 'Autoguardado restaurado.'
        : `Partida de la ranura ${slot} restaurada.`,
      'exito'
    );
  } catch (e) {
    avisar(e.message ?? 'No se pudo cargar la partida.', 'error', 6000);
  } finally {
    marcarRanuraOcupada(slot, false);
  }
}

async function borrarDeNube(saveId, slot) {
  const ok = await confirmar({
    titulo: 'Borrar partida',
    mensaje: slot === 0
      ? 'Se borrara el autoguardado de este juego. Esta accion no se puede deshacer.'
      : `Se borrara la partida de la ranura ${slot}. Esta accion no se puede deshacer.`,
    textoOk: 'Borrar',
    peligro: true,
  });
  if (!ok) return;

  try {
    const r = await api.saves.eliminar(saveId);
    avisar(r.mensaje ?? 'Partida borrada.', 'exito');
    await cargarRanuras();
  } catch (e) {
    avisar(e.message ?? 'No se pudo borrar la partida.', 'error');
  }
}

/** Pregunta si se quiere continuar desde una ranura concreta. */
async function ofrecerContinuar(slot) {
  const r = estado.ranuras.find((x) => x.slot === slot && !x.vacia);
  if (!r) return;

  const ok = await confirmar({
    titulo: 'Continuar partida',
    mensaje: `Tienes una partida guardada en ${
      slot === 0 ? 'el autoguardado' : `la ranura ${slot}`
    } (${r.tiempo} jugados, ${r.peso}). ¿La cargamos?`,
    textoOk: 'Continuar',
  });

  if (ok) await cargarDeNube(r.id, slot);
}

/* =====================================================================
   4. INTERFAZ DE RANURAS
   ===================================================================== */

async function cargarRanuras() {
  if (!dom.ranuras) return;

  try {
    const r = await api.saves.listar(juegoId);
    estado.ranuras = r.ranuras ?? [];
    pintarRanuras();
  } catch (e) {
    dom.ranuras.innerHTML =
      `<p class="text-sm text-debil">No se pudieron cargar las ranuras: ${escapar(e.message)}</p>`;
  }
}

function pintarRanuras() {
  if (!dom.ranuras) return;

  dom.ranuras.innerHTML = estado.ranuras.map((r) => {
    const esAuto = r.slot === 0;
    const nombre = esAuto ? 'Autoguardado' : (r.nombre ?? `Ranura ${r.slot}`);

    if (r.vacia) {
      return `
        <div class="ranura ranura--vacia ${esAuto ? 'ranura--auto' : ''}" data-slot="${r.slot}">
          <div class="ranura__miniatura">vacia</div>
          <div class="ranura__cuerpo">
            <div class="ranura__nombre">${escapar(nombre)}</div>
            <div class="ranura__meta">Sin partida guardada</div>
            <div class="ranura__acciones">
              <button type="button" class="btn btn--sm btn--fantasma"
                      data-accion="guardar" data-slot="${r.slot}">
                Guardar aqui
              </button>
            </div>
          </div>
        </div>`;
    }

    const miniatura = r.url_captura
      ? `<img src="${escapar(r.url_captura)}" alt="" loading="lazy">`
      : 'sin imagen';

    return `
      <div class="ranura ${esAuto ? 'ranura--auto' : ''}" data-slot="${r.slot}">
        <div class="ranura__miniatura">${miniatura}</div>
        <div class="ranura__cuerpo">
          <div class="ranura__nombre">${escapar(nombre)}</div>
          <div class="ranura__meta">
            ${escapar(r.tiempo)} · ${escapar(r.peso)}
          </div>
          <div class="ranura__meta text-xs">
            ${escapar(formato.relativo(r.actualizado_en))}
          </div>
          <div class="ranura__acciones">
            <button type="button" class="btn btn--sm btn--acento"
                    data-accion="cargar" data-id="${r.id}" data-slot="${r.slot}">
              Cargar
            </button>
            <button type="button" class="btn btn--sm btn--fantasma"
                    data-accion="guardar" data-slot="${r.slot}">
              Sobreescribir
            </button>
            <button type="button" class="btn btn--sm btn--peligro"
                    data-accion="borrar" data-id="${r.id}" data-slot="${r.slot}"
                    aria-label="Borrar ${escapar(nombre)}">
              Borrar
            </button>
          </div>
        </div>
      </div>`;
  }).join('');
}

function marcarRanuraOcupada(slot, ocupada) {
  const nodo = dom.ranuras?.querySelector(`[data-slot="${slot}"]`);
  if (!nodo) return;
  nodo.style.opacity = ocupada ? '.55' : '';
  nodo.querySelectorAll('button').forEach((b) => { b.disabled = ocupada; });
}

/* Delegacion de eventos: un solo listener para toda la lista, que
   sigue funcionando aunque el HTML se vuelva a pintar. */
dom.ranuras?.addEventListener('click', (e) => {
  const boton = e.target.closest('[data-accion]');
  if (!boton) return;

  const slot = Number(boton.dataset.slot);
  const id = Number(boton.dataset.id);

  switch (boton.dataset.accion) {
    case 'guardar': guardarEnNube(slot); break;
    case 'cargar':  cargarDeNube(id, slot); break;
    case 'borrar':  borrarDeNube(id, slot); break;
  }
});

/* =====================================================================
   5. TELEMETRIA: SESION DE JUEGO Y CRONOMETRO
   ===================================================================== */

async function iniciarSesionDeJuego() {
  if (estado.sesionId !== null) return;
  try {
    const r = await api.sesiones.iniciar(juegoId);
    estado.sesionId = r.sesion_id;

    // Latido cada 30 s: si el usuario cierra de golpe, como maximo se
    // pierden 30 segundos de registro.
    estado.relojLatido = setInterval(() => {
      if (estado.sesionId !== null) {
        api.sesiones
          .latido(estado.sesionId, estado.segundosJugados)
          .catch(() => { /* la red va y viene; no molestamos al usuario */ });
      }
    }, 30000);
  } catch {
    /* sin telemetria el juego sigue funcionando: no es critico */
  }
}

function arrancarCronometro() {
  if (estado.relojJuego !== null) return;

  estado.relojJuego = setInterval(() => {
    // Solo contamos si la pestana esta visible: dejar el juego de fondo
    // no deberia inflar las horas jugadas.
    if (document.visibilityState === 'visible') {
      estado.segundosJugados++;
      if (dom.cronometro) {
        dom.cronometro.textContent = formato.reloj(estado.segundosJugados);
      }
    }
  }, 1000);
}

function programarAutoguardado() {
  if (estado.relojAuto !== null) return;
  // Cada 3 minutos, a la ranura 0.
  estado.relojAuto = setInterval(() => {
    if (enMarcha() && document.visibilityState === 'visible') {
      guardarEnNube(0, true);
    }
  }, 180000);
}

/** Detiene los temporizadores y cierra la sesion de juego en el servidor. */
async function cerrarSesionDeJuego() {
  clearInterval(estado.relojLatido);
  clearInterval(estado.relojAuto);
  clearInterval(estado.relojJuego);
  estado.relojLatido = null;
  estado.relojAuto = null;
  estado.relojJuego = null;

  if (estado.sesionId === null) return;

  const id = estado.sesionId;
  // Se limpia antes de esperar, para que el manejador de 'pagehide' no
  // vuelva a cerrarla y cuente la partida dos veces.
  estado.sesionId = null;

  try {
    await api.sesiones.cerrar(id, estado.segundosJugados);
  } catch {
    /* si falla, el latido ya dejo registrado el tiempo casi completo */
  }
}

/* Cierre de la sesion al abandonar la pagina.
   'pagehide' es mas fiable que 'beforeunload' en moviles, donde el
   navegador puede congelar la pestana sin dispararlo. */
window.addEventListener('pagehide', () => {
  if (estado.sesionId !== null) {
    api.sesiones.cerrarAlSalir(estado.sesionId, estado.segundosJugados);
  }
});

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') {
    /*
     * La pestana deja de verse: guardamos el progreso por si el usuario
     * no vuelve. Funciona al cambiar de pestana, al minimizar y en la
     * mayoria de las navegaciones.
     *
     * No es infalible: si se cierra el navegador de golpe, la subida
     * puede cancelarse a medias. Un volcado de estado pesa demasiado
     * para enviarlo con sendBeacon() de forma fiable, asi que la via
     * segura es el boton "Terminar partida".
     */
    if (enMarcha() && !estado.guardando) {
      guardarEnNube(0, true);
    }
    return;
  }

  // Al volver a la pestana, sincronizamos el contador.
  if (estado.sesionId !== null) {
    api.sesiones.latido(estado.sesionId, estado.segundosJugados).catch(() => {});
  }
});

/* =====================================================================
   6. MANDOS FISICOS (Gamepad API)

   EmulatorJS ya lee los mandos por su cuenta. Esta seccion existe solo
   para la interfaz: avisar al usuario de que su mando fue reconocido.
   ===================================================================== */

function detectarMandos() {
  if (!('getGamepads' in navigator)) {
    if (dom.mando) dom.mando.hidden = true;
    return;
  }

  const refrescar = () => {
    const mandos = [...navigator.getGamepads()].filter(Boolean);
    if (!dom.mando) return;

    if (mandos.length === 0) {
      dom.mando.className = 'mando';
      dom.mando.innerHTML =
        '<span class="mando__punto"></span><span>Sin mando conectado</span>';
      return;
    }

    // El id del navegador trae fabricante y producto; nos quedamos con
    // la parte legible.
    const nombre = (mandos[0].id || 'Mando')
      .replace(/\s*\([^)]*\)\s*/g, '')
      .trim()
      .slice(0, 28) || 'Mando';

    dom.mando.className = 'mando mando--activo';
    dom.mando.innerHTML =
      `<span class="mando__punto"></span><span>${escapar(nombre)}</span>` +
      (mandos.length > 1 ? `<span class="insignia">${mandos.length}</span>` : '');
  };

  window.addEventListener('gamepadconnected', (e) => {
    refrescar();
    avisar(`Mando detectado: ${e.gamepad.id.slice(0, 40)}`, 'exito');
  });

  window.addEventListener('gamepaddisconnected', () => {
    refrescar();
    avisar('Mando desconectado.', 'info');
  });

  refrescar();
  // Chrome no dispara gamepadconnected hasta que se pulsa un boton,
  // asi que sondeamos un par de veces al principio.
  setTimeout(refrescar, 1200);
  setTimeout(refrescar, 3500);
}

/* =====================================================================
   7. ATAJOS DE TECLADO
   ===================================================================== */

document.addEventListener('keydown', (e) => {
  // No secuestramos teclas mientras se escribe en un campo.
  if (e.target.matches('input, textarea, select')) return;

  // F1..F4 -> guardar en la ranura 1..4
  const guardar = { F1: 1, F2: 2, F3: 3, F4: 4 }[e.key];
  if (guardar && !e.shiftKey) {
    e.preventDefault();
    guardarEnNube(guardar);
    return;
  }

  // Shift + F1..F4 -> cargar de la ranura 1..4
  if (guardar && e.shiftKey) {
    e.preventDefault();
    const r = estado.ranuras.find((x) => x.slot === guardar && !x.vacia);
    if (r) {
      cargarDeNube(r.id, guardar);
    } else {
      avisar(`La ranura ${guardar} esta vacia.`, 'info');
    }
    return;
  }

  // F5 -> autoguardado manual
  if (e.key === 'F5') {
    e.preventDefault();
    guardarEnNube(0);
  }
});

/* =====================================================================
   8. BOTONES DE LA BARRA DE ACCIONES
   ===================================================================== */

$('#btn-guardar-rapido')?.addEventListener('click', () => guardarEnNube(1));

$('#btn-cargar-rapido')?.addEventListener('click', () => {
  const r = estado.ranuras.find((x) => x.slot === 1 && !x.vacia)
         ?? estado.ranuras.find((x) => !x.vacia);
  if (r) {
    cargarDeNube(r.id, r.slot);
  } else {
    avisar('Todavia no tienes partidas guardadas de este juego.', 'info');
  }
});

/**
 * Termina la sesion de forma ordenada: pausa, guarda el progreso en el
 * autoguardado, cierra la sesion en el servidor y vuelve al catalogo.
 *
 * Es la via fiable para salir. El guardado automatico al ocultar la
 * pestana cubre el caso habitual, pero cerrar el navegador de golpe
 * puede cortar la subida a medias.
 */
async function terminarPartida() {
  if (!enMarcha()) {
    location.href = 'index.php';
    return;
  }

  const ok = await confirmar({
    titulo: 'Terminar partida',
    mensaje: 'Se guardara tu progreso en la ranura de autoguardado y volveras '
           + 'al catalogo. Podras continuar luego desde "Mis partidas".',
    textoOk: 'Guardar y salir',
  });
  if (!ok) return;

  const boton = $('#btn-terminar');
  cargando(boton, true, 'Guardando...');

  // Pausa el emulador para que el estado no cambie mientras se vuelca.
  try {
    emulador()?.pause?.();
  } catch {
    /* no todas las versiones exponen pause(); no es critico */
  }

  // El aviso al usuario lo damos aqui, por eso el guardado va silencioso.
  await guardarEnNube(0, true);
  await cerrarSesionDeJuego();

  avisar('Progreso guardado. Puedes continuar cuando quieras.', 'exito', 2600);
  setTimeout(() => { location.href = 'index.php'; }, 700);
}

$('#btn-terminar')?.addEventListener('click', terminarPartida);

/* ---------------------------------------------------------------------
   Pantalla completa
   ---------------------------------------------------------------------
   Tres problemas que hay que cubrir en moviles:

     1. Prefijos. Safari y los Android antiguos exponen el metodo como
        webkitRequestFullscreen, no como requestFullscreen. La version
        anterior solo probaba el nombre estandar y por eso el boton no
        hacia nada en el telefono.

     2. Safari en iPhone NO permite poner un <div> en pantalla completa:
        solo acepta elementos <video>. Ahi no queda otra que simularla
        con CSS (clase .pantalla-completa-simulada).

     3. La peticion debe salir del gesto del usuario, asi que se lanza
        de forma sincrona dentro del manejador del clic. Si se retrasa
        con un await previo, el navegador la rechaza.
   --------------------------------------------------------------------- */

/** Elemento en pantalla completa, probando los distintos prefijos. */
function elementoPantallaCompleta() {
  return document.fullscreenElement
      ?? document.webkitFullscreenElement
      ?? document.msFullscreenElement
      ?? null;
}

function salirPantallaCompleta() {
  const salir = document.exitFullscreen
             ?? document.webkitExitFullscreen
             ?? document.msExitFullscreen;
  if (salir) salir.call(document);
}

/* ---------------------------------------------------------------------
   Autoocultado del boton "Salir"
   ---------------------------------------------------------------------
   Un boton siempre visible sobre el juego se acaba pulsando sin querer.
   Se sigue el patron de los reproductores de video: aparece unos
   segundos al entrar en pantalla completa y cada vez que se toca la
   pantalla, y despues se desvanece.

   Mientras esta oculto lleva pointer-events: none (lo pone el CSS), de
   modo que el dedo atraviesa y llega a los controles del juego.
   --------------------------------------------------------------------- */
const SEGUNDOS_SALIDA_VISIBLE = 4000;
let relojSalida = null;

function mostrarBotonSalir() {
  const boton = $('#btn-salir-pantalla');
  const escenario = $('#escenario');
  if (!boton || !escenario) return;

  // Solo tiene sentido en pantalla completa.
  const ampliado = elementoPantallaCompleta() === escenario
    || escenario.classList.contains('pantalla-completa-simulada');
  if (!ampliado) return;

  boton.classList.add('visible');
  clearTimeout(relojSalida);
  relojSalida = setTimeout(() => boton.classList.remove('visible'), SEGUNDOS_SALIDA_VISIBLE);
}

function ocultarBotonSalir() {
  clearTimeout(relojSalida);
  $('#btn-salir-pantalla')?.classList.remove('visible');
}

/* Cualquier toque o clic sobre el escenario lo vuelve a mostrar.
   El listener es pasivo y no cancela el evento: el toque sigue su
   camino hacia los controles tactiles del emulador. */
$('#escenario')?.addEventListener('pointerdown', mostrarBotonSalir, { passive: true });

function activarSimulada(escenario, avisarUsuario = false) {
  escenario.classList.add('pantalla-completa-simulada');
  document.body.classList.add('sin-scroll');
  mostrarBotonSalir();
  if (avisarUsuario) {
    avisar('Pantalla completa simulada: tu navegador no permite la nativa.', 'info');
  }
}

function desactivarSimulada(escenario) {
  escenario.classList.remove('pantalla-completa-simulada');
  document.body.classList.remove('sin-scroll');
  ocultarBotonSalir();
}

function alternarPantallaCompleta() {
  const escenario = $('#escenario');
  if (!escenario) return;

  if (escenario.classList.contains('pantalla-completa-simulada')) {
    desactivarSimulada(escenario);
    return;
  }

  if (elementoPantallaCompleta()) {
    salirPantallaCompleta();
    return;
  }

  const pedir = escenario.requestFullscreen
             ?? escenario.webkitRequestFullscreen
             ?? escenario.msRequestFullscreen;

  if (!pedir) {
    activarSimulada(escenario, true);
    return;
  }

  try {
    const resultado = pedir.call(escenario, { navigationUI: 'hide' });
    // Chrome devuelve una promesa; Safari antiguo no devuelve nada.
    if (resultado && typeof resultado.catch === 'function') {
      resultado.catch(() => activarSimulada(escenario));
    }
  } catch {
    activarSimulada(escenario);
  }
}

$('#btn-pantalla-completa')?.addEventListener('click', alternarPantallaCompleta);

/* El boton flotante que vive dentro del escenario: es la unica salida
   visible cuando se juega a pantalla completa en un telefono. */
$('#btn-salir-pantalla')?.addEventListener('click', (e) => {
  e.stopPropagation();   // que el clic no lo capture el emulador
  alternarPantallaCompleta();
});

/* ---------------------------------------------------------------------
   Aviso para iPhone / iPad
   ---------------------------------------------------------------------
   En iOS, Safari no deja que una pagina oculte su barra de direcciones,
   asi que la "pantalla completa" siempre deja la interfaz del navegador
   a la vista. La unica forma de ganar la pantalla entera es instalar el
   sitio desde "Compartir > Anadir a inicio", que lo abre sin barras.

   Se muestra una sola vez por dispositivo y solo cuando aplica.
   --------------------------------------------------------------------- */
(function avisarInstalacionIOS() {
  const CLAVE = 'retrocloud:aviso-ios';

  const esIOS = /iPad|iPhone|iPod/.test(navigator.userAgent)
    // iPadOS reciente se identifica como Mac, pero tiene pantalla tactil.
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  /*
   * Chrome (y Firefox, y Edge) en iOS se identifican con CriOS / FxiOS /
   * EdgiOS. Por dentro son todos WebKit, asi que arrastran las mismas
   * limitaciones, pero su menu Compartir no siempre crea un acceso que
   * iOS abra como aplicacion independiente. Por eso a esos usuarios se
   * les anade un primer paso: hacerlo desde Safari.
   */
  const esChromeEnIOS = esIOS && /CriOS|FxiOS|EdgiOS/.test(navigator.userAgent);

  // Si ya esta instalado, no hay barras que ocultar y sobra el aviso.
  const yaInstalado = window.navigator.standalone === true
    || window.matchMedia?.('(display-mode: standalone)').matches;

  if (!esIOS || yaInstalado) return;

  try {
    if (localStorage.getItem(CLAVE) === 'visto') return;
    localStorage.setItem(CLAVE, 'visto');
  } catch {
    return;   // sin localStorage preferimos no insistir en cada carga
  }

  const panel = document.createElement('div');
  panel.className = 'instalar-ios';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Como jugar a pantalla completa');
  panel.innerHTML = `
    <div class="instalar-ios__cabecera">
      <strong>Juega a pantalla completa</strong>
      <button type="button" class="instalar-ios__cerrar" aria-label="Cerrar">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
          <path d="M18 6 6 18M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <p>
      En iPhone, ningun navegador puede ocultar su propia barra: todos
      usan el motor de Safari por dentro y iOS no lo permite. La unica
      forma de ganar la pantalla entera es instalar RetroCloud.
    </p>
    <ol>
      ${esChromeEnIOS
        ? '<li>Abre esta pagina en <strong>Safari</strong> (desde Chrome el atajo no queda como app).</li>'
        : ''}
      <li>Toca <strong>Compartir</strong> (el cuadrito con la flecha hacia arriba).</li>
      <li>Baja y elige <strong>Anadir a pantalla de inicio</strong>.</li>
      <li>Abre RetroCloud desde el icono nuevo: se vera sin barras.</li>
    </ol>
    <button type="button" class="btn btn--primario btn--bloque instalar-ios__ok">
      Entendido
    </button>`;

  const cerrar = () => panel.remove();
  panel.querySelector('.instalar-ios__cerrar').addEventListener('click', cerrar);
  panel.querySelector('.instalar-ios__ok').addEventListener('click', cerrar);

  // Se muestra con el juego ya cargado, para no competir con la carga.
  setTimeout(() => document.body.appendChild(panel), 3000);
})();

/* Al salir con el gesto del sistema o con Escape, limpia el estado. */
['fullscreenchange', 'webkitfullscreenchange', 'MSFullscreenChange']
  .forEach((evento) => {
    document.addEventListener(evento, () => {
      if (elementoPantallaCompleta()) {
        // Acaba de entrar: se muestra la salida unos segundos para que
        // el usuario sepa que existe y donde esta.
        mostrarBotonSalir();
      } else {
        document.body.classList.remove('sin-scroll');
        ocultarBotonSalir();
      }
    });
  });

/* En el modo simulado, Escape tambien debe salir. */
document.addEventListener('keydown', (e) => {
  const escenario = $('#escenario');
  if (e.key === 'Escape' && escenario?.classList.contains('pantalla-completa-simulada')) {
    desactivarSimulada(escenario);
  }
});

/* =====================================================================
   9. INICIO
   ===================================================================== */

if (juegoId <= 0 || urlRom === '') {
  mostrarFalloCarga(
    'Configuracion del juego incompleta',
    'Vuelve al catalogo y elige un juego otra vez.'
  );
} else if (biosRequerido && !urlBios) {
  /*
   * La consola exige firmware y no hay ninguna cargada.
   *
   * Se corta ANTES de arrancar el nucleo. Si se dejara continuar, el
   * emulador descargaria la ROM entera, intentaria iniciar y se
   * quedaria en negro sin decir por que: el sintoma mas desconcertante
   * de la emulacion. Mejor explicarlo de entrada.
   */
  mostrarFalloCarga(
    `${consola} necesita su BIOS`,
    'Esta plataforma no ejecuta el juego por si sola: necesita la firmware '
    + 'original del sistema. Un administrador puede cargarla en '
    + 'Panel > Consolas, y entonces este juego arrancara.'
  );
} else {
  arrancarEmulador();
}
