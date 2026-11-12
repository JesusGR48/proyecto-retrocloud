/**
 * RetroCloud · Programas de demostracion para CHIP-8
 * =====================================================================
 *
 * Estos cuatro programas estan ESCRITOS A MANO para este proyecto,
 * instruccion por instruccion. No son ROMs descargadas: cada byte de
 * aqui abajo se eligio a proposito, por lo que no hay ninguna cuestion
 * de derechos de autor y se pueden distribuir con el codigo.
 *
 * Tambien por eso son utiles: un juego de verdad ejecuta miles de
 * instrucciones antes de pintar nada, y seguirlo paso a paso no enseña
 * gran cosa. Estos caben en la pantalla enteros.
 *
 * Para leerlos hay que recordar como se parte una instruccion:
 *
 *     6A 00
 *     ^^ ^^
 *     ||  +--- NN: el valor, 0x00
 *     |+------ X : el registro, el A de VA
 *     +------- 6 : la familia, "meter un valor en un registro"
 *
 *   -> LD VA, 0x00   "pon un cero en el registro VA"
 */

'use strict';

export const PROGRAMAS = [

  // ===================================================================
  {
    id: 'primeros-pasos',
    nombre: 'Primeros pasos',
    dificultad: '6 instrucciones',
    resumen: 'Dibuja un 5 en pantalla y se queda quieto. Cabe entero en '
      + 'la cabeza: recorrelo con el boton Paso y habras visto un programa '
      + 'completo de principio a fin.',
    /* 0x200  60 05   LD V0, 5      ; quiero el digito 5
       0x202  F0 29   LD F, V0      ; I = donde vive el dibujo del 5
       0x204  61 1C   LD V1, 28     ; columna
       0x206  62 0D   LD V2, 13     ; fila
       0x208  D1 25   DRW V1,V2,5   ; pintalo, 5 filas de alto
       0x20A  12 0A   JP 0x20A      ; saltar a si mismo = no hacer nada
    */
    bytes: [
      0x60, 0x05,
      0xF0, 0x29,
      0x61, 0x1C,
      0x62, 0x0D,
      0xD1, 0x25,
      0x12, 0x0A,
    ],
    notas: [
      'La instruccion F029 no dibuja nada: solo APUNTA el registro I al '
        + 'sitio donde esta guardado el dibujo del digito. Dibujar es el '
        + 'paso siguiente.',
      'La ultima instruccion salta a su propia direccion. Es la forma '
        + 'clasica de decir "ya termine": la maquina sigue viva, dando '
        + 'vueltas sobre si misma, sin consumir nada.',
    ],
  },

  // ===================================================================
  {
    id: 'contador',
    nombre: 'Contador 0-F',
    dificultad: '15 instrucciones',
    resumen: 'Recorre los digitos del 0 al F en bucle. Aqui aparecen las '
      + 'tres cosas que tiene cualquier programa: un bucle, una espera y '
      + 'una condicion.',
    /* 0x200  6A 00   LD VA, 0      ; contador
       0x202  00 E0   CLS
       0x204  FA 29   LD F, VA
       0x206  6B 1C   LD VB, 28
       0x208  6C 0C   LD VC, 12
       0x20A  DB C5   DRW VB,VC,5
       0x20C  6D 1E   LD VD, 30     ; medio segundo (30/60)
       0x20E  FD 15   LD DT, VD
       0x210  FD 07   LD VD, DT     ; <- bucle de espera
       0x212  3D 00   SE VD, 0      ; si llego a 0, salta el JP
       0x214  12 10   JP 0x210
       0x216  7A 01   ADD VA, 1
       0x218  6E 0F   LD VE, 0x0F
       0x21A  8A E2   AND VA, VE    ; VA = VA & 15  -> vuelve a 0 tras F
       0x21C  12 02   JP 0x202
    */
    bytes: [
      0x6A, 0x00,
      0x00, 0xE0,
      0xFA, 0x29,
      0x6B, 0x1C,
      0x6C, 0x0C,
      0xDB, 0xC5,
      0x6D, 0x1E,
      0xFD, 0x15,
      0xFD, 0x07,
      0x3D, 0x00,
      0x12, 0x10,
      0x7A, 0x01,
      0x6E, 0x0F,
      0x8A, 0xE2,
      0x12, 0x02,
    ],
    notas: [
      'CHIP-8 no tiene ninguna instruccion de "esperar". Lo que hay es un '
        + 'temporizador que baja solo 60 veces por segundo, y el programa '
        + 'da vueltas leyendolo hasta que llega a cero. Las tres '
        + 'instrucciones de 0x210 a 0x214 son esa espera.',
      'Para que el contador vuelva a 0 despues del F no se usa ninguna '
        + 'comparacion: se hace AND con 15, que deja pasar solo los cuatro '
        + 'bits bajos. Un truco de la epoca en que cada instruccion contaba.',
    ],
  },

  // ===================================================================
  {
    id: 'pelota',
    nombre: 'Pelota con rebotes',
    dificultad: '26 instrucciones',
    resumen: 'Una pelota que rebota en los cuatro bordes. Es el esqueleto '
      + 'de cualquier juego: estado, movimiento, colision y repetir.',
    /* VA=x  VB=y  VC=dx  VD=dy  VE=0 (constante)
       0x200  6A 20   LD VA, 32
       0x202  6B 10   LD VB, 16
       0x204  6C 01   LD VC, 1
       0x206  6D 01   LD VD, 1
       0x208  6E 00   LD VE, 0
       0x20A  00 E0   CLS            ; <- inicio del bucle
       0x20C  A2 40   LD I, 0x240    ; el dibujo de la pelota
       0x20E  DA B4   DRW VA,VB,4
       0x210  8A C4   ADD VA, VC
       0x212  8B D4   ADD VB, VD
       0x214  3A 00   SE VA, 0       ; borde izquierdo?
       0x216  12 1A   JP 0x21A
       0x218  8C E7   SUBN VC, VE    ; VC = 0 - VC  (invierte el sentido)
       0x21A  3A 3C   SE VA, 60      ; borde derecho?
       0x21C  12 20   JP 0x220
       0x21E  8C E7   SUBN VC, VE
       0x220  3B 00   SE VB, 0       ; borde superior?
       0x222  12 26   JP 0x226
       0x224  8D E7   SUBN VD, VE
       0x226  3B 1C   SE VB, 28      ; borde inferior?
       0x228  12 2C   JP 0x22C
       0x22A  8D E7   SUBN VD, VE
       0x22C  69 02   LD V9, 2       ; pausa corta
       0x22E  F9 15   LD DT, V9
       0x230  F9 07   LD V9, DT
       0x232  39 00   SE V9, 0
       0x234  12 30   JP 0x230
       0x236  12 0A   JP 0x20A       ; vuelta al principio del bucle
       0x238  relleno hasta 0x240
       0x240  60 F0 F0 60            ; la pelota, 4 filas de 4 bits
    */
    bytes: [
      0x6A, 0x20, 0x6B, 0x10, 0x6C, 0x01, 0x6D, 0x01, 0x6E, 0x00,
      0x00, 0xE0, 0xA2, 0x40, 0xDA, 0xB4, 0x8A, 0xC4, 0x8B, 0xD4,
      0x3A, 0x00, 0x12, 0x1A, 0x8C, 0xE7,
      0x3A, 0x3C, 0x12, 0x20, 0x8C, 0xE7,
      0x3B, 0x00, 0x12, 0x26, 0x8D, 0xE7,
      0x3B, 0x1C, 0x12, 0x2C, 0x8D, 0xE7,
      0x69, 0x02, 0xF9, 0x15, 0xF9, 0x07, 0x39, 0x00, 0x12, 0x30,
      0x12, 0x0A,
      0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
      0x60, 0xF0, 0xF0, 0x60,
    ],
    notas: [
      'Los ultimos cuatro bytes (60 F0 F0 60) NO son instrucciones: son el '
        + 'dibujo de la pelota. Estan en el mismo archivo, mezclados con el '
        + 'codigo, y nada los distingue. Si pones el PC ahi, el emulador '
        + 'intentara ejecutarlos. Ese es el problema de fondo de traducir '
        + 'un binario sin ejecutarlo.',
      'Para invertir el sentido no hay instruccion de negar. Se usa '
        + 'SUBN VC, VE con VE valiendo cero, que calcula 0 - VC. El 1 se '
        + 'convierte en 255, y como la suma da la vuelta a los 8 bits, '
        + 'sumar 255 es lo mismo que restar 1.',
      'Mira el registro VF mientras corre: la pelota al borrarse a si '
        + 'misma provoca colisiones constantemente. En un juego de verdad, '
        + 'ese mismo VF es el que te dice que chocaste con un enemigo.',
    ],
  },

  // ===================================================================
  {
    id: 'teclado',
    nombre: 'Lector de teclado',
    dificultad: '8 instrucciones',
    resumen: 'Muestra en pantalla la tecla que pulses. Sirve para ver que '
      + 'pasa cuando un procesador se queda esperando al usuario.',
    /* 0x200  00 E0   CLS
       0x202  F0 0A   LD V0, K      ; <- aqui se CONGELA la maquina
       0x204  F0 29   LD F, V0
       0x206  61 1C   LD V1, 28
       0x208  62 0C   LD V2, 12
       0x20A  00 E0   CLS
       0x20C  D1 25   DRW V1,V2,5
       0x20E  12 02   JP 0x202
    */
    bytes: [
      0x00, 0xE0,
      0xF0, 0x0A,
      0xF0, 0x29,
      0x61, 0x1C,
      0x62, 0x0C,
      0x00, 0xE0,
      0xD1, 0x25,
      0x12, 0x02,
    ],
    notas: [
      'F00A es de las pocas instrucciones que detienen el procesador de '
        + 'verdad. Fijate en que el PC no se mueve: la maquina ejecuta la '
        + 'misma instruccion una y otra vez hasta que hay una tecla.',
      'Las teclas de CHIP-8 son 0-F porque aquellos ordenadores tenian un '
        + 'teclado hexadecimal de 16 botones, no un teclado de escribir.',
    ],
  },
];

/** Devuelve el programa por su id, o el primero si no existe. */
export function programaPorId(id) {
  return PROGRAMAS.find((p) => p.id === id) || PROGRAMAS[0];
}
