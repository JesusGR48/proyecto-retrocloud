/* =====================================================================
   RetroCloud · Panel · Consolas y BIOS
   =====================================================================
   Carga, reemplaza y elimina la firmware de sistema de cada plataforma,
   y permite marcar si una consola la exige para poder jugar.
   ===================================================================== */

'use strict';

import { api, avisar, confirmar, escapar } from './api.js';

const $ = (sel) => document.querySelector(sel);

const tabla = $('#tabla-consolas');
const selector = $('#selector-bios');

/** Consola a la que se va a asignar el archivo que elija el usuario. */
let consolaPendiente = null;

/* =====================================================================
   Pintado
   ===================================================================== */
function pintar(datos) {
  const { consolas, sin_bios: sinBios } = datos;

  $('#conteo-consolas').textContent =
    `${consolas.length} plataforma${consolas.length === 1 ? '' : 's'}`;

  // Aviso resumen: cuantas exigen BIOS y no lo tienen.
  const caja = $('#aviso-falta');
  if (sinBios > 0) {
    $('#aviso-falta-texto').innerHTML =
      `<strong>${sinBios} consola${sinBios === 1 ? '' : 's'} sin BIOS</strong>`
      + ` Sus juegos no arrancaran hasta que cargues el archivo.`;
    caja.hidden = false;
  } else {
    caja.hidden = true;
  }

  tabla.innerHTML = consolas.map((c) => `
    <tr${c.falta_bios ? ' style="background:color-mix(in srgb,var(--aviso) 8%,transparent)"' : ''}>
      <td>
        <span class="insignia insignia--consola"
              style="background:${escapar(c.color_hex)}">
          ${escapar(c.nombre)}
        </span>
        ${c.fabricante
          ? `<div class="text-xs text-debil" style="margin-top:4px">${escapar(c.fabricante)}</div>`
          : ''}
      </td>
      <td><code class="text-sm">${escapar(c.core_emulador)}</code></td>
      <td class="text-sm">${c.total_juegos}</td>
      <td>
        <label class="interruptor">
          <input type="checkbox" data-exigir="${c.id}" ${c.bios_requerido ? 'checked' : ''}>
          <span class="interruptor__pista"></span>
        </label>
      </td>
      <td>
        ${c.tiene_bios
          ? `<div class="text-sm"><strong>${escapar(c.bios_nombre_original ?? 'bios')}</strong></div>
             <div class="text-xs text-debil mono">
               ${escapar(c.bios_peso)} · ${escapar(c.bios_hash_corto)}
             </div>`
          : (c.bios_requerido
              ? '<span class="insignia insignia--alerta">falta</span>'
              : '<span class="text-xs text-debil">no necesario</span>')}
      </td>
      <td class="acciones">
        <div class="flex gap-sm" style="justify-content:flex-end">
          <button type="button" class="btn btn--sm btn--fantasma"
                  data-subir="${c.id}" data-nombre="${escapar(c.nombre)}">
            ${c.tiene_bios ? 'Reemplazar' : 'Cargar BIOS'}
          </button>
          ${c.tiene_bios
            ? `<button type="button" class="btn btn--sm btn--peligro"
                       data-borrar="${c.id}" data-nombre="${escapar(c.nombre)}"
                       aria-label="Borrar BIOS de ${escapar(c.nombre)}">
                 <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round"
                      aria-hidden="true">
                   <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>
                 </svg>
               </button>`
            : ''}
        </div>
      </td>
    </tr>`).join('');
}

async function cargar() {
  tabla.innerHTML =
    '<tr><td colspan="6" class="text-center text-debil">Cargando...</td></tr>';
  try {
    pintar(await api.consolas.listar());
  } catch (e) {
    tabla.innerHTML = `
      <tr><td colspan="6">
        <div class="aviso aviso--error mb-0">
          <div class="aviso__cuerpo">${escapar(e.message)}</div>
        </div>
      </td></tr>`;
  }
}

/* =====================================================================
   Acciones
   ===================================================================== */

/* --- Cargar o reemplazar BIOS ---
   Se usa un unico <input type="file"> oculto y compartido: el boton de
   cada fila apunta a su consola y luego lo abre. Asi no hay un input
   por fila en el DOM. */
tabla?.addEventListener('click', async (e) => {

  const subir = e.target.closest('[data-subir]');
  if (subir) {
    consolaPendiente = { id: Number(subir.dataset.subir), nombre: subir.dataset.nombre };
    selector.value = '';     // permite volver a elegir el mismo archivo
    selector.click();
    return;
  }

  const borrar = e.target.closest('[data-borrar]');
  if (!borrar) return;

  const ok = await confirmar({
    titulo: 'Borrar BIOS',
    mensaje: `Se eliminara la firmware de ${borrar.dataset.nombre}. `
           + 'Los juegos de esa consola dejaran de arrancar hasta que cargues otra.',
    textoOk: 'Borrar',
    peligro: true,
  });
  if (!ok) return;

  borrar.disabled = true;
  try {
    const r = await api.consolas.borrarBios(Number(borrar.dataset.borrar));
    avisar(r.mensaje ?? 'BIOS eliminado.', 'exito');
    cargar();
  } catch (err) {
    borrar.disabled = false;
    avisar(err.message, 'error', 6000);
  }
});

/* --- El usuario eligio un archivo --- */
selector?.addEventListener('change', async () => {
  const archivo = selector.files?.[0];
  if (!archivo || !consolaPendiente) return;

  const { id, nombre } = consolaPendiente;
  consolaPendiente = null;

  avisar(`Subiendo ${archivo.name}...`, 'info', 2500);

  try {
    const r = await api.consolas.subirBios(id, archivo);
    avisar(r.mensaje ?? `BIOS cargado para ${nombre}.`, 'exito');
    cargar();
  } catch (e) {
    avisar(e.message, 'error', 7000);
  }
});

/* --- Marcar si la consola exige BIOS --- */
tabla?.addEventListener('change', async (e) => {
  const casilla = e.target.closest('[data-exigir]');
  if (!casilla) return;

  casilla.disabled = true;
  try {
    const r = await api.consolas.exigirBios(
      Number(casilla.dataset.exigir),
      casilla.checked
    );
    avisar(r.mensaje ?? 'Actualizado.', 'exito', 2500);
    cargar();
  } catch (err) {
    // Revierte: el servidor no acepto el cambio.
    casilla.checked = !casilla.checked;
    casilla.disabled = false;
    avisar(err.message, 'error');
  }
});

cargar();
