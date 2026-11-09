<?php
/**
 * RetroCloud · Barra lateral del panel de administracion
 * ---------------------------------------------------------------------
 * Espera que la pagina haya definido $seccionAdmin.
 */

declare(strict_types=1);

$seccionAdmin = $seccionAdmin ?? '';

$activoAdmin = static fn(string $clave): string =>
    $clave === $seccionAdmin ? ' aria-current="page"' : '';
?>
<nav class="admin__lateral" aria-label="Secciones del panel">

  <div class="admin__titulo">Administracion</div>

  <a class="admin__link" href="index.php"<?= $activoAdmin('dashboard') ?>>
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
      <rect x="3" y="3" width="7" height="9" rx="1.5"/>
      <rect x="14" y="3" width="7" height="5" rx="1.5"/>
      <rect x="14" y="12" width="7" height="9" rx="1.5"/>
      <rect x="3" y="16" width="7" height="5" rx="1.5"/>
    </svg>
    Resumen
  </a>

  <a class="admin__link" href="juegos.php"<?= $activoAdmin('juegos') ?>>
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
      <rect x="2" y="7" width="20" height="13" rx="4"/>
      <path d="M7 13h3m-1.5-1.5v3"/>
      <circle cx="16.5" cy="12.5" r="1" fill="currentColor"/>
      <circle cx="18.5" cy="15" r="1" fill="currentColor"/>
    </svg>
    Juegos
  </a>

  <a class="admin__link" href="consolas.php"<?= $activoAdmin('consolas') ?>>
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
      <rect x="3" y="4" width="18" height="12" rx="2"/>
      <path d="M8 20h8M12 16v4"/>
    </svg>
    Consolas
  </a>

  <a class="admin__link" href="usuarios.php"<?= $activoAdmin('usuarios') ?>>
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
      <circle cx="9" cy="8" r="3.5"/>
      <path d="M2.5 20a6.5 6.5 0 0 1 13 0M17 11a3 3 0 1 0 0-6"/>
      <path d="M18 20a6.4 6.4 0 0 0-1.8-4.5"/>
    </svg>
    Usuarios
  </a>

  <div class="admin__titulo">Sistema</div>

  <a class="admin__link" href="../diagnostico.php">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
      <path d="M3 12h4l2-5 3 10 2.5-5H21"/>
    </svg>
    Diagnostico
  </a>

  <a class="admin__link" href="../index.php">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
      <path d="M19 12H5m6-7-7 7 7 7"/>
    </svg>
    Volver al sitio
  </a>

</nav>
