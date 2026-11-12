/**
 * RetroCloud · Nucleo de emulacion CHIP-8
 * =====================================================================
 *
 * Este archivo NO es una libreria de terceros: es un emulador escrito
 * desde cero para el proyecto. Su proposito es didactico, y por eso
 * esta construido al reves de como se construye un emulador normal.
 *
 * Un emulador de produccion (los nucleos libretro que mueven el resto
 * del catalogo) esconde su estado interno y solo expone la pantalla:
 * cuanto menos trabajo haga fuera del bucle, mas rapido va.
 *
 * Aqui queremos justo lo contrario. Cada paso deja un rastro de lo que
 * hizo -- que registro cambio, que direccion se leyo, como se decodifico
 * la instruccion -- para que la interfaz pueda enseñarlo. Es mas lento,
 * y da igual: CHIP-8 corria originalmente a unos cientos de
 * instrucciones por segundo.
 *
 * ---------------------------------------------------------------------
 * QUE ES CHIP-8
 * ---------------------------------------------------------------------
 * No es una consola. Es una maquina virtual que Joseph Weisbecker
 * diseño a mediados de los 70 para los microordenadores COSMAC VIP y
 * Telmac 1800. En lugar de programar aquellas maquinas en su codigo
 * maquina real, se escribia para esta maquina imaginaria, y un
 * interprete de unos pocos cientos de bytes la ejecutaba.
 *
 * Es decir: CHIP-8 ya era emulacion en 1977.
 *
 * Su valor hoy es que tiene 35 instrucciones. Treinta y cinco. El
 * procesador de la PlayStation tiene del orden de cien, mas una unidad
 * de geometria, mas un chip de sonido, mas los tiempos del lector de
 * CD. Pero el BUCLE es exactamente el mismo:
 *
 *      leer 2 bytes  ->  decodificar  ->  ejecutar  ->  avanzar
 *
 * Quien entiende este archivo entiende el principio que mueve a todos
 * los demas emuladores del sitio.
 *
 * ---------------------------------------------------------------------
 * LA MAQUINA
 * ---------------------------------------------------------------------
 *   Memoria    4096 bytes. Los programas se cargan en 0x200 porque los
 *              512 primeros los ocupaba el propio interprete.
 *   Registros  16 de 8 bits, V0 a VF. VF es especial: lo usan el acarreo
 *              y la deteccion de colisiones, asi que no se guarda nada
 *              util en el.
 *   I          Registro de 16 bits. Apunta a memoria (para dibujar).
 *   PC         Contador de programa: la direccion de la instruccion
 *              que toca ejecutar.
 *   Pila       16 niveles, solo para direcciones de retorno.
 *   Pantalla   64 x 32 pixeles, un bit por pixel. Blanco o negro.
 *   Teclado    16 teclas, 0-F (la calculadora hexadecimal de la epoca).
 *   Temporizadores  Dos, que bajan a 60 Hz: uno de retardo y uno de
 *              sonido. El de sonido pita mientras no sea cero.
 */

'use strict';

/* ---------------------------------------------------------------------
   Tipografia incorporada
   ---------------------------------------------------------------------
   Los 16 digitos hexadecimales, dibujados a mano como sprites de 4x5
   pixeles. Viven en el arranque de la memoria y la instruccion FX29
   devuelve la direccion del digito que le pidas.

   Cada byte es una fila; solo cuentan los 4 bits altos. El 0 es:

       0xF0 -> 1111 ....  ####
       0x90 -> 1001 ....  #  #
       0x90 -> 1001 ....  #  #
       0x90 -> 1001 ....  #  #
       0xF0 -> 1111 ....  ####
   --------------------------------------------------------------------- */
