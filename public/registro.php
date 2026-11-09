<?php
/**
 * RetroCloud · Registro de cuenta
 * ---------------------------------------------------------------------
 * La validacion del navegador (required, pattern, minlength) es solo
 * comodidad para el usuario. La que decide es la del servidor, en
 * src/Validator.php, porque la del cliente se puede desactivar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Csrf;
use RetroCloud\Validator;

if (Auth::autenticado()) {
    header('Location: index.php', true, 302);
    exit;
}

$e = static fn(mixed $v): string => Validator::e($v);

$tituloPagina = 'Crear cuenta';
$paginaActual = 'registro';

require __DIR__ . '/partes/cabecera.php';
?>

<div style="max-width:440px;margin-inline:auto;padding-block:clamp(10px,4vw,36px)">

  <h1 class="text-center">Crear cuenta</h1>
  <p class="text-center text-tenue">
    Gratis, y tus partidas se guardan en el servidor.
  </p>

  <div class="tarjeta mt">
    <form id="form-registro" novalidate>

      <?= Csrf::campo() ?>

      <div id="aviso-registro" class="aviso aviso--error" hidden>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/>
        </svg>
        <div class="aviso__cuerpo" id="aviso-registro-texto"></div>
      </div>

      <div class="campo">
        <label class="campo__etiqueta" for="usuario">
          Nombre de usuario <span class="campo__requerido">*</span>
        </label>
        <input type="text" id="usuario" name="usuario" class="entrada"
               required minlength="3" maxlength="32"
               pattern="[A-Za-z0-9_\-]{3,32}"
               autocomplete="username" autocapitalize="none" autofocus>
        <span class="campo__ayuda">
          De 3 a 32 caracteres: letras, numeros, guion y guion bajo.
        </span>
        <span class="campo__error" data-error-de="usuario"></span>
      </div>

      <div class="campo">
        <label class="campo__etiqueta" for="correo">
          Correo electronico <span class="campo__requerido">*</span>
        </label>
        <input type="email" id="correo" name="correo" class="entrada"
               required maxlength="190" autocomplete="email" autocapitalize="none">
        <span class="campo__error" data-error-de="correo"></span>
      </div>

      <div class="campo">
        <label class="campo__etiqueta" for="password">
          Contrasena <span class="campo__requerido">*</span>
        </label>
        <div style="position:relative">
          <input type="password" id="password" name="password" class="entrada"
                 required minlength="<?= (int) PASSWORD_MIN ?>" maxlength="200"
                 autocomplete="new-password" style="padding-right:46px">
          <button type="button" class="btn btn--fantasma btn--icono"
                  data-ver-password="password"
                  style="position:absolute;right:4px;top:50%;transform:translateY(-50%);
                         border:none;background:transparent;width:38px;height:38px"
                  aria-label="Mostrar contrasena">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
              <path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/>
              <circle cx="12" cy="12" r="2.6"/>
            </svg>
          </button>
        </div>

        <?php /* Medidor de fuerza: lo calcula registro en auth.js */ ?>
        <div class="progreso" style="margin-top:8px">
          <div class="progreso__relleno" id="fuerza-barra" style="width:0"></div>
        </div>
        <span class="campo__ayuda" id="fuerza-texto">
          Minimo <?= (int) PASSWORD_MIN ?> caracteres, con letras y numeros.
        </span>
        <span class="campo__error" data-error-de="password"></span>
      </div>

      <div class="campo">
        <label class="campo__etiqueta" for="password2">
          Repetir contrasena <span class="campo__requerido">*</span>
        </label>
        <input type="password" id="password2" name="password2" class="entrada"
               required maxlength="200" autocomplete="new-password">
        <span class="campo__error" data-error-de="password2"></span>
      </div>

      <button type="submit" class="btn btn--primario btn--bloque mt">
        Crear mi cuenta
      </button>
    </form>
  </div>

  <p class="text-center text-sm mt">
    ¿Ya tienes cuenta? <a href="login.php">Entra aqui</a>
  </p>

</div>

<?php
$scriptModulo = 'assets/js/auth.js';
require __DIR__ . '/partes/pie.php';
