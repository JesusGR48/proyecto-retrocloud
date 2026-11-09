<?php
/**
 * RetroCloud · Panel · Gestion de usuarios
 * ---------------------------------------------------------------------
 * Consulta, cambio de rol, habilitacion y baja de cuentas. El backend
 * impide quedarse sin administradores y que un admin se bloquee a si
 * mismo; aqui solo deshabilitamos los controles correspondientes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;

Auth::exigirAdminPagina();

$tituloPagina = 'Usuarios';
$paginaActual = 'admin';
$seccionAdmin = 'usuarios';
$enRaiz       = false;

require __DIR__ . '/../partes/cabecera.php';
?>

<div class="admin">

  <?php require __DIR__ . '/../partes/admin-lateral.php'; ?>

  <div>
    <div class="mb">
      <h1 class="mb-0">Usuarios</h1>
      <p class="text-sm text-debil mb-0" style="margin-top:4px">
        Cuentas registradas y su actividad en la plataforma.
      </p>
    </div>

    <div class="aviso aviso--info">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z"/>
      </svg>
      <div class="aviso__cuerpo">
        <strong>Reglas del sistema</strong>
        No puedes modificar tu propia cuenta desde aqui, y el sistema nunca
        permite quedarse sin administradores activos.
      </div>
    </div>

    <section class="panel" aria-labelledby="t-usuarios">
      <div class="panel__cabecera">
        <h2 id="t-usuarios" style="font-size:1.05rem;flex:1 1 auto">Cuentas</h2>

        <div class="buscador" style="flex:0 1 230px">
          <label class="solo-lector" for="buscador-usuarios">Buscar usuarios</label>
          <input type="search" id="buscador-usuarios" class="entrada"
                 placeholder="Usuario o correo..." style="padding-left:14px">
        </div>

        <span class="text-sm text-debil" id="conteo-usuarios"></span>
      </div>

      <div class="tabla-envoltorio">
        <table class="tabla">
          <thead>
            <tr>
              <th scope="col">Usuario</th>
              <th scope="col">Correo</th>
              <th scope="col">Rol</th>
              <th scope="col">Partidas</th>
              <th scope="col">Tiempo</th>
              <th scope="col">En la nube</th>
              <th scope="col">Ultimo acceso</th>
              <th scope="col">Estado</th>
              <th scope="col" class="acciones">Acciones</th>
            </tr>
          </thead>
          <tbody id="tabla-usuarios-admin">
            <tr><td colspan="9" class="text-center text-debil">Cargando...</td></tr>
          </tbody>
        </table>
      </div>

      <div class="panel__cuerpo">
        <nav class="paginacion mt-0" id="paginacion-usuarios"
             aria-label="Paginacion de usuarios"></nav>
      </div>
    </section>

  </div>
</div>

<?php
$scriptModulo = 'assets/js/admin-usuarios.js';
require __DIR__ . '/../partes/pie.php';
