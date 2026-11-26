/**
 * RetroCloud · Efectos de puntero y clic
 * =====================================================================
 * Dos cosas: un halo que persigue al raton y unas chispas al hacer clic.
 *
 * Reglas que se respetan en todo el archivo:
 *
 *   1. Si el navegador dice que la persona prefiere menos movimiento,
 *      este modulo no hace absolutamente nada. No es cortesia: a quien
 *      tiene sensibilidad al movimiento una pantalla asi le provoca
 *      mareo de verdad.
 *   2. No se ejecuta en pantallas tactiles. No hay puntero que seguir y
 *      solo gastaria bateria.
 *   3. No se ejecuta en el reproductor. Encima de un juego, unas chispas
 *      son ruido, y el halo tapa justo lo que se esta mirando.
 *   4. Nada de esto intercepta clics: los elementos que crea llevan
 *      pointer-events: none y se borran solos.
 */

'use strict';

(function () {

  // --- Puertas de salida ---------------------------------------------
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
  if (/\/jugar\.php/.test(window.location.pathname)) return;

  // ===================================================================
  // Halo que persigue al puntero
  // ===================================================================

  const halo = document.createElement('div');
  halo.className = 'halo-puntero';
  halo.setAttribute('aria-hidden', 'true');
  document.body.appendChild(halo);

  let ratonX = 0, ratonY = 0;   // donde esta el raton ahora
  let haloX  = 0, haloY  = 0;   // donde va el halo, siempre por detras
  let vivo = false;

  /* Selector de "esto se puede pulsar". Se consulta con closest() para
     que tambien valga si el raton esta sobre el texto de dentro de un
     boton y no sobre el boton mismo. */
  const PULSABLE = 'a, button, summary, label, select, [role="button"], '
                 + '.juego, .reco__item, .tecla-hex, .lab-prog, .asis__chip';

  document.addEventListener('pointermove', (ev) => {
    ratonX = ev.clientX;
    ratonY = ev.clientY;

    if (!vivo) {
      // Primer movimiento: colocar el halo de golpe para que no entre
      // volando desde la esquina superior izquierda.
      haloX = ratonX;
      haloY = ratonY;
      vivo = true;
      halo.classList.add('is-visible');
    }

    halo.classList.toggle('is-activo', !!ev.target.closest?.(PULSABLE));
  }, { passive: true });

  /* Al salir de la ventana el halo se esconde, para que no quede un
     punto de luz huerfano pegado al borde. */
  document.addEventListener('pointerleave', () => halo.classList.remove('is-visible'));
  document.addEventListener('pointerenter', () => { if (vivo) halo.classList.add('is-visible'); });

  document.addEventListener('pointerdown', () => halo.classList.add('is-pulsando'));
  document.addEventListener('pointerup',   () => halo.classList.remove('is-pulsando'));

  /**
   * El halo no salta al raton: se le acerca un 18 % en cada fotograma.
   *
   * Ese retraso es justo lo que lo hace agradable. Si lo siguiera
   * exactamente seria un cursor mas, y no se notaria que esta ahi.
   */
  function seguir() {
    haloX += (ratonX - haloX) * 0.18;
    haloY += (ratonY - haloY) * 0.18;
    halo.style.transform = 'translate3d(' + haloX + 'px,' + haloY + 'px,0)';
    requestAnimationFrame(seguir);
  }
  requestAnimationFrame(seguir);

  // ===================================================================
  // Chispas al hacer clic
  // ===================================================================

  const COLORES = ['#00e5c0', '#7d5fff', '#9d85ff', '#ffffff'];
  const CHISPAS = 6;

  /* Limite de seguridad: si alguien se pone a hacer clic como loco, no
     queremos cientos de elementos vivos a la vez. */
  let enPantalla = 0;
  const TOPE = 40;

  document.addEventListener('pointerdown', (ev) => {
    if (ev.button !== 0) return;                 // solo el boton izquierdo
    if (enPantalla > TOPE) return;

    const x = ev.clientX;
    const y = ev.clientY;

    // --- Onda circular -----------------------------------------------
    const onda = document.createElement('span');
    onda.className = 'onda-clic';
    onda.style.left = x + 'px';
    onda.style.top  = y + 'px';
    document.body.appendChild(onda);
    enPantalla++;
    onda.addEventListener('animationend', () => { onda.remove(); enPantalla--; });

    // --- Chispas en abanico ------------------------------------------
    for (let i = 0; i < CHISPAS; i++) {
      const chispa = document.createElement('span');
      chispa.className = 'chispa';

      /* Se reparten en circulo con un poco de azar, para que dos clics
         seguidos no den exactamente el mismo dibujo. */
      const angulo = (360 / CHISPAS) * i + (Math.random() * 24 - 12);
      const dist   = 26 + Math.random() * 26;

      chispa.style.left = x + 'px';
      chispa.style.top  = y + 'px';
      chispa.style.setProperty('--ang',  angulo + 'deg');
      chispa.style.setProperty('--dist', dist + 'px');
      chispa.style.background = COLORES[i % COLORES.length];

      document.body.appendChild(chispa);
      enPantalla++;
      chispa.addEventListener('animationend', () => { chispa.remove(); enPantalla--; });
    }
  }, { passive: true });

  /* Si la pestana se va a segundo plano, se limpia lo que quede: una
     animacion pausada no dispara animationend, y sin esto los elementos
     se quedarian ahi para siempre. */
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      document.querySelectorAll('.chispa, .onda-clic').forEach((n) => n.remove());
      enPantalla = 0;
    }
  });

})();
