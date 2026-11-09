/* =====================================================================
   RetroCloud · Panel · Gestion de usuarios
   ===================================================================== */

'use strict';

import {
  api, avisar, confirmar, escapar, formato, retardar,
} from './api.js';

const $ = (sel) => document.querySelector(sel);

const estado = { pagina: 1, porPagina: 25, busqueda: '' };

const tabla = $('#tabla-usuarios-admin');

/* =====================================================================
   Tabla
   ===================================================================== */
async function cargar() {
  tabla.innerHTML =
    '<tr><td colspan="9" class="text-center text-debil">Cargando...</td></tr>';

  try {
    const r = await api.usuarios.listar({
      pagina: estado.pagina,
      por_pagina: estado.porPagina,
      q: estado.busqueda,
    });

    $('#conteo-usuarios').textContent =
      `${r.paginacion.total} cuenta${r.paginacion.total === 1 ? '' : 's'}`;

    if (r.usuarios.length === 0) {
      tabla.innerHTML = `
        <tr><td colspan="9">
          <div class="vacio" style="padding:34px 16px">
            <h3 style="font-size:1rem">Sin resultados</h3>
            <p class="text-sm mb-0">Prueba con otro termino de busqueda.</p>
          </div>
        </td></tr>`;
      $('#paginacion-usuarios').innerHTML = '';
      return;
    }

    tabla.innerHTML = r.usuarios.map((u) => {
      /* La propia cuenta no se puede tocar desde aqui: el backend lo
         rechaza, asi que no ofrecemos controles que van a fallar. */
      const propia = u.es_tu_cuenta;

      return `
        <tr${propia ? ' style="background:color-mix(in srgb,var(--primario) 7%,transparent)"' : ''}>
          <td>
            <strong>${escapar(u.nombre_usuario)}</strong>
            ${propia ? '<span class="insignia insignia--exito">tu cuenta</span>' : ''}
            <div class="text-xs text-debil">
              Desde ${escapar(formato.fecha(u.creado_en))}
            </div>
          </td>
          <td class="text-sm text-tenue truncar" style="max-width:200px">
            ${escapar(u.correo)}
          </td>
          <td>
            ${propia
              ? `<span class="insignia insignia--exito">${escapar(u.rol)}</span>`
              : `<select class="selector" data-rol="${u.id}"
                         style="padding:5px 9px;font-size:.84rem;width:auto"
                         aria-label="Rol de ${escapar(u.nombre_usuario)}">
                   <option value="jugador" ${u.rol === 'jugador' ? 'selected' : ''}>jugador</option>
                   <option value="admin"   ${u.rol === 'admin' ? 'selected' : ''}>admin</option>
                 </select>`}
          </td>
          <td class="text-sm">${u.partidas}</td>
          <td class="text-sm text-debil">${escapar(u.tiempo)}</td>
          <td class="text-sm">${u.saves}</td>
          <td class="text-sm text-debil">
            ${u.ultimo_acceso ? escapar(formato.relativo(u.ultimo_acceso)) : 'nunca'}
          </td>
          <td>
            <span class="insignia ${u.activo ? 'insignia--exito' : 'insignia--error'}">
              ${u.activo ? 'activa' : 'bloqueada'}
            </span>
          </td>
          <td class="acciones">
            ${propia
              ? '<span class="text-xs text-debil">&mdash;</span>'
              : `<div class="flex gap-sm" style="justify-content:flex-end">
                   <button type="button" class="btn btn--sm btn--fantasma"
                           data-activo="${u.id}" data-valor="${u.activo ? '0' : '1'}"
                           title="${u.activo ? 'Deshabilitar' : 'Habilitar'} cuenta">
                     ${u.activo ? 'Bloquear' : 'Activar'}
                   </button>
                   <button type="button" class="btn btn--sm btn--peligro"
                           data-eliminar="${u.id}"
                           data-nombre="${escapar(u.nombre_usuario)}"
                           title="Eliminar cuenta">
                     <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                          stroke="currentColor" stroke-width="2" stroke-linecap="round"
                          aria-hidden="true">
                       <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>
                     </svg>
                   </button>
                 </div>`}
          </td>
        </tr>`;
    }).join('');

    pintarPaginacion(r.paginacion);
  } catch (e) {
    tabla.innerHTML = `
      <tr><td colspan="9">
        <div class="aviso aviso--error mb-0">
          <div class="aviso__cuerpo">${escapar(e.message)}</div>
        </div>
      </td></tr>`;
  }
}

