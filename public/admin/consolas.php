<?php
/**
 * RetroCloud · Panel · Consolas y BIOS
 * ---------------------------------------------------------------------
 * Algunas plataformas necesitan la firmware original del sistema para
 * arrancar. Desde aqui se carga una por consola, con la misma
 * validacion que las ROMs: tamano, extension, SHA-256 y almacenamiento
 * fuera del document root.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Csrf;
use RetroCloud\Validator;

Auth::exigirAdminPagina();

$e = static fn(mixed $v): string => Validator::e($v);

$tituloPagina = 'Consolas';
$paginaActual = 'admin';
$seccionAdmin = 'consolas';
$enRaiz       = false;

require __DIR__ . '/../partes/cabecera.php';
?>

<div class="admin">

  <?php require __DIR__ . '/../partes/admin-lateral.php'; ?>

  <div>
    <div class="mb">
      <h1 class="mb-0">Consolas y BIOS</h1>
      <p class="text-sm text-debil mb-0" style="margin-top:4px">
        Plataformas disponibles y su firmware de sistema.
      </p>
    </div>

    <div class="aviso aviso--info">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/>
      </svg>
      <div class="aviso__cuerpo">
        <strong>¿Que es el BIOS de una consola?</strong>
        Plataformas como Neo Geo o PlayStation no ejecutan el juego por si
        solas: lo corren <em>sobre</em> la firmware de la maquina, que contiene
        las rutinas de arranque del sistema. Sin ese archivo el emulador no
        sabe ni como empezar, y el juego se queda en pantalla negra.
        Maximo <?= $e(Validator::bytesLegibles(MAX_BYTES_BIOS)) ?> por archivo.
      </div>
    </div>

    <div id="aviso-falta" class="aviso aviso--alerta" hidden>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <path d="M12 8v5m0 3h.01"/><circle cx="12" cy="12" r="9"/>
      </svg>
      <div class="aviso__cuerpo" id="aviso-falta-texto"></div>
    </div>

    <section class="panel" aria-labelledby="t-consolas">
      <div class="panel__cabecera">
        <h2 id="t-consolas" style="font-size:1.05rem;flex:1">Plataformas</h2>
        <span class="text-sm text-debil" id="conteo-consolas"></span>
      </div>

      <div class="tabla-envoltorio">
        <table class="tabla">
          <thead>
            <tr>
              <th scope="col">Consola</th>
              <th scope="col">Nucleo</th>
              <th scope="col">Juegos</th>
              <th scope="col">¿Exige BIOS?</th>
              <th scope="col">BIOS cargado</th>
              <th scope="col" class="acciones">Acciones</th>
            </tr>
          </thead>
          <tbody id="tabla-consolas">
            <tr><td colspan="6" class="text-center text-debil">Cargando...</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <?php /* Input oculto reutilizable: lo dispara el boton de cada fila. */ ?>
    <input type="file" id="selector-bios" hidden
           accept=".zip,.bin,.rom,.bios,.img,.pup">
    <?= Csrf::campo() ?>

  </div>
</div>

<?php
$scriptModulo = 'assets/js/admin-consolas.js';
require __DIR__ . '/../partes/pie.php';
