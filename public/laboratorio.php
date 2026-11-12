<?php
/**
 * RetroCloud · Laboratorio de emulacion
 * ---------------------------------------------------------------------
 * El resto del sitio USA emuladores. Esta pagina enseña COMO funcionan,
 * ejecutando un CHIP-8 escrito desde cero para el proyecto con todo su
 * estado interno a la vista.
 *
 * No necesita sesion: es material divulgativo y no toca la base de
 * datos ni el almacenamiento. Tampoco necesita 'unsafe-eval' en la CSP,
 * porque aqui no hay WebAssembly ni codigo generado en tiempo de
 * ejecucion: es JavaScript normal interpretando bytes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use RetroCloud\Validator;

$e = static fn(mixed $v): string => Validator::e($v);

$tituloPagina = 'Laboratorio · como funciona un emulador';
$paginaActual = 'laboratorio';
$descripcion  = 'Emulador de CHIP-8 escrito desde cero, con los registros, '
              . 'la memoria y el desensamblado a la vista paso a paso.';
$scriptModulo = 'assets/js/laboratorio.js';

require __DIR__ . '/partes/cabecera.php';
?>

<section class="lab-intro">
  <p class="lab-etiqueta">Extra del proyecto</p>
  <h1 class="lab-titulo">¿Que hace un emulador por dentro?</h1>
  <p class="lab-bajada">
    En el resto de RetroCloud los juegos los mueven nucleos de
    <strong>libretro</strong>: decenas de miles de lineas de C compiladas a
    WebAssembly. Funcionan de maravilla y son completamente opacos.
  </p>
  <p class="lab-bajada">
    Esta pagina hace lo contrario. Debajo hay un emulador de
    <strong>CHIP-8</strong> escrito desde cero para este proyecto, con todo
    su interior expuesto: cada registro, cada byte de memoria y la
    traduccion de cada instruccion segun se ejecuta. Es el mismo bucle de
    cuatro pasos que usan los emuladores de PlayStation o Neo Geo, solo que
    con 35 instrucciones en lugar de cientos.
  </p>
</section>

<?php /* --- Ciclo de ejecucion: las cuatro fases ------------------- */ ?>
<ol class="lab-fases">
  <li>
    <span class="lab-fases__num">1</span>
    <strong>Buscar</strong>
    <p>Leer los 2 bytes que hay en la direccion del contador de programa.</p>
  </li>
  <li>
    <span class="lab-fases__num">2</span>
    <strong>Decodificar</strong>
    <p>Repartir esos 16 bits en campos fijos: familia, registros, valor.</p>
  </li>
  <li>
    <span class="lab-fases__num">3</span>
    <strong>Ejecutar</strong>
    <p>Hacer lo que digan: sumar, saltar, dibujar, esperar.</p>
  </li>
  <li>
    <span class="lab-fases__num">4</span>
    <strong>Avanzar</strong>
    <p>Mover el contador y volver a empezar. Para siempre.</p>
  </li>
</ol>

<?php /* --- Seleccion de programa ------------------------------------ */ ?>
<section class="tarjeta lab-seccion">
  <h2 class="lab-h2">1 · Elige un programa</h2>
  <p class="text-tenue text-sm">
    Los cuatro estan escritos a mano, instruccion por instruccion, para este
    proyecto. Por eso se pueden distribuir con el codigo sin ningun problema
    de licencias: no son ROMs descargadas.
  </p>
  <div class="lab-programas" id="lab-programas"></div>
  <p class="lab-resumen" id="lab-resumen"></p>
</section>