const TIPOGRAFIA = [
  0xF0, 0x90, 0x90, 0x90, 0xF0, // 0
  0x20, 0x60, 0x20, 0x20, 0x70, // 1
  0xF0, 0x10, 0xF0, 0x80, 0xF0, // 2
  0xF0, 0x10, 0xF0, 0x10, 0xF0, // 3
  0x90, 0x90, 0xF0, 0x10, 0x10, // 4
  0xF0, 0x80, 0xF0, 0x10, 0xF0, // 5
  0xF0, 0x80, 0xF0, 0x90, 0xF0, // 6
  0xF0, 0x10, 0x20, 0x40, 0x40, // 7
  0xF0, 0x90, 0xF0, 0x90, 0xF0, // 8
  0xF0, 0x90, 0xF0, 0x10, 0xF0, // 9
  0xF0, 0x90, 0xF0, 0x90, 0x90, // A
  0xE0, 0x90, 0xE0, 0x90, 0xE0, // B
  0xF0, 0x80, 0x80, 0x80, 0xF0, // C
  0xE0, 0x90, 0x90, 0x90, 0xE0, // D
  0xF0, 0x80, 0xF0, 0x80, 0xF0, // E
  0xF0, 0x80, 0xF0, 0x80, 0x80, // F
];

export const ANCHO = 64;
export const ALTO = 32;
export const INICIO_PROGRAMA = 0x200;
export const DIR_TIPOGRAFIA = 0x050;

/** Formatea un numero en hexadecimal con ceros a la izquierda. */
export const hex = (n, cifras = 2) =>
  n.toString(16).toUpperCase().padStart(cifras, '0');

export class Chip8 {
  constructor() {
    this.memoria   = new Uint8Array(4096);
    this.V         = new Uint8Array(16);
    this.pantalla  = new Uint8Array(ANCHO * ALTO);
    this.pila      = new Uint16Array(16);
    this.teclas    = new Uint8Array(16);

    this.reiniciar();
  }

  // ===================================================================
  // Estado
  // ===================================================================

  /** Deja la maquina como recien encendida, conservando el programa. */
  reiniciar() {
    this.V.fill(0);
    this.pila.fill(0);
    this.pantalla.fill(0);
    this.teclas.fill(0);

    this.I   = 0;
    this.PC  = INICIO_PROGRAMA;
    this.SP  = 0;              // puntero de pila
    this.DT  = 0;              // temporizador de retardo
    this.ST  = 0;              // temporizador de sonido

    this.ciclos      = 0;      // instrucciones ejecutadas
    this.detenido    = false;  // true si se topo con algo que no sabe hacer
    this.motivoParo  = null;
    this.esperandoTecla = -1;  // registro que espera una pulsacion, o -1

    /* Rastro del ultimo paso. Esto es lo que lee la interfaz y lo que
       distingue a este emulador de uno normal. */
    this.ultimo = null;

    this._cargarTipografia();
  }

  _cargarTipografia() {
    for (let i = 0; i < TIPOGRAFIA.length; i++) {
      this.memoria[DIR_TIPOGRAFIA + i] = TIPOGRAFIA[i];
    }
  }

  /** Borra la memoria y carga un programa en 0x200. */
  cargar(bytes) {
    this.memoria.fill(0);
    this.reiniciar();

    if (bytes.length > this.memoria.length - INICIO_PROGRAMA) {
      throw new Error('El programa no cabe en la memoria de CHIP-8.');
    }
    this.memoria.set(bytes, INICIO_PROGRAMA);
    this.tamanoPrograma = bytes.length;
  }

  // ===================================================================
  // Teclado
  // ===================================================================

  pulsar(tecla) {
    this.teclas[tecla & 0xF] = 1;

    /* FX0A congela la maquina hasta que alguien pulsa algo. Si estamos
       en esa espera, esta pulsacion la desbloquea. */
    if (this.esperandoTecla >= 0) {
      this.V[this.esperandoTecla] = tecla & 0xF;
      this.esperandoTecla = -1;
    }
  }

  soltar(tecla) {
    this.teclas[tecla & 0xF] = 0;
  }

  // ===================================================================
  // Temporizadores
  // ===================================================================

  /**
   * Los dos temporizadores bajan a 60 Hz, con independencia de la
   * velocidad a la que se ejecuten las instrucciones. Por eso tienen
   * su propia llamada: no van dentro del bucle de instrucciones.
   */
  tictac() {
    if (this.DT > 0) this.DT--;
    if (this.ST > 0) this.ST--;
  }

