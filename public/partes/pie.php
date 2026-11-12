<?php
/**
 * RetroCloud · Plantilla de pie
 * ---------------------------------------------------------------------
 * Cierra el <main> que abrio cabecera.php y carga los modulos de
 * JavaScript. Variables opcionales que la pagina puede definir antes:
 *
 *   $scriptModulo  string  modulo JS extra (p. ej. 'assets/js/emulador.js')
 *   $sinApp        bool    true para no cargar app.js
 */

declare(strict_types=1);

use RetroCloud\Validator;

$base         = $base         ?? '';
$scriptModulo = $scriptModulo ?? null;
$sinApp       = $sinApp       ?? false;

$e = static fn(mixed $v): string => Validator::e($v);
?>
  </div><!-- /.contenedor -->
</main>

<footer class="pie">
  <div class="contenedor pie__inner">

    <div>
      <strong style="color:var(--texto-tenue)"><?= $e(APP_NOMBRE) ?></strong>
      v<?= $e(APP_VERSION) ?>
      &middot; Proyecto final de Desarrollo y Programacion Web
      <div class="text-xs" style="margin-top:4px">
        Emulacion en el navegador con
        <a href="https://emulatorjs.org/" target="_blank" rel="noopener noreferrer">EmulatorJS</a>
        (nucleos libretro compilados a WebAssembly).
      </div>
    </div>

    <p class="pie__nota text-xs mb-0">
      Los emuladores son software legal. Cada titulo del catalogo se publica
      con su licencia declarada; sube unicamente ROMs que tengas derecho a
      distribuir (homebrew, dominio publico o copias propias).
    </p>

  </div>
</footer>

<div class="notificaciones" role="status" aria-live="polite"></div>

<?php /* recurso() incorpora la fecha del archivo a la URL, para que un
         cambio llegue al navegador sin esperar a que caduque su cache. */ ?>
<?php if (!$sinApp): ?>
  <script type="module" src="<?= $e(recurso('assets/js/app.js')) ?>"></script>
<?php endif; ?>

<?php if ($scriptModulo !== null): ?>
  <script type="module" src="<?= $e(recurso($scriptModulo)) ?>"></script>
<?php endif; ?>

<?php /* Asistente flotante del catalogo. Se carga en todo el sitio: el
         modulo inyecta su propio marcado, asi que no hace falta tocar
         ninguna plantilla, y el propio script se abstiene en el
         reproductor para no estorbar encima del juego. */ ?>
<script type="module" src="<?= $e(recurso('assets/js/asistente.js')) ?>"></script>

</body>
</html>