function pintarPaginacion(p) {
  const nodo = $('#paginacion-usuarios');
  if (!nodo) return;

  if (p.paginas <= 1) {
    nodo.innerHTML = '';
    return;
  }

  const partes = [`
    <button type="button" data-pagina="${p.pagina - 1}"
            ${p.pagina === 1 ? 'disabled' : ''} aria-label="Anterior">&lsaquo;</button>`];

  for (let i = 1; i <= p.paginas; i++) {
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

/* =====================================================================
   Acciones
   ===================================================================== */

/* Cambio de rol */
tabla?.addEventListener('change', async (e) => {
  const selector = e.target.closest('[data-rol]');
  if (!selector) return;

  const id = Number(selector.dataset.rol);
  const anterior = selector.dataset.anterior ?? '';
  selector.disabled = true;

  try {
    const r = await api.usuarios.rol(id, selector.value);
    avisar(r.mensaje ?? 'Rol actualizado.', 'exito');
    selector.disabled = false;
    cargar();
  } catch (err) {
    // Revertimos la seleccion: el servidor no acepto el cambio.
    if (anterior) selector.value = anterior;
    selector.disabled = false;
    avisar(err.message, 'error', 6000);
    cargar();
  }
});

/* Guardamos el valor previo al enfocar, para poder revertir. */
tabla?.addEventListener('focusin', (e) => {
  const selector = e.target.closest('[data-rol]');
  if (selector) selector.dataset.anterior = selector.value;
});

/* Bloquear / activar y eliminar */
tabla?.addEventListener('click', async (e) => {

  const botonActivo = e.target.closest('[data-activo]');
  if (botonActivo) {
    const activar = botonActivo.dataset.valor === '1';
    botonActivo.disabled = true;

    try {
      const r = await api.usuarios.activo(Number(botonActivo.dataset.activo), activar);
      avisar(r.mensaje ?? 'Cuenta actualizada.', 'exito');
      cargar();
    } catch (err) {
      botonActivo.disabled = false;
      avisar(err.message, 'error', 6000);
    }
    return;
  }

  const botonEliminar = e.target.closest('[data-eliminar]');
  if (!botonEliminar) return;

  const nombre = botonEliminar.dataset.nombre;

  const ok = await confirmar({
    titulo: 'Eliminar cuenta',
    mensaje: `Se eliminara la cuenta de "${nombre}" junto con sus partidas `
           + 'guardadas, favoritos, logros e historial. '
           + 'Esta accion no se puede deshacer.',
    textoOk: 'Eliminar definitivamente',
    peligro: true,
  });
  if (!ok) return;

  botonEliminar.disabled = true;
  try {
    const r = await api.usuarios.eliminar(Number(botonEliminar.dataset.eliminar));
    avisar(r.mensaje ?? 'Cuenta eliminada.', 'exito', 6000);
    cargar();
  } catch (err) {
    botonEliminar.disabled = false;
    avisar(err.message, 'error', 6000);
  }
});

/* Paginacion y busqueda */
$('#paginacion-usuarios')?.addEventListener('click', (e) => {
  const boton = e.target.closest('[data-pagina]');
  if (!boton || boton.disabled) return;
  estado.pagina = Number(boton.dataset.pagina);
  cargar();
});

$('#buscador-usuarios')?.addEventListener('input', retardar((e) => {
  estado.busqueda = e.target.value.trim();
  estado.pagina = 1;
  cargar();
}, 350));

cargar();
