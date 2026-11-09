/* =====================================================================
   RetroCloud · Panel · Resumen
   =====================================================================
   Pinta las metricas y dos graficas de barras construidas solo con CSS
   (altura porcentual de cada columna). No se carga ninguna libreria de
   graficas: para dos barras verticales no hace falta y se ahorran unos
   200 KB de descarga.
   ===================================================================== */

'use strict';

import { api, escapar, formato } from './api.js';

const $ = (sel) => document.querySelector(sel);

/* ---------------------------------------------------------------------
   Grafica de barras
   --------------------------------------------------------------------- */

/**
 * @param {HTMLElement} contenedor
 * @param {Array<{etiqueta:string, valor:number, color?:string}>} datos
 */
function pintarGrafica(contenedor, datos) {
  if (!contenedor) return;

  if (datos.length === 0) {
    contenedor.innerHTML =
      '<p class="text-sm text-debil" style="margin:auto">Sin datos todavia.</p>';
    return;
  }

  // El maximo define la escala. Minimo 1 para no dividir entre cero.
  const maximo = Math.max(1, ...datos.map((d) => d.valor));

  contenedor.innerHTML = datos.map((d) => {
    // 4% de altura minima: una barra de valor 0 debe seguir siendo
    // visible como marca, si no la grafica parece incompleta.
    const alto = d.valor === 0 ? 2 : Math.max(4, (d.valor / maximo) * 100);

    return `
      <div class="grafica__columna" title="${escapar(d.etiqueta)}: ${d.valor}">
        <span class="text-xs text-debil">${d.valor}</span>
        <div class="grafica__barra"
             style="height:${alto}%${d.color
               ? `;background:linear-gradient(to top,${escapar(d.color)},${escapar(d.color)})`
               : ''}"></div>
        <span class="grafica__etiqueta">${escapar(d.etiqueta)}</span>
      </div>`;
  }).join('');
}

/* ---------------------------------------------------------------------
   Carga
   --------------------------------------------------------------------- */
async function cargar() {
  try {
    const d = await api.stats.dashboard();
    const c = d.cifras;

    /* ---- Metricas ---- */
    $('#metricas-admin').innerHTML = `
      <div class="metrica">
        <div class="metrica__etiqueta">Juegos</div>
        <div class="metrica__valor">${c.juegos_total}</div>
        <div class="metrica__pie">${c.juegos_publicos} publicados</div>
      </div>
      <div class="metrica">
        <div class="metrica__etiqueta">Usuarios</div>
        <div class="metrica__valor">${c.usuarios}</div>
        <div class="metrica__pie">${c.admins} administrador${c.admins === 1 ? '' : 'es'}</div>
      </div>
      <div class="metrica">
        <div class="metrica__etiqueta">Partidas guardadas</div>
        <div class="metrica__valor">${c.saves}</div>
        <div class="metrica__pie">${escapar(c.peso_saves)} en disco</div>
      </div>
      <div class="metrica">
        <div class="metrica__etiqueta">Sesiones de juego</div>
        <div class="metrica__valor">${c.sesiones}</div>
        <div class="metrica__pie">historico</div>
      </div>
      <div class="metrica">
        <div class="metrica__etiqueta">ROMs en disco</div>
        <div class="metrica__valor" style="font-size:1.45rem">${escapar(c.peso_roms)}</div>
        <div class="metrica__pie">fuera del document root</div>
      </div>
      <div class="metrica">
        <div class="metrica__etiqueta">Logins fallidos (24 h)</div>
        <div class="metrica__valor" style="${c.fallos_24h > 20
          ? 'color:var(--error)' : ''}">${c.fallos_24h}</div>
        <div class="metrica__pie">${c.fallos_24h > 20
          ? 'actividad inusual' : 'dentro de lo normal'}</div>
      </div>`;

    /* ---- Juegos por consola ---- */
    pintarGrafica(
      $('#grafica-consolas'),
      d.por_consola.map((x) => ({
        // Nombres largos se recortan: "Nintendo Entertainment..." no cabe.
        etiqueta: x.nombre.length > 14 ? x.nombre.slice(0, 12) + '...' : x.nombre,
        valor: x.total,
        color: x.color_hex,
      }))
    );

    /* ---- Actividad de 14 dias ----
       La API solo devuelve los dias CON partidas. Rellenamos los huecos
       con cero para que la grafica no mienta sobre la continuidad. */
    const porDia = new Map(
      d.actividad.map((a) => [String(a.dia), Number(a.partidas)])
    );

    const dias = [];
    for (let i = 13; i >= 0; i--) {
      const fecha = new Date();
      fecha.setHours(0, 0, 0, 0);
      fecha.setDate(fecha.getDate() - i);

      const clave = fecha.toISOString().slice(0, 10);
      dias.push({
        etiqueta: String(fecha.getDate()),
        valor: porDia.get(clave) ?? 0,
      });
    }
    pintarGrafica($('#grafica-actividad'), dias);

    /* ---- Ultimos usuarios ---- */
    const cuerpo = $('#tabla-usuarios');
    if (d.ultimos_usuarios.length === 0) {
      cuerpo.innerHTML =
        '<tr><td colspan="5" class="text-center text-debil">Sin usuarios.</td></tr>';
    } else {
      cuerpo.innerHTML = d.ultimos_usuarios.map((u) => `
        <tr>
          <td><strong>${escapar(u.nombre_usuario)}</strong></td>
          <td class="text-sm text-tenue">${escapar(u.correo)}</td>
          <td>
            <span class="insignia ${u.rol === 'admin' ? 'insignia--exito' : ''}">
              ${escapar(u.rol)}
            </span>
          </td>
          <td class="text-sm text-debil">${escapar(formato.fecha(u.creado_en))}</td>
          <td class="text-sm text-debil">
            ${u.ultimo_acceso ? escapar(formato.relativo(u.ultimo_acceso)) : 'nunca'}
          </td>
        </tr>`).join('');
    }
  } catch (e) {
    $('#metricas-admin').innerHTML = `
      <div class="aviso aviso--error" style="grid-column:1/-1">
        <div class="aviso__cuerpo">
          <strong>No se pudo cargar el resumen</strong>
          ${escapar(e.message)}
        </div>
      </div>`;
  }
}

cargar();
