<?php
/**
 * RetroCloud · Perfil del jugador
 * ---------------------------------------------------------------------
 * Resumen estadistico, partidas guardadas, favoritos, logros y cambio
 * de contrasena. Los datos los pide perfil.js a la API.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Csrf;
use RetroCloud\Validator;

Auth::exigirPagina();

$e = static fn(mixed $v): string => Validator::e($v);
$usuario = Auth::usuario() ?? [];

$tituloPagina = 'Mis partidas';
$paginaActual = 'perfil';

require __DIR__ . '/partes/cabecera.php';
?>

<div class="flex items-center justify-between flex-wrap gap mb">
  <div>
    <h1 class="mb-0"><?= $e($usuario['nombre_usuario'] ?? '') ?></h1>
    <p class="text-sm text-debil mb-0" style="margin-top:4px">
      <?= $e($usuario['correo'] ?? '') ?>
      &middot; Miembro desde <?= $e(date('d/m/Y', strtotime((string) ($usuario['creado_en'] ?? 'now')))) ?>
      <?php if (Auth::esAdmin()): ?>
        &middot; <span class="insignia insignia--exito">administrador</span>
      <?php endif; ?>
    </p>
  </div>

  <button type="button" class="btn btn--fantasma" id="btn-abrir-password">
    Cambiar contrasena
  </button>
</div>

<!-- ============ METRICAS ============ -->
<div class="metricas" id="metricas" aria-live="polite">
  <div class="metrica"><div class="esqueleto" style="height:52px"></div></div>
  <div class="metrica"><div class="esqueleto" style="height:52px"></div></div>
  <div class="metrica"><div class="esqueleto" style="height:52px"></div></div>
  <div class="metrica"><div class="esqueleto" style="height:52px"></div></div>
</div>

<!-- ============ PARTIDAS GUARDADAS ============ -->
<section class="panel mt-lg" aria-labelledby="t-partidas">
  <div class="panel__cabecera">
    <h2 id="t-partidas" style="font-size:1.1rem;flex:1">Partidas guardadas</h2>
    <span class="text-sm text-debil" id="conteo-partidas"></span>
  </div>
  <div class="panel__cuerpo">
    <div class="rejilla-juegos" id="lista-partidas">
      <div class="esqueleto" style="height:150px"></div>
      <div class="esqueleto" style="height:150px"></div>
      <div class="esqueleto" style="height:150px"></div>
    </div>
  </div>
</section>

<!-- ============ CONTINUAR JUGANDO ============ -->
<section class="panel mt-lg" aria-labelledby="t-historial">
  <div class="panel__cabecera">
    <h2 id="t-historial" style="font-size:1.1rem">Continuar jugando</h2>
  </div>
  <div class="panel__cuerpo">
    <div class="rejilla-juegos" id="lista-historial"></div>
  </div>
</section>

<!-- ============ FAVORITOS ============ -->
<section class="panel mt-lg" aria-labelledby="t-favoritos">
  <div class="panel__cabecera">
    <h2 id="t-favoritos" style="font-size:1.1rem">Favoritos</h2>
  </div>
  <div class="panel__cuerpo">
    <div class="rejilla-juegos" id="lista-favoritos"></div>
  </div>
</section>

<!-- ============ LOGROS ============ -->
<section class="panel mt-lg" aria-labelledby="t-logros">
  <div class="panel__cabecera">
    <h2 id="t-logros" style="font-size:1.1rem;flex:1">Logros</h2>
    <span class="text-sm text-debil" id="conteo-logros"></span>
  </div>
  <div class="panel__cuerpo">
    <div style="display:grid;gap:11px;grid-template-columns:repeat(auto-fit,minmax(270px,1fr))"
         id="lista-logros"></div>
  </div>
</section>

<!-- ============ MODAL: CAMBIAR CONTRASENA ============ -->
<div class="modal" id="modal-password" hidden role="dialog" aria-modal="true"
     aria-labelledby="t-modal-password">
  <div class="modal__caja" role="document">
    <form id="form-password">
      <?= Csrf::campo() ?>

      <div class="modal__cabecera">
        <h3 id="t-modal-password">Cambiar contrasena</h3>
        <button type="button" class="btn btn--fantasma btn--icono"
                data-cerrar-modal aria-label="Cerrar">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <div class="modal__cuerpo">
        <div class="campo">
          <label class="campo__etiqueta" for="pw-actual">Contrasena actual</label>
          <input type="password" id="pw-actual" name="actual" class="entrada"
                 required autocomplete="current-password">
          <span class="campo__error" data-error-de="actual"></span>
        </div>

        <div class="campo">
          <label class="campo__etiqueta" for="pw-nueva">Nueva contrasena</label>
          <input type="password" id="pw-nueva" name="nueva" class="entrada"
                 required minlength="<?= (int) PASSWORD_MIN ?>" autocomplete="new-password">
          <span class="campo__ayuda">
            Minimo <?= (int) PASSWORD_MIN ?> caracteres, con letras y numeros.
          </span>
          <span class="campo__error" data-error-de="nueva"></span>
        </div>

        <div class="campo mb-0">
          <label class="campo__etiqueta" for="pw-nueva2">Repetir nueva contrasena</label>
          <input type="password" id="pw-nueva2" name="nueva2" class="entrada"
                 required autocomplete="new-password">
          <span class="campo__error" data-error-de="nueva2"></span>
        </div>
      </div>

      <div class="modal__pie">
        <button type="button" class="btn btn--fantasma" data-cerrar-modal>Cancelar</button>
        <button type="submit" class="btn btn--primario">Guardar</button>
      </div>
    </form>
  </div>
</div>

<?php
$scriptModulo = 'assets/js/perfil.js';
require __DIR__ . '/partes/pie.php';
