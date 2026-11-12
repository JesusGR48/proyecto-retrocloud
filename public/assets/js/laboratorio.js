/**
 * RetroCloud · Laboratorio de emulacion (interfaz)
 * =====================================================================
 * Conecta el nucleo de chip8.js con la pagina: pinta la pantalla, el
 * banco de registros, el desensamblado y la explicacion de cada paso.
 *
 * La idea de toda la pantalla es que NADA quede escondido. Un emulador
 * normal es una caja negra con una imagen saliendo por un lado; aqui la
 * caja esta abierta.
 */

'use strict';

import { Chip8, ANCHO, ALTO, INICIO_PROGRAMA, hex } from './chip8.js';
import { PROGRAMAS, programaPorId } from './chip8-programas.js';

/* ---------------------------------------------------------------------
   Teclado
   ---------------------------------------------------------------------
   Las 16 teclas hexadecimales de CHIP-8 se reparten en el cuadrado
   1234 / QWER / ASDF / ZXCV, que es la convencion de toda la escena
   desde los años 90.
   --------------------------------------------------------------------- */
const MAPA_TECLAS = {
  '1': 0x1, '2': 0x2, '3': 0x3, '4': 0xC,
  'q': 0x4, 'w': 0x5, 'e': 0x6, 'r': 0xD,
  'a': 0x7, 's': 0x8, 'd': 0x9, 'f': 0xE,
  'z': 0xA, 'x': 0x0, 'c': 0xB, 'v': 0xF,
};

const DISTRIBUCION_TECLADO = [
  ['1', '2', '3', 'C'],
  ['4', '5', '6', 'D'],
  ['7', '8', '9', 'E'],
  ['A', '0', 'B', 'F'],
];

const VELOCIDADES = [
  { ips: 2,    etiqueta: 'Muy lento',  nota: '2 instrucciones por segundo: se lee cada paso' },
  { ips: 15,   etiqueta: 'Lento',      nota: '15 por segundo: se sigue la logica' },
  { ips: 120,  etiqueta: 'Normal',     nota: '120 por segundo: velocidad original aproximada' },
  { ips: 700,  etiqueta: 'Rapido',     nota: '700 por segundo' },
];

const $ = (sel) => document.querySelector(sel);

class Laboratorio {
  constructor() {
    this.maquina = new Chip8();
    this.corriendo = false;
    this.velocidad = 1;          // indice en VELOCIDADES
    this.acumulador = 0;
    this.ultimoInstante = 0;
    this.programa = null;

    this._cachearNodos();
    this._construirRegistros();
    this._construirTeclado();
    this._construirSelectorProgramas();
    this._enlazarControles();

    this.cargarPrograma(PROGRAMAS[0].id);
    this._bucle = this._bucle.bind(this);
    requestAnimationFrame(this._bucle);
  }

  _cachearNodos() {
    this.lienzo       = $('#lab-pantalla');
    this.ctx          = this.lienzo.getContext('2d', { alpha: false });
    this.nodoRegs     = $('#lab-registros');
    this.nodoEspec    = $('#lab-especiales');
    this.nodoCodigo   = $('#lab-codigo');
    this.nodoExplic   = $('#lab-explicacion');
    this.nodoNemonico = $('#lab-nemonico');
    this.nodoOpcode   = $('#lab-opcode');
    this.nodoDesglose = $('#lab-desglose');
    this.nodoCambios  = $('#lab-cambios');
    this.nodoCiclos   = $('#lab-ciclos');
    this.nodoTeclado  = $('#lab-teclado');
    this.nodoProgs    = $('#lab-programas');
    this.nodoResumen  = $('#lab-resumen');
    this.nodoNotas    = $('#lab-notas');
    this.nodoAviso    = $('#lab-aviso');
    this.btnCorrer    = $('#lab-correr');
    this.btnPaso      = $('#lab-paso');
    this.btnReinicio  = $('#lab-reinicio');
    this.rangoVel     = $('#lab-velocidad');
    this.nodoVelTxt   = $('#lab-velocidad-txt');

    /* El lienzo se dibuja a resolucion nativa (64x32) y el CSS lo
       escala. Sin esto, el navegador suaviza los pixeles y el resultado
       parece una foto borrosa en lugar de pixel art. */
    this.lienzo.width = ANCHO;
    this.lienzo.height = ALTO;
    this.ctx.imageSmoothingEnabled = false;
  }

  // ===================================================================
  // Construccion de la interfaz
  // ===================================================================

