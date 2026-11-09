<?php
/**
 * RetroCloud · Panel de administracion · Resumen
 * ---------------------------------------------------------------------
 * Cifras del sistema, distribucion de juegos por consola y actividad
 * reciente. Las graficas se dibujan con CSS puro (sin librerias).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;

Auth::exigirAdminPagina();

$tituloPagina = 'Panel';
$paginaActual = 'admin';
$seccionAdmin = 'dashboard';
$enRaiz       = false;

require __DIR__ . '/../partes/cabecera.php';
?>

<div class="admin">

  <?php require __DIR__ . '/../partes/admin-lateral.php'; ?>

  <div>
    <div class="flex items-center justify-between flex-wrap gap mb">
      <div>
        <h1 class="mb-0">Resumen del sistema</h1>
        <p class="text-sm text-debil mb-0" style="margin-top:4px">
          Estado de RetroCloud en tiempo real.
        </p>
      </div>
      <a class="btn btn--primario" href="juegos.php?nuevo=1">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
          <path d="M12 5v14M5 12h14"/>
        </svg>
        Nuevo juego
      </a>
    </div>

    <!-- Metricas -->
    <div class="metricas" id="metricas-admin" aria-live="polite">
      <?php for ($i = 0; $i < 6; $i++): ?>
        <div class="metrica"><div class="esqueleto" style="height:52px"></div></div>
      <?php endfor; ?>
    </div>

    <!-- Juegos por consola -->
    <section class="panel mt-lg" aria-labelledby="t-consolas">
      <div class="panel__cabecera">
        <h2 id="t-consolas" style="font-size:1.05rem">Juegos por consola</h2>
      </div>
      <div class="panel__cuerpo">
        <div class="grafica" id="grafica-consolas" role="img"
             aria-label="Distribucion de juegos por consola"></div>
      </div>
    </section>

    <!-- Actividad -->
    <section class="panel mt-lg" aria-labelledby="t-actividad">
      <div class="panel__cabecera">
        <h2 id="t-actividad" style="font-size:1.05rem">Partidas en los ultimos 14 dias</h2>
      </div>
      <div class="panel__cuerpo">
        <div class="grafica" id="grafica-actividad" role="img"
             aria-label="Partidas por dia en las ultimas dos semanas"></div>
      </div>
    </section>

    <!-- Ultimos usuarios -->
    <section class="panel mt-lg" aria-labelledby="t-usuarios">
      <div class="panel__cabecera">
        <h2 id="t-usuarios" style="font-size:1.05rem;flex:1">Ultimos registros</h2>
        <a class="btn btn--fantasma btn--sm" href="usuarios.php">Ver todos</a>
      </div>
      <div class="tabla-envoltorio">
        <table class="tabla">
          <thead>
            <tr>
              <th scope="col">Usuario</th>
              <th scope="col">Correo</th>
              <th scope="col">Rol</th>
              <th scope="col">Registro</th>
              <th scope="col">Ultimo acceso</th>
            </tr>
          </thead>
          <tbody id="tabla-usuarios">
            <tr><td colspan="5" class="text-center text-debil">Cargando...</td></tr>
          </tbody>
        </table>
      </div>
    </section>

  </div>
</div>

<?php
$scriptModulo = 'assets/js/admin-dashboard.js';
require __DIR__ . '/../partes/pie.php';