<?php /* --- El laboratorio propiamente dicho ------------------------- */ ?>
<div class="lab-rejilla">

  <!-- Columna izquierda: pantalla, mandos y teclado -->
  <section class="tarjeta lab-panel">
    <h2 class="lab-h2">2 · La maquina</h2>

    <div class="lab-pantalla-marco">
      <canvas id="lab-pantalla" width="64" height="32"
              aria-label="Pantalla del emulador CHIP-8, 64 por 32 pixeles"></canvas>
    </div>
    <p class="text-xs text-debil lab-centro">
      64 &times; 32 pixeles. 2048 en total, de un solo bit cada uno.
    </p>

    <div class="lab-mandos">
      <button type="button" class="btn btn--primario" id="lab-correr">Ejecutar</button>
      <button type="button" class="btn btn--fantasma" id="lab-paso">Paso</button>
      <button type="button" class="btn btn--fantasma" id="lab-reinicio">Reiniciar</button>
    </div>

    <label class="lab-velocidad">
      <span class="text-xs text-tenue">Velocidad</span>
      <input type="range" id="lab-velocidad" min="0" max="3" step="1" value="0">
      <span class="text-xs" id="lab-velocidad-txt"></span>
    </label>

    <p class="lab-aviso" id="lab-aviso" hidden></p>

    <h3 class="lab-h3">Teclado hexadecimal</h3>
    <p class="text-xs text-debil">
      Aquellos ordenadores no tenian teclado de escribir, sino 16 botones
      del 0 al F. Usa el raton o las teclas
      <kbd>1</kbd><kbd>2</kbd><kbd>3</kbd><kbd>4</kbd> /
      <kbd>Q</kbd><kbd>W</kbd><kbd>E</kbd><kbd>R</kbd> /
      <kbd>A</kbd><kbd>S</kbd><kbd>D</kbd><kbd>F</kbd> /
      <kbd>Z</kbd><kbd>X</kbd><kbd>C</kbd><kbd>V</kbd>.
    </p>
    <div class="lab-teclado" id="lab-teclado"></div>
  </section>

  <!-- Columna derecha: el interior -->
  <section class="tarjeta lab-panel">
    <h2 class="lab-h2">3 · El interior</h2>

    <h3 class="lab-h3">Instruccion en curso</h3>
    <div class="lab-instruccion">
      <code class="lab-opcode" id="lab-opcode">----</code>
      <span class="lab-nemonico" id="lab-nemonico">&mdash;</span>
    </div>

    <div class="lab-desglose" id="lab-desglose"></div>

    <p class="lab-explicacion" id="lab-explicacion">
      Pulsa <strong>Paso</strong> para ejecutar la primera instruccion.
    </p>

    <h3 class="lab-h3">Que cambio</h3>
    <ul class="lab-cambios" id="lab-cambios"></ul>

    <h3 class="lab-h3">Registros</h3>
    <div class="lab-registros" id="lab-registros"></div>

    <h3 class="lab-h3">Registros especiales</h3>
    <div class="lab-registros lab-registros--esp" id="lab-especiales"></div>

    <p class="text-xs text-debil lab-contador">
      Instrucciones ejecutadas: <strong id="lab-ciclos">0</strong>
    </p>
  </section>
</div>

<?php /* --- Desensamblado -------------------------------------------- */ ?>
<section class="tarjeta lab-seccion">
  <h2 class="lab-h2">4 · El programa, traducido</h2>
  <p class="text-tenue text-sm">
    Esto es un <strong>desensamblador</strong>: toma cada pareja de bytes y la
    traduce a texto legible, igual que se hace a mano con un binario de
    PlayStation. La fila resaltada es la que toca ejecutar.
  </p>
  <p class="text-tenue text-sm">
    Fijate en algo al final de <em>Pelota con rebotes</em>: aparecen lineas
    sin sentido. No es un fallo. Son los cuatro bytes del dibujo de la
    pelota, leidos como si fueran codigo. <strong>Nada en el archivo
    distingue un dibujo de una instruccion</strong>, y por eso no se puede
    traducir un binario entero a otro lenguaje sin ejecutarlo.
  </p>
  <ol class="lab-codigo" id="lab-codigo"></ol>
</section>

<?php /* --- Notas del programa --------------------------------------- */ ?>
<section class="tarjeta lab-seccion">
  <h2 class="lab-h2">5 · Detalles de este programa</h2>
  <ul class="lab-notas" id="lab-notas"></ul>
</section>

<?php /* --- Cierre: la conexion con el resto del sitio --------------- */ ?>
<section class="tarjeta lab-seccion lab-cierre">
  <h2 class="lab-h2">Y esto, ¿que tiene que ver con los juegos del catalogo?</h2>
  <p>
    Todo. El bucle que acabas de ver paso a paso es <em>exactamente</em> el
    mismo que ejecuta un juego de Neo Geo o de PlayStation en este sitio.
    Lo que cambia es la escala:
  </p>

  <div class="tabla-envoltorio">
    <table class="tabla lab-tabla">
      <thead>
        <tr>
          <th scope="col"></th>
          <th scope="col">CHIP-8 (esta pagina)</th>
          <th scope="col">PlayStation (catalogo)</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <th scope="row">Instrucciones distintas</th>
          <td>35</td>
          <td>~100, mas una unidad de geometria</td>
        </tr>
        <tr>
          <th scope="row">Tamaño de instruccion</th>
          <td>2 bytes</td>
          <td>4 bytes (MIPS R3000A)</td>
        </tr>
        <tr>
          <th scope="row">Pantalla</th>
          <td>64 &times; 32, un bit</td>
          <td>320 &times; 240, 16 bits, con GPU propia</td>
        </tr>
        <tr>
          <th scope="row">Velocidad</th>
          <td>Cientos por segundo</td>
          <td>33 millones por segundo</td>
        </tr>
        <tr>
          <th scope="row">Escrito en</th>
          <td>JavaScript, en este sitio</td>
          <td>C, compilado a WebAssembly</td>
        </tr>
      </tbody>
    </table>
  </div>

  <p>
    Un nucleo de PlayStation no puede permitirse este panel: anotar cada
    cambio de registro lo haria cientos de veces mas lento. Pero por dentro
    hace lo mismo, treinta y tres millones de veces por segundo.
  </p>
  <p class="text-tenue text-sm mb-0">
    Codigo fuente del emulador:
    <code>public/assets/js/chip8.js</code> (el nucleo) y
    <code>public/assets/js/chip8-programas.js</code> (los programas).
    Ambos comentados en castellano.
  </p>
</section>

<?php require __DIR__ . '/partes/pie.php'; ?>