  _construirRegistros() {
    this.celdasV = [];
    const frag = document.createDocumentFragment();

    for (let i = 0; i < 16; i++) {
      const celda = document.createElement('div');
      celda.className = 'reg';
      celda.innerHTML =
        '<span class="reg__nombre">V' + hex(i, 1) + '</span>'
        + '<span class="reg__valor">00</span>';
      if (i === 0xF) {
        celda.classList.add('reg--especial');
        celda.title = 'VF no es de uso libre: lo escriben el acarreo y las colisiones.';
      }
      this.celdasV.push(celda.querySelector('.reg__valor'));
      frag.appendChild(celda);
    }
    this.nodoRegs.appendChild(frag);

    this.celdasEsp = {};
    const especiales = [
      ['PC', 'Contador de programa: la direccion de la instruccion que toca'],
      ['I',  'Puntero a memoria, usado para dibujar'],
      ['SP', 'Cima de la pila de llamadas'],
      ['DT', 'Temporizador de retardo (baja a 60 Hz)'],
      ['ST', 'Temporizador de sonido: pita mientras no sea cero'],
    ];
    const frag2 = document.createDocumentFragment();
    for (const [nombre, ayuda] of especiales) {
      const celda = document.createElement('div');
      celda.className = 'reg reg--ancho';
      celda.title = ayuda;
      celda.innerHTML =
        '<span class="reg__nombre">' + nombre + '</span>'
        + '<span class="reg__valor">000</span>';
      this.celdasEsp[nombre] = celda.querySelector('.reg__valor');
      frag2.appendChild(celda);
    }
    this.nodoEspec.appendChild(frag2);
  }

  _construirTeclado() {
    const frag = document.createDocumentFragment();
    for (const fila of DISTRIBUCION_TECLADO) {
      for (const etiqueta of fila) {
        const valor = parseInt(etiqueta, 16);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tecla-hex';
        btn.textContent = etiqueta;
        btn.dataset.tecla = String(valor);

        const pulsar = (ev) => { ev.preventDefault(); this.maquina.pulsar(valor); btn.classList.add('is-activa'); };
        const soltar = () => { this.maquina.soltar(valor); btn.classList.remove('is-activa'); };

        btn.addEventListener('pointerdown', pulsar);
        btn.addEventListener('pointerup', soltar);
        btn.addEventListener('pointerleave', soltar);
        btn.addEventListener('pointercancel', soltar);

        frag.appendChild(btn);
      }
    }
    this.nodoTeclado.appendChild(frag);

    window.addEventListener('keydown', (ev) => {
      if (ev.repeat) return;
      const t = MAPA_TECLAS[ev.key.toLowerCase()];
      if (t === undefined) return;
      ev.preventDefault();
      this.maquina.pulsar(t);
      this._marcarTecla(t, true);
    });

    window.addEventListener('keyup', (ev) => {
      const t = MAPA_TECLAS[ev.key.toLowerCase()];
      if (t === undefined) return;
      this.maquina.soltar(t);
      this._marcarTecla(t, false);
    });
  }

  _marcarTecla(valor, activa) {
    const btn = this.nodoTeclado.querySelector('[data-tecla="' + valor + '"]');
    if (btn) btn.classList.toggle('is-activa', activa);
  }