  get pitando() {
    return this.ST > 0;
  }

  // ===================================================================
  // EL BUCLE
  // ===================================================================

  /**
   * Ejecuta UNA instruccion y devuelve el rastro de lo que hizo.
   *
   * Las cuatro fases estan separadas y etiquetadas a proposito, porque
   * son las cuatro fases de cualquier procesador real.
   */
  paso() {
    if (this.detenido) return this.ultimo;

    /* Si esperamos una tecla, el PC no avanza. La maquina esta
       literalmente congelada en la misma instruccion. */
    if (this.esperandoTecla >= 0) {
      this.ultimo = {
        pc: this.PC,
        opcode: (this.memoria[this.PC] << 8) | this.memoria[this.PC + 1],
        nemonico: 'LD V' + hex(this.esperandoTecla, 1) + ', K',
        explicacion: 'Congelado. La maquina no avanza hasta que se pulse una tecla.',
        cambios: [],
        esperando: true,
      };
      return this.ultimo;
    }

    const pcInicial = this.PC;

    // --- FASE 1 · BUSCAR -------------------------------------------
    // Toda instruccion de CHIP-8 mide exactamente 2 bytes. Se leen en
    // orden big-endian: el primero es la mitad alta.
    const alto = this.memoria[this.PC];
    const bajo = this.memoria[this.PC + 1];
    const opcode = (alto << 8) | bajo;

    // --- FASE 2 · DECODIFICAR --------------------------------------
    // Repartir los 16 bits en los campos que define el formato. Esto
    // no es interpretacion: las posiciones son fijas.
    const x   = (opcode & 0x0F00) >> 8;   // indice de registro
    const y   = (opcode & 0x00F0) >> 4;   // indice de registro
    const n   =  opcode & 0x000F;         // nibble suelto
    const nn  =  opcode & 0x00FF;         // byte inmediato
    const nnn =  opcode & 0x0FFF;         // direccion de 12 bits

    // El PC avanza ANTES de ejecutar. Si la instruccion resulta ser un
    // salto, lo sobreescribira; si no, ya queda apuntando a la
    // siguiente. Los procesadores reales hacen justo esto.
    this.PC = (this.PC + 2) & 0xFFF;

    const cambios = [];
    const anotar = (que, antes, despues) => {
      if (antes !== despues) cambios.push({ que, antes, despues });
    };

    // --- FASE 3 · EJECUTAR -----------------------------------------
    let nemonico = '???';
    let explicacion = '';

    switch (opcode & 0xF000) {

      case 0x0000:
        if (opcode === 0x00E0) {
          this.pantalla.fill(0);
          nemonico = 'CLS';
          explicacion = 'Apaga los 2048 pixeles de la pantalla.';
          cambios.push({ que: 'pantalla', antes: '-', despues: 'borrada' });

        } else if (opcode === 0x00EE) {
          const antes = this.PC;
          this.SP = (this.SP - 1) & 0xF;
          this.PC = this.pila[this.SP];
          nemonico = 'RET';
          explicacion = 'Vuelve de una subrutina: saca de la pila la direccion '
            + 'guardada al llamarla (0x' + hex(this.PC, 3) + ').';
          anotar('PC', antes, this.PC);
          anotar('SP', (this.SP + 1) & 0xF, this.SP);

        } else {
          /* 0NNN llamaba a codigo maquina del COSMAC VIP de verdad.
             Ningun emulador moderno lo implementa, porque significaria
             emular tambien aquel ordenador entero. */
          nemonico = 'SYS 0x' + hex(nnn, 3);
          explicacion = 'Instruccion obsoleta de 1977: saltaba a codigo maquina '
            + 'nativo del COSMAC VIP. Se ignora.';
        }
        break;

      case 0x1000:
        anotar('PC', this.PC, nnn);
        this.PC = nnn;
        nemonico = 'JP 0x' + hex(nnn, 3);
        explicacion = 'Salto incondicional: la ejecucion continua en 0x'
          + hex(nnn, 3) + '.';
        break;

      case 0x2000: {
        this.pila[this.SP] = this.PC;
        this.SP = (this.SP + 1) & 0xF;
        anotar('SP', (this.SP - 1) & 0xF, this.SP);
        anotar('PC', this.PC, nnn);
        this.PC = nnn;
        nemonico = 'CALL 0x' + hex(nnn, 3);
        explicacion = 'Llama a una subrutina. Antes de saltar guarda en la pila '
          + 'la direccion de vuelta, para que RET sepa a donde regresar.';
        break;
      }

      case 0x3000:
        nemonico = 'SE V' + hex(x, 1) + ', 0x' + hex(nn);
        if (this.V[x] === nn) {
          this.PC = (this.PC + 2) & 0xFFF;
          explicacion = 'V' + hex(x, 1) + ' vale 0x' + hex(nn)
            + ', asi que SALTA la siguiente instruccion.';
          cambios.push({ que: 'PC', antes: pcInicial + 2, despues: this.PC });
        } else {
          explicacion = 'V' + hex(x, 1) + ' vale 0x' + hex(this.V[x])
            + ', no 0x' + hex(nn) + ': sigue con la siguiente instruccion.';
        }
        break;

      case 0x4000:
        nemonico = 'SNE V' + hex(x, 1) + ', 0x' + hex(nn);
        if (this.V[x] !== nn) {
          this.PC = (this.PC + 2) & 0xFFF;
          explicacion = 'V' + hex(x, 1) + ' NO vale 0x' + hex(nn)
            + ': salta la siguiente instruccion.';
          cambios.push({ que: 'PC', antes: pcInicial + 2, despues: this.PC });
        } else {
          explicacion = 'V' + hex(x, 1) + ' si vale 0x' + hex(nn)
            + ': no salta nada.';
        }
        break;

      case 0x5000:
        nemonico = 'SE V' + hex(x, 1) + ', V' + hex(y, 1);
        if (this.V[x] === this.V[y]) {
          this.PC = (this.PC + 2) & 0xFFF;
          explicacion = 'Los dos registros son iguales: salta la siguiente.';
          cambios.push({ que: 'PC', antes: pcInicial + 2, despues: this.PC });
        } else {
          explicacion = 'Los registros son distintos: no salta.';
        }
        break;

      case 0x6000: {
        const antes = this.V[x];
        this.V[x] = nn;
        nemonico = 'LD V' + hex(x, 1) + ', 0x' + hex(nn);
        explicacion = 'Mete el valor 0x' + hex(nn) + ' (' + nn + ' en decimal) '
          + 'en el registro V' + hex(x, 1) + '.';
        anotar('V' + hex(x, 1), antes, this.V[x]);
        break;
      }

      case 0x7000: {
        const antes = this.V[x];
        this.V[x] = (this.V[x] + nn) & 0xFF;
        nemonico = 'ADD V' + hex(x, 1) + ', 0x' + hex(nn);
        explicacion = 'Suma 0x' + hex(nn) + ' a V' + hex(x, 1)
          + '. Ojo: esta suma NO toca el acarreo VF.';
        anotar('V' + hex(x, 1), antes, this.V[x]);
        break;
      }

      case 0x8000: {
        const antesX = this.V[x];
        const antesF = this.V[0xF];

        switch (n) {
          case 0x0:
            this.V[x] = this.V[y];
            nemonico = 'LD V' + hex(x, 1) + ', V' + hex(y, 1);
            explicacion = 'Copia V' + hex(y, 1) + ' dentro de V' + hex(x, 1) + '.';
            break;

          case 0x1:
            this.V[x] |= this.V[y];
            nemonico = 'OR V' + hex(x, 1) + ', V' + hex(y, 1);
            explicacion = 'OR bit a bit entre los dos registros.';
            break;

          case 0x2:
            this.V[x] &= this.V[y];
            nemonico = 'AND V' + hex(x, 1) + ', V' + hex(y, 1);
            explicacion = 'AND bit a bit. Sirve para quedarse solo con ciertos bits.';
            break;

          case 0x3:
            this.V[x] ^= this.V[y];
            nemonico = 'XOR V' + hex(x, 1) + ', V' + hex(y, 1);
            explicacion = 'XOR bit a bit.';
            break;

          case 0x4: {
            const suma = this.V[x] + this.V[y];
            this.V[x] = suma & 0xFF;
            this.V[0xF] = suma > 0xFF ? 1 : 0;
            nemonico = 'ADD V' + hex(x, 1) + ', V' + hex(y, 1);
            explicacion = 'Suma con acarreo: ' + (suma > 0xFF
              ? 'el resultado paso de 255, asi que VF se pone a 1 y el valor da la vuelta.'
              : 'cabe en 8 bits, VF queda a 0.');
            break;
          }

          case 0x5: {
            const noHayPrestamo = this.V[x] >= this.V[y] ? 1 : 0;
            this.V[x] = (this.V[x] - this.V[y]) & 0xFF;
            this.V[0xF] = noHayPrestamo;
            nemonico = 'SUB V' + hex(x, 1) + ', V' + hex(y, 1);
            explicacion = 'Resta V' + hex(y, 1) + ' de V' + hex(x, 1)
              + '. VF vale 1 si NO hubo prestamo.';
            break;
          }

          case 0x6: {
            const bit = this.V[x] & 1;
            this.V[x] >>= 1;
            this.V[0xF] = bit;
            nemonico = 'SHR V' + hex(x, 1);
            explicacion = 'Desplaza un bit a la derecha (dividir entre 2). '
              + 'El bit que se cae se guarda en VF.';
            break;
          }

          case 0x7: {
            const noHayPrestamo = this.V[y] >= this.V[x] ? 1 : 0;
            this.V[x] = (this.V[y] - this.V[x]) & 0xFF;
            this.V[0xF] = noHayPrestamo;
            nemonico = 'SUBN V' + hex(x, 1) + ', V' + hex(y, 1);
            explicacion = 'Resta al reves: V' + hex(x, 1) + ' = V' + hex(y, 1)
              + ' - V' + hex(x, 1) + '. Con V' + hex(y, 1)
              + ' a cero, sirve para cambiar un numero de signo.';
            break;
          }

          case 0xE: {
            const bit = (this.V[x] & 0x80) >> 7;
            this.V[x] = (this.V[x] << 1) & 0xFF;
            this.V[0xF] = bit;
            nemonico = 'SHL V' + hex(x, 1);
            explicacion = 'Desplaza un bit a la izquierda (multiplicar por 2).';
            break;
          }

          default:
            return this._desconocido(opcode, pcInicial);
        }

        anotar('V' + hex(x, 1), antesX, this.V[x]);
        anotar('VF', antesF, this.V[0xF]);
        break;
      }

      case 0x9000:
        nemonico = 'SNE V' + hex(x, 1) + ', V' + hex(y, 1);
        if (this.V[x] !== this.V[y]) {
          this.PC = (this.PC + 2) & 0xFFF;
          explicacion = 'Los registros son distintos: salta la siguiente instruccion.';
          cambios.push({ que: 'PC', antes: pcInicial + 2, despues: this.PC });
        } else {
          explicacion = 'Los registros son iguales: no salta.';
        }
        break;

      case 0xA000: {
        const antes = this.I;
        this.I = nnn;
        nemonico = 'LD I, 0x' + hex(nnn, 3);
        explicacion = 'Apunta el registro I a la direccion 0x' + hex(nnn, 3)
          + '. Casi siempre es el paso previo a dibujar algo.';
        anotar('I', antes, this.I);
        break;
      }

      case 0xB000: {
        const destino = (nnn + this.V[0]) & 0xFFF;
        anotar('PC', this.PC, destino);
        this.PC = destino;
        nemonico = 'JP V0, 0x' + hex(nnn, 3);
        explicacion = 'Salto calculado: destino = 0x' + hex(nnn, 3) + ' + V0. '
          + 'Este es el tipo de salto que hace imposible traducir un binario '
          + 'sin ejecutarlo.';
        break;
      }

      case 0xC000: {
        const antes = this.V[x];
        const azar = Math.floor(Math.random() * 256);
        this.V[x] = azar & nn;
        nemonico = 'RND V' + hex(x, 1) + ', 0x' + hex(nn);
        explicacion = 'Numero al azar (' + azar + ') filtrado con AND 0x'
          + hex(nn) + '.';
        anotar('V' + hex(x, 1), antes, this.V[x]);
        break;
      }

      // ---------------------------------------------------------------
      // DXYN · dibujar. La instruccion mas interesante de CHIP-8.
      // ---------------------------------------------------------------
      case 0xD000: {
        const antesF = this.V[0xF];
        const px = this.V[x] % ANCHO;
        const py = this.V[y] % ALTO;
        let colision = 0;

        /* Los sprites se dibujan con XOR, no pintando encima. Esto tiene
           dos consecuencias preciosas:
             1. Dibujar lo mismo dos veces lo borra. Mover algo es
                redibujarlo en su sitio viejo y pintarlo en el nuevo.
             2. Si al dibujar se apaga un pixel que estaba encendido,
                es que dos cosas se solapan. Eso es una COLISION, y se
                avisa en VF.
           La deteccion de choques de un juego de CHIP-8 sale gratis del
           propio sistema de dibujo. */
        for (let fila = 0; fila < n; fila++) {
          const byteSprite = this.memoria[(this.I + fila) & 0xFFF];
          const posY = py + fila;
          if (posY >= ALTO) break;            // se recorta, no da la vuelta

          for (let col = 0; col < 8; col++) {
            const bit = (byteSprite >> (7 - col)) & 1;
            if (!bit) continue;

            const posX = px + col;
            if (posX >= ANCHO) break;

            const idx = posY * ANCHO + posX;
            if (this.pantalla[idx]) colision = 1;
            this.pantalla[idx] ^= 1;
          }
        }

        this.V[0xF] = colision;
        nemonico = 'DRW V' + hex(x, 1) + ', V' + hex(y, 1) + ', ' + n;
        explicacion = 'Dibuja ' + n + ' filas desde la direccion I (0x'
          + hex(this.I, 3) + ') en (' + px + ', ' + py + ') usando XOR. '
          + (colision
              ? 'Hubo colision: se apago un pixel encendido, VF = 1.'
              : 'Sin colision, VF = 0.');
        cambios.push({ que: 'pantalla', antes: '-', despues: n + ' filas' });
        anotar('VF', antesF, this.V[0xF]);
        break;
      }

      case 0xE000:
        if (nn === 0x9E) {
          nemonico = 'SKP V' + hex(x, 1);
          if (this.teclas[this.V[x] & 0xF]) {
            this.PC = (this.PC + 2) & 0xFFF;
            explicacion = 'La tecla ' + hex(this.V[x] & 0xF, 1)
              + ' esta pulsada: salta la siguiente instruccion.';
          } else {
            explicacion = 'La tecla ' + hex(this.V[x] & 0xF, 1)
              + ' no esta pulsada: no salta.';
          }
        } else if (nn === 0xA1) {
          nemonico = 'SKNP V' + hex(x, 1);
          if (!this.teclas[this.V[x] & 0xF]) {
            this.PC = (this.PC + 2) & 0xFFF;
            explicacion = 'La tecla ' + hex(this.V[x] & 0xF, 1)
              + ' NO esta pulsada: salta la siguiente instruccion.';
          } else {
            explicacion = 'La tecla ' + hex(this.V[x] & 0xF, 1)
              + ' esta pulsada: no salta.';
          }
        } else {
          return this._desconocido(opcode, pcInicial);
        }
        break;

      case 0xF000:
        switch (nn) {
          case 0x07: {
            const antes = this.V[x];
            this.V[x] = this.DT;
            nemonico = 'LD V' + hex(x, 1) + ', DT';
            explicacion = 'Lee el temporizador de retardo (' + this.DT + ').';
            anotar('V' + hex(x, 1), antes, this.V[x]);
            break;
          }

          case 0x0A:
            this.esperandoTecla = x;
            nemonico = 'LD V' + hex(x, 1) + ', K';
            explicacion = 'Detiene la maquina entera hasta que se pulse una tecla. '
              + 'El PC no avanza: se queda clavado aqui.';
            cambios.push({ que: 'estado', antes: 'corriendo', despues: 'esperando tecla' });
            break;

          case 0x15: {
            const antes = this.DT;
            this.DT = this.V[x];
            nemonico = 'LD DT, V' + hex(x, 1);
            explicacion = 'Arranca el temporizador de retardo en ' + this.DT
              + '. Bajara solo, a 60 por segundo.';
            anotar('DT', antes, this.DT);
            break;
          }

          case 0x18: {
            const antes = this.ST;
            this.ST = this.V[x];
            nemonico = 'LD ST, V' + hex(x, 1);
            explicacion = 'Arranca el temporizador de sonido: pita mientras no sea cero.';
            anotar('ST', antes, this.ST);
            break;
          }

          case 0x1E: {
            const antes = this.I;
            this.I = (this.I + this.V[x]) & 0xFFF;
            nemonico = 'ADD I, V' + hex(x, 1);
            explicacion = 'Avanza el puntero I en ' + this.V[x] + ' bytes.';
            anotar('I', antes, this.I);
            break;
          }

          case 0x29: {
            const antes = this.I;
            this.I = DIR_TIPOGRAFIA + (this.V[x] & 0xF) * 5;
            nemonico = 'LD F, V' + hex(x, 1);
            explicacion = 'Apunta I al dibujo del digito '
              + hex(this.V[x] & 0xF, 1) + ' en la tipografia incorporada.';
            anotar('I', antes, this.I);
            break;
          }

          case 0x33: {
            const v = this.V[x];
            this.memoria[this.I]             = Math.floor(v / 100);
            this.memoria[(this.I + 1) & 0xFFF] = Math.floor(v / 10) % 10;
            this.memoria[(this.I + 2) & 0xFFF] = v % 10;
            nemonico = 'LD B, V' + hex(x, 1);
            explicacion = 'Parte el numero ' + v + ' en sus tres cifras decimales ('
              + Math.floor(v / 100) + ', ' + (Math.floor(v / 10) % 10) + ', '
              + (v % 10) + ') y las deja en memoria. Asi se enseña un marcador.';
            cambios.push({ que: 'memoria', antes: '-', despues: '3 bytes en 0x' + hex(this.I, 3) });
            break;
          }

          case 0x55:
            for (let i = 0; i <= x; i++) {
              this.memoria[(this.I + i) & 0xFFF] = this.V[i];
            }
            nemonico = 'LD [I], V' + hex(x, 1);
            explicacion = 'Vuelca V0..V' + hex(x, 1) + ' a memoria desde 0x'
              + hex(this.I, 3) + '.';
            cambios.push({ que: 'memoria', antes: '-', despues: (x + 1) + ' bytes escritos' });
            break;

          case 0x65:
            for (let i = 0; i <= x; i++) {
              this.V[i] = this.memoria[(this.I + i) & 0xFFF];
            }
            nemonico = 'LD V' + hex(x, 1) + ', [I]';
            explicacion = 'Rellena V0..V' + hex(x, 1) + ' leyendo memoria desde 0x'
              + hex(this.I, 3) + '.';
            cambios.push({ que: 'V0..V' + hex(x, 1), antes: '-', despues: 'releidos' });
            break;

          default:
            return this._desconocido(opcode, pcInicial);
        }
        break;

      default:
        return this._desconocido(opcode, pcInicial);
    }

    // --- FASE 4 · DEJAR CONSTANCIA ---------------------------------
    this.ciclos++;
    this.ultimo = {
      pc: pcInicial,
      opcode,
      nemonico,
      explicacion,
      cambios,
      esperando: false,
    };
    return this.ultimo;
  }

