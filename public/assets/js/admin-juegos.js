/* =====================================================================
   RetroCloud · Panel · CRUD de juegos
   =====================================================================
   Nota tecnica importante:

   La subida NO usa fetch(). fetch() no expone el progreso de carga
   (no hay evento equivalente a upload.onprogress), y una ROM de 60 MB
   sin barra de progreso parece que colgo el navegador. Por eso aqui
   usamos XMLHttpRequest, que si lo expone. El resto de la aplicacion
   si usa fetch().
   ===================================================================== */

'use strict';

import {
  api, avisar, cargando, confirmar, csrf,
  escapar, formato, pintarErrores, retardar, ruta,
} from './api.js';

const $ = (sel) => document.querySelector(sel);

/* ---------------------------------------------------------------------
   Estado
   --------------------------------------------------------------------- */
const estado = {
  pagina: 1,
  porPagina: 20,
  busqueda: '',
  editando: null,   // id del juego en edicion, null si es alta
};

/* ---------------------------------------------------------------------
   Elementos
   --------------------------------------------------------------------- */
const panel = $('#panel-formulario');
const form = $('#form-juego');
const tabla = $('#tabla-juegos');

/* =====================================================================
   1. FORMULARIO: abrir, cerrar, precargar
   ===================================================================== */

function abrirFormulario(juego = null) {
  estado.editando = juego?.id ?? null;

  form.reset();
  pintarErrores(form, {});
  $('#aviso-juego').hidden = true;
  $('#progreso-subida').hidden = true;
  $('#previsualizacion-portada').hidden = true;
  reiniciarZonas();

  $('#t-formulario').textContent = juego
    ? `Editar: ${juego.titulo}`
    : 'Nuevo juego';

  $('#juego-id').value = juego?.id ?? '';

  // Al editar, la ROM es opcional: solo se reemplaza si se adjunta otra.
  $('#rom').required = !juego;
  $('#rom-requerido').hidden = !!juego;
  $('#rom-titulo').textContent = juego
    ? 'Reemplazar la ROM (opcional)'
    : 'Arrastra la ROM o pulsa aqui';

  if (juego) {
    $('#titulo').value        = juego.titulo ?? '';
    $('#consola_id').value    = juego.consola_id ?? '';
    $('#desarrollador').value = juego.desarrollador ?? '';
    $('#anio').value          = juego.anio ?? '';
    $('#jugadores').value     = juego.jugadores ?? 1;
    $('#descripcion').value   = juego.descripcion ?? '';
    $('#licencia').value      = juego.licencia ?? '';
    form.publicado.checked    = !!juego.publicado;

    // El genero llega por nombre en la vista; buscamos su <option>.
    const opcion = [...$('#genero_id').options]
      .find((o) => o.textContent.trim() === (juego.genero ?? ''));
    $('#genero_id').value = opcion?.value ?? '';

    if (juego.url_portada) {
      const img = $('#previsualizacion-portada');
      img.src = ruta(juego.url_portada);
      img.hidden = false;
    }
  }

  actualizarExtensiones();

  panel.hidden = false;
  panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  $('#titulo').focus();
}

function cerrarFormulario() {
  panel.hidden = true;
  estado.editando = null;
  form.reset();
  reiniciarZonas();
}

function reiniciarZonas() {
  $('#rom-pista').textContent = $('#rom-pista').dataset.original
    ?? $('#rom-pista').textContent;
  $('#portada-titulo').textContent = 'Arrastra una imagen o pulsa aqui';
  $('#zona-rom').classList.remove('esta-sobre');
  $('#zona-portada').classList.remove('esta-sobre');
}

$('#btn-nuevo')?.addEventListener('click', () => abrirFormulario());
$('#btn-cerrar-form')?.addEventListener('click', cerrarFormulario);
$('#btn-cancelar-form')?.addEventListener('click', cerrarFormulario);

/* Si llegamos con ?nuevo=1 desde el resumen, abrimos el formulario. */
if (new URLSearchParams(location.search).get('nuevo') === '1') {
  abrirFormulario();
}

/* ---------------------------------------------------------------------
   Extensiones aceptadas segun la consola elegida
   El atributo accept mejora el dialogo de archivos del sistema; la
   validacion de verdad la hace el servidor.
   --------------------------------------------------------------------- */
