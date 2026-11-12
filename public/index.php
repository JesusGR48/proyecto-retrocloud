<?php
/**
 * RetroCloud · Catalogo (pagina de inicio)
 * ---------------------------------------------------------------------
 * Renderiza el armazon de la pagina en el servidor (bueno para la
 * primera pintura y para que funcione sin JavaScript hasta cierto
 * punto) y deja que app.js rellene la rejilla por la API. Asi el
 * buscador y los filtros no provocan recargas completas.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Database;
use RetroCloud\Storage;
use RetroCloud\Validator;

Storage::preparar();

$e = static fn(mixed $v): string => Validator::e($v);

/* Cifras para el hero. Si la base de datos no responde todavia, la
   pagina no debe romperse: mostramos ceros y un aviso. */
$resumen = null;
$errorBd = null;

try {
    $resumen = Database::fila(
        'SELECT
           (SELECT COUNT(*) FROM juegos WHERE publicado = 1)  AS juegos,
           (SELECT COUNT(*) FROM consolas WHERE activa = 1)   AS consolas,
           (SELECT COUNT(*) FROM usuarios WHERE activo = 1)   AS usuarios,
           (SELECT COUNT(*) FROM save_states)                 AS partidas'
    );
} catch (\Throwable $ex) {
    $errorBd = $ex->getMessage();
}

$tituloPagina = 'Catalogo';
$paginaActual = 'catalogo';
$descripcion  = 'Juega clasicos de NES, SNES, Game Boy, Genesis y mas '
              . 'directo en el navegador, con tus partidas guardadas en la nube.';

require __DIR__ . '/partes/cabecera.php';
?>

<?php if ($errorBd !== null): ?>
  <div class="aviso aviso--error">
    <div class="aviso__cuerpo">
      <strong>No hay conexion con la base de datos</strong>
      <?= $e($errorBd) ?>
      <div class="mt text-sm">
        Revisa <a href="diagnostico.php">diagnostico.php</a> para ver que falta.
      </div>
    </div>
  </div>
<?php endif; ?>

<section class="hero">
  <h1>
    Tus clasicos, <br>
    <span style="background:linear-gradient(90deg,var(--primario-claro),var(--acento));
                 -webkit-background-clip:text;background-clip:text;color:transparent">
      donde los dejaste
    </span>
  </h1>

  <p>
    RetroCloud emula consolas retro dentro de tu navegador usando nucleos
    compilados a <strong>WebAssembly</strong>. Conecta un mando, juega en el
    celular con controles tactiles y guarda tu progreso en el servidor para
    continuarlo desde cualquier dispositivo.
  </p>

  <div class="hero__acciones">
    <?php if (Auth::autenticado()): ?>
      <a class="btn btn--primario" href="#rejilla-juegos">Explorar el catalogo</a>
      <a class="btn btn--fantasma" href="perfil.php">Mis partidas guardadas</a>
    <?php else: ?>
      <a class="btn btn--primario" href="registro.php">Crear cuenta gratis</a>
      <a class="btn btn--fantasma" href="login.php">Ya tengo cuenta</a>
    <?php endif; ?>
  </div>

  <div class="cifras">
    <div class="cifra">
      <div class="cifra__valor"><?= (int) ($resumen['juegos'] ?? 0) ?></div>
      <div class="cifra__etiqueta">Juegos</div>
    </div>
    <div class="cifra">
      <div class="cifra__valor"><?= (int) ($resumen['consolas'] ?? 0) ?></div>
      <div class="cifra__etiqueta">Consolas</div>
    </div>
    <div class="cifra">
      <div class="cifra__valor"><?= (int) ($resumen['usuarios'] ?? 0) ?></div>
      <div class="cifra__etiqueta">Jugadores</div>
    </div>
    <div class="cifra">
      <div class="cifra__valor"><?= (int) ($resumen['partidas'] ?? 0) ?></div>
      <div class="cifra__etiqueta">Partidas en la nube</div>
    </div>
  </div>
</section>

<?php if (!Auth::autenticado()): ?>
  <div class="aviso aviso--info">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" aria-hidden="true">
      <circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/>
    </svg>
    <div class="aviso__cuerpo">
      <strong>Inicia sesion para jugar</strong>
      Las ROMs se sirven solo a usuarios con sesion activa, y las partidas
      guardadas se asocian a tu cuenta.
    </div>
  </div>
<?php endif; ?>

<?php /* ---------------------------------------------------------------
     Recomendaciones personalizadas

     La rellena recomendaciones.js desde /api/recomendaciones.php. Se
     renderiza vacia y oculta: si el motor no devuelve nada (catalogo
     vacio, API caida) la seccion no llega a aparecer y la portada
     queda exactamente como antes.
     --------------------------------------------------------------- */ ?>
<section class="reco" id="reco" aria-labelledby="titulo-reco" hidden>

  <div class="reco__cabecera">
    <div>
      <h2 id="titulo-reco" class="mb-0">
        Para ti
        <span class="insignia insignia--consola reco__sello">IA</span>
      </h2>
      <p class="reco__sub text-sm text-debil mb-0" id="reco-estrategia"></p>
    </div>

    <button type="button" class="btn btn--fantasma btn--sm" id="reco-porque"
            aria-expanded="false" aria-controls="reco-diagnostico">
      ¿Como decidio esto?
    </button>
  </div>

  <?php /* Panel de transparencia: ensena los pesos reales del modelo.
           Un recomendador que no sabe justificarse es una caja negra. */ ?>
  <div class="reco__diagnostico" id="reco-diagnostico" hidden></div>

  <div class="reco__lista" id="reco-lista"></div>
</section>

<section aria-labelledby="titulo-catalogo">

  <div class="flex items-center justify-between flex-wrap gap mb">
    <h2 id="titulo-catalogo" class="mb-0">Catalogo</h2>
    <span class="text-sm text-debil" id="conteo-resultados" aria-live="polite"></span>
  </div>

  <?php /* Fila de consolas: la rellena app.js desde la API. */ ?>
  <div class="consolas-fila" id="fila-consolas" role="group"
       aria-label="Filtrar por consola"></div>

  <form class="filtros" role="search" onsubmit="return false">
    <div class="buscador">
      <span class="buscador__icono" aria-hidden="true">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round">
          <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
        </svg>
      </span>
      <label class="solo-lector" for="buscador">Buscar juegos</label>
      <input type="search" id="buscador" class="entrada"
             placeholder="Buscar por titulo o desarrollador...  (pulsa / )"
             autocomplete="off">
    </div>

    <label class="solo-lector" for="selector-genero">Filtrar por genero</label>
    <select id="selector-genero" class="selector" style="flex:0 1 190px">
      <option value="">Todos los generos</option>
    </select>

    <label class="solo-lector" for="selector-orden">Ordenar</label>
    <select id="selector-orden" class="selector" style="flex:0 1 170px">
      <option value="recientes">Mas recientes</option>
      <option value="populares">Mas jugados</option>
      <option value="titulo">Titulo (A-Z)</option>
      <option value="anio">Ano de lanzamiento</option>
      <option value="antiguos">Mas antiguos</option>
    </select>
  </form>

  <div class="rejilla-juegos" id="rejilla-juegos" aria-busy="true"></div>

  <nav class="paginacion" id="paginacion" aria-label="Paginacion del catalogo"></nav>

</section>

<?php
$scriptModulo = 'assets/js/recomendaciones.js';
require __DIR__ . '/partes/pie.php';
