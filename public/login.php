<?php
/**
 * RetroCloud · Inicio de sesion
 * ---------------------------------------------------------------------
 * El formulario se envia por fetch() contra api/auth.php. El servidor
 * aplica el limitador de intentos y responde 401 o 429 segun el caso.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Csrf;
use RetroCloud\Validator;

/* Ya tiene sesion: no tiene sentido mostrarle el login. */
if (Auth::autenticado()) {
    header('Location: index.php', true, 302);
    exit;
}

$e = static fn(mixed $v): string => Validator::e($v);

/**
 * Destino tras entrar. Se valida para que nadie pueda usar
 * ?volver=https://sitio-malo.com y convertir nuestro login en un
 * trampolin hacia otro dominio (open redirect).
 */
$volver = (string) ($_GET['volver'] ?? 'index.php');
if ($volver === ''
    || str_starts_with($volver, '//')
    || str_contains($volver, ':')
    || !preg_match('#^[A-Za-z0-9_\-./?=&%]+$#', $volver)
) {
    $volver = 'index.php';
}

$expirada = ($_GET['expirada'] ?? '') === '1';

$tituloPagina = 'Entrar';
$paginaActual = 'login';

require __DIR__ . '/partes/cabecera.php';
?>

<div style="max-width:420px;margin-inline:auto;padding-block:clamp(10px,4vw,36px)">

  <h1 class="text-center">Entrar</h1>
  <p class="text-center text-tenue">
    Tus partidas guardadas te esperan.
  </p>

  <?php if ($expirada): ?>
    <div class="aviso aviso--alerta">
      <div class="aviso__cuerpo">
        <strong>Tu sesion expiro</strong>
        Vuelve a entrar para continuar donde estabas.
      </div>
    </div>
  <?php endif; ?>

  <div class="tarjeta mt">
    <form id="form-login" novalidate
          data-volver="<?= $e($volver) ?>">

      <?= Csrf::campo() ?>

      <div id="aviso-login" class="aviso aviso--error" hidden>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/>
        </svg>
        <div class="aviso__cuerpo" id="aviso-login-texto"></div>
      </div>

      <div class="campo">
        <label class="campo__etiqueta" for="usuario">
          Usuario o correo <span class="campo__requerido">*</span>
        </label>
        <input type="text" id="usuario" name="usuario" class="entrada"
               required autocomplete="username" autocapitalize="none"
               autofocus maxlength="190">
        <span class="campo__error" data-error-de="usuario"></span>
      </div>

      <div class="campo">
        <label class="campo__etiqueta" for="password">
          Contrasena <span class="campo__requerido">*</span>
        </label>
        <div style="position:relative">
          <input type="password" id="password" name="password" class="entrada"
                 required autocomplete="current-password" maxlength="200"
                 style="padding-right:46px">
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
        <span class="campo__error" data-error-de="password"></span>
      </div>

      <button type="submit" class="btn btn--primario btn--bloque mt">
        Entrar
      </button>
    </form>
  </div>

  <p class="text-center text-sm mt">
    ¿Primera vez aqui? <a href="registro.php">Crea tu cuenta</a>
  </p>

  <div class="tarjeta tarjeta--plana mt-lg text-xs text-debil">
    <strong style="color:var(--texto-tenue)">Nota de seguridad</strong>
    <p class="mb-0 mt-0" style="margin-top:6px">
      Tras <?= (int) LOGIN_MAX_INTENTOS ?> intentos fallidos la cuenta y la IP
      quedan bloqueadas <?= (int) (LOGIN_BLOQUEO_SEG / 60) ?> minutos. Las
      contrasenas se guardan como hash bcrypt, nunca en texto plano.
    </p>
  </div>

</div>

<?php
$scriptModulo = 'assets/js/auth.js';
require __DIR__ . '/partes/pie.php';