function actualizarExtensiones() {
  const opcion = $('#consola_id').selectedOptions[0];
  const extensiones = opcion?.dataset.extensiones ?? '';
  const core = opcion?.dataset.core ?? '';

  const lista = extensiones
    .split(',')
    .map((x) => x.trim())
    .filter(Boolean);

  $('#rom').accept = lista.map((x) => '.' + x).join(',');

  $('#ayuda-extensiones').innerHTML = lista.length
    ? `Extensiones aceptadas: <strong>${escapar(lista.join(', '))}</strong>
       &middot; nucleo <code>${escapar(core)}</code>`
    : 'Elige la consola para ver las extensiones aceptadas.';
}

$('#consola_id')?.addEventListener('change', actualizarExtensiones);

/* ---------------------------------------------------------------------
   Zonas de arrastre
   --------------------------------------------------------------------- */
function conectarZona(idZona, idInput, alElegir) {
  const zona = document.getElementById(idZona);
  const input = document.getElementById(idInput);
  if (!zona || !input) return;

  ['dragenter', 'dragover'].forEach((ev) =>
    zona.addEventListener(ev, (e) => {
      e.preventDefault();
      zona.classList.add('esta-sobre');
    })
  );

  ['dragleave', 'drop'].forEach((ev) =>
    zona.addEventListener(ev, () => zona.classList.remove('esta-sobre'))
  );

  zona.addEventListener('drop', (e) => {
    e.preventDefault();
    const archivo = e.dataTransfer?.files?.[0];
    if (!archivo) return;

    // DataTransfer permite asignar FileList a un input de forma valida.
    const dt = new DataTransfer();
    dt.items.add(archivo);
    input.files = dt.files;
    input.dispatchEvent(new Event('change'));
  });

  input.addEventListener('change', () => {
    const archivo = input.files?.[0];
    if (archivo) alElegir(archivo);
  });
}

conectarZona('zona-rom', 'rom', (archivo) => {
  $('#rom-titulo').textContent = archivo.name;
  $('#rom-pista').textContent = formato.bytes(archivo.size);
});

conectarZona('zona-portada', 'portada', (archivo) => {
  $('#portada-titulo').textContent = archivo.name;
  $('#portada-pista').textContent = formato.bytes(archivo.size);

  // Previsualizacion con URL de objeto: no hace falta leer el archivo.
  const img = $('#previsualizacion-portada');
  const url = URL.createObjectURL(archivo);
  img.src = url;
  img.hidden = false;
  // Liberamos la memoria del blob una vez pintada.
  img.onload = () => URL.revokeObjectURL(url);
});

/* =====================================================================
   2. SUBIDA CON PROGRESO (XMLHttpRequest)
   ===================================================================== */

/**
 * Envia el FormData mostrando el progreso real de carga.
 * @param {string} url
 * @param {FormData} datos
 * @param {(pct:number, cargado:number, total:number) => void} alProgresar
 * @returns {Promise<any>} el contenido de `datos` de la respuesta
 */
function subirConProgreso(url, datos, alProgresar) {
  return new Promise((resolver, rechazar) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', ruta(url), true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-CSRF-Token', csrf.obtener());

    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) {
        alProgresar((e.loaded / e.total) * 100, e.loaded, e.total);
      }
    });

    xhr.addEventListener('load', () => {
      let json = null;
      try {
        json = JSON.parse(xhr.responseText);
      } catch {
        rechazar({
          message: 'El servidor devolvio una respuesta que no es JSON. '
                 + 'Revisa logs/php-error.log.',
          campos: {},
        });
        return;
      }

      if (xhr.status >= 200 && xhr.status < 300 && json?.ok !== false) {
        resolver(json.datos ?? null);
      } else {
        rechazar({
          message: json?.error ?? `Error ${xhr.status} del servidor.`,
          campos: json?.campos ?? {},
        });
      }
    });

    xhr.addEventListener('error', () =>
      rechazar({ message: 'Fallo de red durante la subida.', campos: {} })
    );

    xhr.addEventListener('abort', () =>
      rechazar({ message: 'Subida cancelada.', campos: {} })
    );

    xhr.send(datos);
  });
}

/* =====================================================================
   3. GUARDAR
   ===================================================================== */