  _construirSelectorProgramas() {
    const frag = document.createDocumentFragment();
    for (const p of PROGRAMAS) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn--fantasma btn--sm lab-prog';
      btn.dataset.prog = p.id;
      btn.innerHTML = '<strong>' + p.nombre + '</strong>'
        + '<span class="text-xs text-debil">' + p.dificultad + '</span>';
      btn.addEventListener('click', () => this.cargarPrograma(p.id));
      frag.appendChild(btn);
    }
    this.nodoProgs.appendChild(frag);
  }

  _enlazarControles() {
    this.btnCorrer.addEventListener('click', () => this.alternarCarrera());
    this.btnPaso.addEventListener('click', () => { this.pausar(); this.unPaso(); });
    this.btnReinicio.addEventListener('click', () => this.reiniciar());

    this.rangoVel.max = String(VELOCIDADES.length - 1);
    this.rangoVel.value = String(this.velocidad);
    this.rangoVel.addEventListener('input', () => {
      this.velocidad = Number(this.rangoVel.value);
      this.acumulador = 0;
      this._pintarVelocidad();
    });
    this._pintarVelocidad();
  }

  _pintarVelocidad() {
    const v = VELOCIDADES[this.velocidad];
    this.nodoVelTxt.innerHTML = '<strong>' + v.etiqueta + '</strong> · ' + v.nota;
  }

  // ===================================================================
  // Control
  // ===================================================================

  cargarPrograma(id) {
    const p = programaPorId(id);
    this.programa = p;
    this.maquina.cargar(new Uint8Array(p.bytes));
    this.pausar();

    for (const btn of this.nodoProgs.querySelectorAll('.lab-prog')) {
      btn.classList.toggle('is-activo', btn.dataset.prog === id);
    }

    this.nodoResumen.textContent = p.resumen;
    this.nodoNotas.innerHTML = p.notas.map((n) => '<li>' + n + '</li>').join('');
    this.nodoAviso.hidden = true;

    this._pintarTodo();
    this._pintarCodigo();
  }

  reiniciar() {
    const bytes = new Uint8Array(this.programa.bytes);
    this.maquina.cargar(bytes);
    this.pausar();
    this.nodoAviso.hidden = true;
    this._pintarTodo();
    this._pintarCodigo();
  }

  alternarCarrera() {
    if (this.corriendo) this.pausar();
    else this.arrancar();
  }

  arrancar() {
    if (this.maquina.detenido) return;
    this.corriendo = true;
    this.acumulador = 0;
    this.btnCorrer.textContent = 'Pausar';
    this.btnCorrer.classList.add('btn--acento');
    this.btnCorrer.classList.remove('btn--primario');
  }

  pausar() {
    this.corriendo = false;
    this.btnCorrer.textContent = 'Ejecutar';
    this.btnCorrer.classList.remove('btn--acento');
    this.btnCorrer.classList.add('btn--primario');
  }

  unPaso() {
    this.maquina.paso();
    if (this.maquina.detenido) {
      this.pausar();
      this.nodoAviso.textContent = this.maquina.motivoParo;
      this.nodoAviso.hidden = false;
    }
    this._pintarTodo();
    this._pintarCodigo();
  }

  // ===================================================================
  // El bucle de la pagina
  // ===================================================================

  /**
   * requestAnimationFrame nos despierta ~60 veces por segundo, que es
   * justo la frecuencia a la que bajan los temporizadores de CHIP-8.
   * Las instrucciones, en cambio, van a la velocidad que pida el usuario,
   * asi que se acumulan por tiempo transcurrido.
   */
  _bucle(instante) {
    requestAnimationFrame(this._bucle);

    const delta = this.ultimoInstante ? (instante - this.ultimoInstante) / 1000 : 0;
    this.ultimoInstante = instante;

    /* Los dos temporizadores bajan a 60 Hz por reloj de pared, no por
       instrucciones ejecutadas. En el hardware real siguen contando
       aunque el procesador este parado, asi que esto va ANTES de
       comprobar si estamos en marcha: de lo contrario, ir paso a paso
       por un programa que espera al temporizador se quedaria colgado
       para siempre en el bucle de espera. */
    this.maquina.tictac();

    if (!this.corriendo) return;

    const ips = VELOCIDADES[this.velocidad].ips;
    this.acumulador += delta * ips;

    let ejecutadas = 0;
    while (this.acumulador >= 1 && ejecutadas < 2000) {
      this.acumulador -= 1;
      ejecutadas++;

      this.maquina.paso();
      if (this.maquina.detenido) {
        this.pausar();
        this.nodoAviso.textContent = this.maquina.motivoParo;
        this.nodoAviso.hidden = false;
        break;
      }
      if (this.maquina.esperandoTecla >= 0) break;
    }

    if (ejecutadas > 0) {
      this._pintarTodo();
      this._pintarCodigo();
    }
  }

  // ===================================================================
  // Pintado
  // ===================================================================

  _pintarTodo() {
    this._pintarPantalla();
    this._pintarRegistros();
    this._pintarPaso();
    this.nodoCiclos.textContent = this.maquina.ciclos.toLocaleString('es-MX');
  }

  _pintarPantalla() {
    const m = this.maquina;
    const img = this.ctx.createImageData(ANCHO, ALTO);
    const d = img.data;

    /* Verde fosforo sobre negro: no es nostalgia gratuita, es que el
       contraste de un monitor monocromo es exactamente lo que vieron
       quienes escribieron estos programas. */
    for (let i = 0; i < m.pantalla.length; i++) {
      const on = m.pantalla[i];
      const o = i * 4;
      d[o]     = on ? 0x3d : 0x08;
      d[o + 1] = on ? 0xf5 : 0x12;
      d[o + 2] = on ? 0xb0 : 0x0e;
      d[o + 3] = 255;
    }
    this.ctx.putImageData(img, 0, 0);
  }

  _pintarRegistros() {
    const m = this.maquina;
    const cambiados = new Set(
      (m.ultimo?.cambios || []).map((c) => c.que)
    );

    for (let i = 0; i < 16; i++) {
      const celda = this.celdasV[i];
      const txt = hex(m.V[i]);
      if (celda.textContent !== txt) celda.textContent = txt;
      celda.parentElement.classList.toggle(
        'is-cambiado',
        cambiados.has('V' + hex(i, 1)) || (i === 0xF && cambiados.has('VF'))
      );
    }

    this.celdasEsp.PC.textContent = hex(m.PC, 3);
    this.celdasEsp.I.textContent  = hex(m.I, 3);
    this.celdasEsp.SP.textContent = hex(m.SP, 1);
    this.celdasEsp.DT.textContent = String(m.DT).padStart(3, '0');
    this.celdasEsp.ST.textContent = String(m.ST).padStart(3, '0');

    this.celdasEsp.ST.parentElement.classList.toggle('is-sonando', m.pitando);
  }

  _pintarPaso() {
    const u = this.maquina.ultimo;
    if (!u) {
      this.nodoNemonico.textContent = '—';
      this.nodoOpcode.textContent = '----';
      this.nodoDesglose.innerHTML = '';
      this.nodoExplic.textContent = 'Pulsa Paso para ejecutar la primera instruccion.';
      this.nodoCambios.innerHTML = '';
      return;
    }

    this.nodoNemonico.textContent = u.nemonico;
    this.nodoOpcode.textContent = hex(u.opcode, 4);
    this.nodoExplic.textContent = u.explicacion;

    /* El desglose de los 16 bits en sus cuatro campos. Esta es la parte
       que convierte "magia" en "tabla": los bits siempre significan lo
       mismo, en la misma posicion. */
    const op = u.opcode;
    const campos = [
      { etq: 'familia', val: hex((op & 0xF000) >> 12, 1), desc: 'que clase de instruccion es' },
      { etq: 'X',       val: hex((op & 0x0F00) >> 8, 1),  desc: 'primer registro' },
      { etq: 'Y',       val: hex((op & 0x00F0) >> 4, 1),  desc: 'segundo registro' },
      { etq: 'N',       val: hex(op & 0x000F, 1),         desc: 'valor suelto' },
    ];
    this.nodoDesglose.innerHTML = campos.map((c) =>
      '<span class="bitcampo" title="' + c.desc + '">'
      + '<em>' + c.etq + '</em><b>' + c.val + '</b></span>'
    ).join('');

    this.nodoCambios.innerHTML = u.cambios.length
      ? u.cambios.map((c) => {
          const antes = typeof c.antes === 'number' ? hex(c.antes, 2) : c.antes;
          const desp  = typeof c.despues === 'number' ? hex(c.despues, 2) : c.despues;
          return '<li><code>' + c.que + '</code> '
            + '<span class="text-debil">' + antes + '</span> → <strong>' + desp + '</strong></li>';
        }).join('')
      : '<li class="text-debil">Esta instruccion no cambio ningun registro.</li>';
  }

  /**
   * Lista de instrucciones alrededor del PC.
   *
   * Importante para la explicacion: esto desensambla CADA pareja de
   * bytes como si fuera una instruccion, porque no hay forma de saber
   * cuales lo son. Por eso al final de "Pelota con rebotes" aparecen
   * lineas sin sentido: son el dibujo de la pelota leido como codigo.
   */
  _pintarCodigo() {
    const m = this.maquina;
    const fin = INICIO_PROGRAMA + (this.programa?.bytes.length || 0);
    const filas = [];

    for (let dir = INICIO_PROGRAMA; dir < fin; dir += 2) {
      const opcode = (m.memoria[dir] << 8) | m.memoria[dir + 1];
      const actual = dir === m.PC;
      filas.push(
        '<li class="codigo__fila' + (actual ? ' is-actual' : '') + '">'
        + '<span class="codigo__dir">' + hex(dir, 3) + '</span>'
        + '<span class="codigo__bytes">' + hex(m.memoria[dir]) + ' ' + hex(m.memoria[dir + 1]) + '</span>'
        + '<span class="codigo__asm">' + Chip8.desensamblar(opcode) + '</span>'
        + '</li>'
      );
    }

    this.nodoCodigo.innerHTML = filas.join('');

    const activa = this.nodoCodigo.querySelector('.is-actual');
    if (activa) activa.scrollIntoView({ block: 'nearest' });
  }
}

/* Arranque. */
if ($('#lab-pantalla')) {
  new Laboratorio();
}
