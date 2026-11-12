/**
 * RetroCloud · Recomendaciones personalizadas (cliente)
 * =====================================================================
 * Pide las sugerencias a /api/recomendaciones.php y las pinta en la
 * portada, junto con el panel que explica como las decidio el modelo.
 *
 * Todo el calculo vive en el servidor (src/Recomendador.php): aqui solo
 * se dibuja. La razon es que el motor necesita el historial de TODOS
 * los usuarios para la parte colaborativa, y eso no puede viajar al
 * navegador de nadie.
 *
 * La seccion arranca oculta y solo se muestra si llegan resultados: si
 * la API falla o el catalogo esta vacio, la portada queda tal cual
 * estaba en lugar de enseñar un hueco roto.
 */

'use strict';

const base = window.RETROCLOUD_BASE ?? '';
const $ = (sel) => document.querySelector(sel);

const seccion    = $('#reco');
const lista      = $('#reco-lista');
const estrategia = $('#reco-estrategia');
const panel      = $('#reco-diagnostico');
const btnPorque  = $('#reco-porque');

/** Escapa texto antes de interpolarlo en HTML. Defensa contra XSS. */
function escapar(v) {
  return String(v ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

/** Iniciales para la portada generada, igual que en el catalogo. */
function iniciales(titulo) {
  return String(titulo).split(/\s+/).filter(Boolean).slice(0, 2)
    .map((p) => p[0]).join('').toUpperCase() || '?';
}

function tarjeta(r) {
  const portada = r.portada
    ? `<img src="${escapar(base + 'assets/portadas/' + r.portada)}"
            alt="Portada de ${escapar(r.titulo)}" loading="lazy" decoding="async">`
    : `<div class="reco__sinportada">${escapar(iniciales(r.titulo))}</div>`;

  const meta = [r.consola, r.genero, r.anio].filter(Boolean)
    .map(escapar).join(' · ');

  return `
    <a class="reco__item" href="${escapar(base + 'jugar.php?id=' + r.id)}">
      <div class="reco__portada">
        ${portada}
        ${r.afinidad === null || r.afinidad === undefined ? '' : `
        <span class="reco__afinidad" title="Afinidad estimada por el modelo">
          ${escapar(String(r.afinidad))}%
        </span>`}
      </div>
      <div class="reco__cuerpo">
        <strong class="reco__titulo">${escapar(r.titulo)}</strong>
        <span class="reco__meta">${meta}</span>
        <span class="reco__porque">${escapar(r.porque)}</span>
      </div>
    </a>`;
}

/**
 * Panel de transparencia.
 *
 * Enseña los pesos de verdad con los que se calculo la lista, no una
 * version simplificada: cuanto pesa cada motor, cuantos datos habia y
 * que perfil de gustos dedujo.
 */
function pintarDiagnostico(d) {
  if (!d) {
    panel.innerHTML = '<p class="text-sm text-debil mb-0">'
      + 'Inicia sesion para ver como razona el modelo con tus datos.</p>';
    return;
  }

  if (d.estrategia === 'retomar') {
    panel.innerHTML = `
      <p class="text-sm mb-0">
        <strong>Estrategia: retomar.</strong> ${escapar(d.motivo ?? '')}
        Es el <em>agotamiento del catalogo</em>, el problema contrario al
        arranque en frio: en vez de faltar datos, sobran. La nota mezcla
        cuanto te gusto cada juego con cuanto hace que no lo abres, porque
        uno que te encanto y dejaste hace semanas es mejor sugerencia que
        uno que cerraste hace diez minutos.
      </p>`;
    return;
  }

  if (d.estrategia === 'popularidad') {
    panel.innerHTML = `
      <p class="text-sm mb-0">
        <strong>Estrategia: popularidad.</strong> ${escapar(d.motivo ?? '')}
        Es el llamado <em>arranque en frio</em>: sin datos de comportamiento no
        hay nada que aprender, y el sistema lo admite en lugar de inventarse
        un gusto. En cuanto juegues algo, pasa a recomendar de verdad.
      </p>`;
    return;
  }

  const barra = (etiqueta, valor, clase) => `
    <div class="reco__peso">
      <span>${escapar(etiqueta)}</span>
      <div class="reco__barra">
        <i class="${clase}" style="width:${Math.round(valor * 100)}%"></i>
      </div>
      <b>${Math.round(valor * 100)}%</b>
    </div>`;

  const listaPerfil = (titulo, items) => {
    if (!items || !items.length) return '';
    return `<div class="reco__dim">
      <span class="reco__dim-titulo">${escapar(titulo)}</span>
      ${items.map((i) => `<span class="reco__chip">
          ${escapar(i.nombre)}
          <i>${Math.round(i.peso * 100)}</i>
        </span>`).join('')}
    </div>`;
  };

  const p = d.perfil ?? {};

  panel.innerHTML = `
    <p class="text-sm reco__explica">
      El modelo combina <strong>dos motores</strong>. El de <em>contenido</em>
      compara consola, genero, decada y estudio con lo que ya jugaste. El
      <em>colaborativo</em> ignora todo eso y mira solo quien juega a que:
      si dos juegos los juega la misma gente, se parecen.
      El reparto entre ambos no es fijo, se calcula segun cuantos datos hay.
    </p>

    ${barra('Motor de contenido', d.peso_contenido ?? 0, 'es-contenido')}
    ${barra('Motor colaborativo', d.peso_colaborativo ?? 0, 'es-colaborativo')}

    <p class="text-xs text-debil reco__nota">
      El colaborativo pesa poco mientras haya pocos usuarios: con dos personas
      en el sistema, "les gusta a los mismos" no significa nada. Segun entra
      gente, su peso sube solo hasta un tope del 75 %.
    </p>

    <div class="reco__perfil">
      <span class="reco__dim-cabecera">Perfil deducido de tu historial</span>
      ${listaPerfil('Consolas', p.consolas)}
      ${listaPerfil('Generos', p.generos)}
      ${listaPerfil('Decadas', p.decadas)}
    </div>

    <p class="text-xs text-debil mb-0 reco__pie">
      ${escapar(String(d.juegos_analizados ?? 0))} juegos analizados ·
      ${escapar(String(d.juegos_con_historial ?? 0))} con historial tuyo ·
      ${escapar(String(d.usuarios_en_modelo ?? 0))} usuarios en el modelo ·
      calculado en ${escapar(String(d.milisegundos ?? '?'))} ms
    </p>`;
}

async function cargar() {
  let json;
  try {
    const res = await fetch(base + 'api/recomendaciones.php?limite=6&explicar=1', {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    json = await res.json();
  } catch (e) {
    return;   // sin red o API caida: la seccion no aparece
  }

  if (!json?.ok || !json.datos?.recomendaciones?.length) {
    return;
  }

  const d = json.datos;
  lista.innerHTML = d.recomendaciones.map(tarjeta).join('');

  const diag = d.diagnostico ?? null;
  const modo = diag?.estrategia ?? d.estrategia;

  estrategia.textContent = {
    popularidad: 'Lo mas jugado de la plataforma, repartido entre consolas.',
    retomar: 'Ya jugaste todo el catalogo: esto es lo que conviene retomar.',
  }[modo] ?? 'Calculado a partir de tu historial de juego.';

  pintarDiagnostico(diag ?? (modo ? { estrategia: modo } : null));

  seccion.hidden = false;
}

btnPorque?.addEventListener('click', () => {
  const abierto = panel.hidden;
  panel.hidden = !abierto;
  btnPorque.setAttribute('aria-expanded', String(abierto));
  btnPorque.textContent = abierto ? 'Ocultar el razonamiento' : '¿Como decidio esto?';
});

if (seccion) {
  cargar();
}