form?.addEventListener('submit', async (evento) => {
  evento.preventDefault();
  pintarErrores(form, {});
  $('#aviso-juego').hidden = true;

  const datos = new FormData(form);
  datos.set('accion', estado.editando ? 'actualizar' : 'crear');

  // Un checkbox sin marcar no se envia; lo declaramos explicitamente.
  if (!form.publicado.checked) datos.set('publicado', '0');

  // En edicion sin ROM nueva, quitamos el campo vacio para que el
  // backend sepa que debe conservar la que ya estaba.
  const rom = $('#rom').files?.[0];
  if (!rom) datos.delete('rom');
  if (!$('#portada').files?.[0]) datos.delete('portada');

  if (!estado.editando && !rom) {
    pintarErrores(form, { rom: 'Adjunta el archivo de la ROM.' });
    return;
  }

  const boton = $('#btn-guardar-juego');
  cargando(boton, true, 'Subiendo...');

  const cajaProgreso = $('#progreso-subida');
  const relleno = $('#progreso-subida-relleno');
  const texto = $('#progreso-subida-texto');

  if (rom) {
    cajaProgreso.hidden = false;
    relleno.style.width = '0%';
  }

  try {
    const url = estado.editando
      ? 'api/juegos.php?accion=actualizar'
      : 'api/juegos.php?accion=crear';

    const r = await subirConProgreso(url, datos, (pct, cargado, total) => {
      relleno.style.width = `${pct.toFixed(1)}%`;
      texto.textContent = pct >= 99.5
        ? 'Procesando en el servidor (calculando SHA-256)...'
        : `Subiendo ${formato.bytes(cargado)} de ${formato.bytes(total)} · ${pct.toFixed(0)}%`;
    });

    cargando(boton, false);
    cajaProgreso.hidden = true;

    avisar(r.mensaje ?? 'Juego guardado.', 'exito');
    cerrarFormulario();
    cargarTabla();
  } catch (e) {
    cargando(boton, false);
    cajaProgreso.hidden = true;

    if (e.campos && Object.keys(e.campos).length > 0) {
      pintarErrores(form, e.campos);
    }

    $('#aviso-juego-texto').textContent = e.message;
    $('#aviso-juego').hidden = false;
    $('#aviso-juego').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
});

/* =====================================================================
   4. TABLA
   ===================================================================== */

async function cargarTabla() {
  tabla.innerHTML =
    '<tr><td colspan="8" class="text-center text-debil">Cargando...</td></tr>';

  try {
    const r = await api.juegos.listar({
      pagina: estado.pagina,
      por_pagina: estado.porPagina,
      q: estado.busqueda,
      incluir_ocultos: '1',     // el panel ve tambien los borradores
      orden: 'recientes',
    });

    $('#conteo-admin').textContent =
      `${r.paginacion.total} juego${r.paginacion.total === 1 ? '' : 's'}`;

    if (r.juegos.length === 0) {
      tabla.innerHTML = `
        <tr><td colspan="8">
          <div class="vacio" style="padding:34px 16px">
            <h3 style="font-size:1rem">
              ${estado.busqueda ? 'Sin resultados' : 'El catalogo esta vacio'}
            </h3>
            <p class="text-sm mb-0">
              ${estado.busqueda
                ? 'Prueba con otro termino de busqueda.'
                : 'Pulsa "Nuevo juego" para subir tu primera ROM.'}
            </p>
          </div>
        </td></tr>`;
      $('#paginacion-admin').innerHTML = '';
      return;
    }

    tabla.innerHTML = r.juegos.map((j) => `
      <tr data-juego='${escapar(JSON.stringify(j))}'>
        <td>
          ${j.url_portada
            ? `<img class="tabla__miniatura" src="${escapar(ruta(j.url_portada))}"
                    alt="" loading="lazy">`
            : `<div class="tabla__miniatura" style="display:grid;place-items:center;
                    background:${escapar(j.color_hex)};color:#fff;font-size:.7rem;
                    font-weight:700">
                 ${escapar((j.titulo[0] ?? '?').toUpperCase())}
               </div>`}
        </td>
        <td>
          <strong>${escapar(j.titulo)}</strong>
          ${j.desarrollador
            ? `<div class="text-xs text-debil">${escapar(j.desarrollador)}</div>`
            : ''}
        </td>
        <td>
          <span class="insignia insignia--consola"
                style="background:${escapar(j.color_hex)}">
            ${escapar(j.consola)}
          </span>
        </td>
        <td class="text-sm">${j.anio ?? '-'}</td>
        <td class="text-sm text-debil mono">${escapar(j.peso)}</td>
        <td class="text-sm">${j.veces_jugado}</td>
        <td>
          <span class="insignia ${j.publicado ? 'insignia--exito' : 'insignia--alerta'}">
            ${j.publicado ? 'publicado' : 'borrador'}
          </span>
        </td>
        <td class="acciones">
          <div class="flex gap-sm" style="justify-content:flex-end">
            <a class="btn btn--sm btn--fantasma"
               href="${escapar(ruta('jugar.php?id=' + j.id))}"
               title="Probar">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"
                   aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
            </a>
            <button type="button" class="btn btn--sm btn--fantasma"
                    data-editar="${j.id}" title="Editar">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round"
                   aria-hidden="true">
                <path d="M11 4h-5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/>
                <path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/>
              </svg>
            </button>
            <button type="button" class="btn btn--sm btn--peligro"
                    data-eliminar="${j.id}"
                    data-titulo="${escapar(j.titulo)}" title="Eliminar">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round"
                   aria-hidden="true">
                <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>
              </svg>
            </button>
          </div>
        </td>
      </tr>`).join('');

    pintarPaginacion(r.paginacion);
  } catch (e) {
    tabla.innerHTML = `
      <tr><td colspan="8">
        <div class="aviso aviso--error mb-0">
          <div class="aviso__cuerpo">${escapar(e.message)}</div>
        </div>
      </td></tr>`;
  }
}

function pintarPaginacion(p) {
  const nodo = $('#paginacion-admin');
  if (!nodo) return;

  if (p.paginas <= 1) {
    nodo.innerHTML = '';
    return;
  }

  const partes = [`
    <button type="button" data-pagina="${p.pagina - 1}"
            ${p.pagina === 1 ? 'disabled' : ''} aria-label="Anterior">&lsaquo;</button>`];

  for (let i = 1; i <= p.paginas; i++) {
    // Ventana de 2 paginas alrededor de la actual + primera y ultima.
    if (i === 1 || i === p.paginas || Math.abs(i - p.pagina) <= 1) {
      partes.push(`
        <button type="button" data-pagina="${i}"
                ${i === p.pagina ? 'aria-current="page"' : ''}>${i}</button>`);
    } else if (Math.abs(i - p.pagina) === 2) {
      partes.push('<button type="button" disabled>...</button>');
    }
  }

  partes.push(`
    <button type="button" data-pagina="${p.pagina + 1}"
            ${p.pagina === p.paginas ? 'disabled' : ''} aria-label="Siguiente">&rsaquo;</button>`);

  nodo.innerHTML = partes.join('');
}

$('#paginacion-admin')?.addEventListener('click', (e) => {
  const boton = e.target.closest('[data-pagina]');
  if (!boton || boton.disabled) return;
  estado.pagina = Number(boton.dataset.pagina);
  cargarTabla();
});

/* Busqueda con retardo */
$('#buscador-admin')?.addEventListener('input', retardar((e) => {
  estado.busqueda = e.target.value.trim();
  estado.pagina = 1;
  cargarTabla();
}, 350));

/* =====================================================================
   5. EDITAR Y ELIMINAR (delegado)
   ===================================================================== */

tabla?.addEventListener('click', async (e) => {
  const botonEditar = e.target.closest('[data-editar]');
  if (botonEditar) {
    const fila = botonEditar.closest('tr');
    try {
      abrirFormulario(JSON.parse(fila.dataset.juego));
    } catch {
      avisar('No se pudieron leer los datos del juego.', 'error');
    }
    return;
  }

  const botonEliminar = e.target.closest('[data-eliminar]');
  if (!botonEliminar) return;

  const titulo = botonEliminar.dataset.titulo;

  const ok = await confirmar({
    titulo: 'Eliminar juego',
    mensaje: `Se eliminara "${titulo}" junto con su ROM, su portada y TODAS `
           + 'las partidas guardadas de todos los usuarios. '
           + 'Esta accion no se puede deshacer.',
    textoOk: 'Eliminar definitivamente',
    peligro: true,
  });
  if (!ok) return;

  botonEliminar.disabled = true;
  try {
    const r = await api.juegos.eliminar(Number(botonEliminar.dataset.eliminar));
    avisar(r.mensaje ?? 'Juego eliminado.', 'exito');
    cargarTabla();
  } catch (err) {
    botonEliminar.disabled = false;
    avisar(err.message, 'error', 6000);
  }
});

/* ---- Arranque ---- */
$('#rom-pista').dataset.original = $('#rom-pista').textContent;
cargarTabla();