  /**
   * Opcode que no existe.
   *
   * Un emulador real tiene varias politicas posibles aqui: ignorarlo,
   * imitar el fallo del hardware original, o parar. Paramos, porque en
   * una herramienta didactica el error silencioso es el peor resultado:
   * el alumno ve una pantalla negra y no sabe por que.
   */
  _desconocido(opcode, pc) {
    this.detenido = true;
    this.motivoParo = 'Opcode 0x' + hex(opcode, 4) + ' en 0x' + hex(pc, 3)
      + ': no corresponde a ninguna de las 35 instrucciones de CHIP-8. '
      + 'Probablemente el PC se salio del codigo y esta leyendo datos '
      + '(graficos, texto) como si fueran instrucciones.';

    this.ultimo = {
      pc,
      opcode,
      nemonico: 'DESCONOCIDO',
      explicacion: this.motivoParo,
      cambios: [],
      esperando: false,
    };
    return this.ultimo;
  }

  // ===================================================================
  // Desensamblador
  // ===================================================================

  /**
   * Traduce 2 bytes a texto legible SIN ejecutarlos.
   *
   * Es lo mismo que hice a mano con los bytes del Metal Slug: mirar el
   * opcode en una tabla. Fijate en que no puede saber si esos 2 bytes
   * son de verdad una instruccion o son parte de un dibujo: ese es,
   * exactamente, el problema que hace imposible traducir un binario
   * entero a otro lenguaje sin ejecutarlo.
   */
  static desensamblar(opcode) {
    const x   = (opcode & 0x0F00) >> 8;
    const y   = (opcode & 0x00F0) >> 4;
    const n   =  opcode & 0x000F;
    const nn  =  opcode & 0x00FF;
    const nnn =  opcode & 0x0FFF;
    const Vx  = 'V' + hex(x, 1);
    const Vy  = 'V' + hex(y, 1);

    switch (opcode & 0xF000) {
      case 0x0000:
        if (opcode === 0x00E0) return 'CLS';
        if (opcode === 0x00EE) return 'RET';
        return 'SYS 0x' + hex(nnn, 3);
      case 0x1000: return 'JP 0x' + hex(nnn, 3);
      case 0x2000: return 'CALL 0x' + hex(nnn, 3);
      case 0x3000: return 'SE ' + Vx + ', 0x' + hex(nn);
      case 0x4000: return 'SNE ' + Vx + ', 0x' + hex(nn);
      case 0x5000: return 'SE ' + Vx + ', ' + Vy;
      case 0x6000: return 'LD ' + Vx + ', 0x' + hex(nn);
      case 0x7000: return 'ADD ' + Vx + ', 0x' + hex(nn);
      case 0x8000:
        switch (n) {
          case 0x0: return 'LD ' + Vx + ', ' + Vy;
          case 0x1: return 'OR ' + Vx + ', ' + Vy;
          case 0x2: return 'AND ' + Vx + ', ' + Vy;
          case 0x3: return 'XOR ' + Vx + ', ' + Vy;
          case 0x4: return 'ADD ' + Vx + ', ' + Vy;
          case 0x5: return 'SUB ' + Vx + ', ' + Vy;
          case 0x6: return 'SHR ' + Vx;
          case 0x7: return 'SUBN ' + Vx + ', ' + Vy;
          case 0xE: return 'SHL ' + Vx;
          default:  return '--';
        }
      case 0x9000: return 'SNE ' + Vx + ', ' + Vy;
      case 0xA000: return 'LD I, 0x' + hex(nnn, 3);
      case 0xB000: return 'JP V0, 0x' + hex(nnn, 3);
      case 0xC000: return 'RND ' + Vx + ', 0x' + hex(nn);
      case 0xD000: return 'DRW ' + Vx + ', ' + Vy + ', ' + n;
      case 0xE000:
        if (nn === 0x9E) return 'SKP ' + Vx;
        if (nn === 0xA1) return 'SKNP ' + Vx;
        return '--';
      case 0xF000:
        switch (nn) {
          case 0x07: return 'LD ' + Vx + ', DT';
          case 0x0A: return 'LD ' + Vx + ', K';
          case 0x15: return 'LD DT, ' + Vx;
          case 0x18: return 'LD ST, ' + Vx;
          case 0x1E: return 'ADD I, ' + Vx;
          case 0x29: return 'LD F, ' + Vx;
          case 0x33: return 'LD B, ' + Vx;
          case 0x55: return 'LD [I], ' + Vx;
          case 0x65: return 'LD ' + Vx + ', [I]';
          default:   return '--';
        }
      default: return '--';
    }
  }
}
