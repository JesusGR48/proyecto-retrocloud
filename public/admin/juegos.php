<?php
/**
 * RetroCloud · Panel · CRUD de juegos
 * ---------------------------------------------------------------------
 * Alta, consulta, edicion y baja del catalogo. El formulario se envia
 * como multipart/form-data porque incluye la ROM y la portada; el
 * backend valida extension, tamano y duplicados por SHA-256.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Csrf;
use RetroCloud\Database;
use RetroCloud\Validator;

Auth::exigirAdminPagina();

$e = static fn(mixed $v): string => Validator::e($v);

/* Catalogos para los selectores del formulario. */
$consolas = Database::filas(
    'SELECT id, nombre, slug, extensiones, core_emulador
       FROM consolas WHERE activa = 1 ORDER BY orden ASC, nombre ASC'
);
$generos = Database::filas('SELECT id, nombre FROM generos ORDER BY nombre ASC');

/* Limites reales del servidor: los mostramos para que nadie se pelee
   a ciegas con una subida que PHP va a rechazar. */
$limitePhp = min(
    (int) ini_get('upload_max_filesize') > 0
        ? (int) ini_get('upload_max_filesize') * 1024 * 1024
        : PHP_INT_MAX,
    MAX_BYTES_ROM
);

$tituloPagina = 'Juegos';
$paginaActual = 'admin';
$seccionAdmin = 'juegos';
$enRaiz       = false;

require __DIR__ . '/../partes/cabecera.php';
?>

<div class="admin">

  <?php require __DIR__ . '/../partes/admin-lateral.php'; ?>

  <div>
    <div class="flex items-center justify-between flex-wrap gap mb">
      <div>
        <h1 class="mb-0">Juegos</h1>
        <p class="text-sm text-debil mb-0" style="margin-top:4px">
          Catalogo completo, incluidos los borradores.
        </p>
      </div>
      <button type="button" class="btn btn--primario" id="btn-nuevo">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
          <path d="M12 5v14M5 12h14"/>
        </svg>
        Nuevo juego
      </button>
    </div>

    <!-- ============ FORMULARIO (oculto hasta que se pide) ============ -->
    <section class="panel mb" id="panel-formulario" hidden
             aria-labelledby="t-formulario">

      <div class="panel__cabecera">
        <h2 id="t-formulario" style="font-size:1.05rem;flex:1">Nuevo juego</h2>
        <button type="button" class="btn btn--fantasma btn--icono" id="btn-cerrar-form"
                aria-label="Cerrar formulario">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <div class="panel__cuerpo">
        <form id="form-juego" enctype="multipart/form-data" novalidate>

          <?= Csrf::campo() ?>
          <input type="hidden" name="id" id="juego-id" value="">

          <div id="aviso-juego" class="aviso aviso--error" hidden>
            <div class="aviso__cuerpo" id="aviso-juego-texto"></div>
          </div>

          <div style="display:grid;gap:0 18px;grid-template-columns:repeat(auto-fit,minmax(230px,1fr))">

            <div class="campo">
              <label class="campo__etiqueta" for="titulo">
                Titulo <span class="campo__requerido">*</span>
              </label>
              <input type="text" id="titulo" name="titulo" class="entrada"
                     required maxlength="150">
              <span class="campo__error" data-error-de="titulo"></span>
            </div>

            <div class="campo">
              <label class="campo__etiqueta" for="consola_id">
                Consola <span class="campo__requerido">*</span>
              </label>
              <select id="consola_id" name="consola_id" class="selector" required>
                <option value="">Selecciona una consola</option>
                <?php foreach ($consolas as $c): ?>
                  <option value="<?= (int) $c['id'] ?>"
                          data-extensiones="<?= $e($c['extensiones']) ?>"
                          data-core="<?= $e($c['core_emulador']) ?>">
                    <?= $e($c['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <span class="campo__ayuda" id="ayuda-extensiones">
                Elige la consola para ver las extensiones aceptadas.
              </span>
              <span class="campo__error" data-error-de="consola_id"></span>
            </div>

            <div class="campo">
              <label class="campo__etiqueta" for="genero_id">Genero</label>
              <select id="genero_id" name="genero_id" class="selector">
                <option value="">Sin genero</option>
                <?php foreach ($generos as $g): ?>
                  <option value="<?= (int) $g['id'] ?>"><?= $e($g['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="campo">
              <label class="campo__etiqueta" for="desarrollador">Desarrollador</label>
              <input type="text" id="desarrollador" name="desarrollador"
                     class="entrada" maxlength="120">
            </div>

            <div class="campo">
              <label class="campo__etiqueta" for="anio">Ano de lanzamiento</label>
              <input type="number" id="anio" name="anio" class="entrada"
                     min="1950" max="<?= (int) date('Y') + 1 ?>"
                     placeholder="<?= (int) date('Y') ?>">
              <span class="campo__error" data-error-de="anio"></span>
            </div>

            <div class="campo">
              <label class="campo__etiqueta" for="jugadores">Jugadores</label>
              <input type="number" id="jugadores" name="jugadores" class="entrada"
                     min="1" max="8" value="1">
            </div>

          </div>

          <div class="campo">
            <label class="campo__etiqueta" for="descripcion">Descripcion</label>
            <textarea id="descripcion" name="descripcion" class="area"
                      maxlength="2000"
                      placeholder="De que va el juego, controles especiales, notas..."></textarea>
          </div>

          <div class="campo">
            <label class="campo__etiqueta" for="licencia">
              Licencia o procedencia
            </label>
            <input type="text" id="licencia" name="licencia" class="entrada"
                   maxlength="140" list="sugerencias-licencia"
                   placeholder="Homebrew (CC BY-SA 4.0)">
            <datalist id="sugerencias-licencia">
              <option value="Homebrew - libre distribucion"></option>
              <option value="Dominio publico"></option>
              <option value="CC BY 4.0"></option>
              <option value="CC BY-SA 4.0"></option>
              <option value="CC0 1.0 Universal"></option>
              <option value="GPL-3.0"></option>
              <option value="Demo tecnica / test suite"></option>
              <option value="Copia de respaldo propia"></option>
            </datalist>
            <span class="campo__ayuda">
              Se muestra en la ficha del juego. Documentar la procedencia de
              cada ROM es parte de los entregables del proyecto.
            </span>
          </div>

          <div style="display:grid;gap:0 18px;grid-template-columns:repeat(auto-fit,minmax(250px,1fr))">

            <div class="campo">
              <label class="campo__etiqueta" for="rom">
                Archivo ROM <span class="campo__requerido" id="rom-requerido">*</span>
              </label>
              <label class="zona-archivo" id="zona-rom">
                <input type="file" id="rom" name="rom" accept="">
                <div class="zona-archivo__titulo" id="rom-titulo">
                  Arrastra la ROM o pulsa aqui
                </div>
                <div class="zona-archivo__pista" id="rom-pista">
                  Maximo <?= $e(Validator::bytesLegibles($limitePhp)) ?>
                </div>
              </label>
              <span class="campo__error" data-error-de="rom"></span>
            </div>

            <div class="campo">
              <label class="campo__etiqueta" for="portada">Portada (opcional)</label>
              <label class="zona-archivo" id="zona-portada">
                <input type="file" id="portada" name="portada"
                       accept="image/jpeg,image/png,image/webp,image/gif">
                <div class="zona-archivo__titulo" id="portada-titulo">
                  Arrastra una imagen o pulsa aqui
                </div>
                <div class="zona-archivo__pista" id="portada-pista">
                  JPG, PNG, WEBP o GIF &middot; maximo
                  <?= $e(Validator::bytesLegibles(MAX_BYTES_PORTADA)) ?>
                </div>
              </label>
              <span class="campo__error" data-error-de="portada"></span>
              <img id="previsualizacion-portada" alt="" hidden
                   style="margin-top:10px;max-height:130px;border-radius:var(--radio-sm)">
            </div>

          </div>

          <div class="campo">
            <label class="interruptor">
              <input type="checkbox" name="publicado" value="1" checked>
              <span class="interruptor__pista"></span>
              <span>
                <strong>Publicado</strong>
                <span class="text-sm text-debil">
                  &mdash; visible en el catalogo para todos los jugadores
                </span>
              </span>
            </label>
          </div>

          <?php /* Barra de progreso de la subida: las ROMs pesan. */ ?>
          <div id="progreso-subida" hidden>
            <div class="progreso" style="height:7px">
              <div class="progreso__relleno" id="progreso-subida-relleno"
                   style="width:0"></div>
            </div>
            <p class="text-sm text-debil" id="progreso-subida-texto"
               style="margin-top:7px">Subiendo...</p>
          </div>

          <div class="flex gap flex-wrap mt">
            <button type="submit" class="btn btn--primario" id="btn-guardar-juego">
              Guardar juego
            </button>
            <button type="button" class="btn btn--fantasma" id="btn-cancelar-form">
              Cancelar
            </button>
          </div>

        </form>
      </div>
    </section>

    <!-- ============ TABLA ============ -->
    <section class="panel" aria-labelledby="t-tabla">
      <div class="panel__cabecera">
        <h2 id="t-tabla" style="font-size:1.05rem;flex:1 1 auto">Catalogo</h2>

        <div class="buscador" style="flex:0 1 230px">
          <label class="solo-lector" for="buscador-admin">Buscar juegos</label>
          <input type="search" id="buscador-admin" class="entrada"
                 placeholder="Buscar..." style="padding-left:14px">
        </div>

        <span class="text-sm text-debil" id="conteo-admin"></span>
      </div>

      <div class="tabla-envoltorio">
        <table class="tabla">
          <thead>
            <tr>
              <th scope="col" style="width:62px">Portada</th>
              <th scope="col">Titulo</th>
              <th scope="col">Consola</th>
              <th scope="col">Ano</th>
              <th scope="col">Peso</th>
              <th scope="col">Partidas</th>
              <th scope="col">Estado</th>
              <th scope="col" class="acciones">Acciones</th>
            </tr>
          </thead>
          <tbody id="tabla-juegos">
            <tr><td colspan="8" class="text-center text-debil">Cargando...</td></tr>
          </tbody>
        </table>
      </div>

      <div class="panel__cuerpo">
        <nav class="paginacion mt-0" id="paginacion-admin"
             aria-label="Paginacion del catalogo"></nav>
      </div>
    </section>

  </div>
</div>

<?php
$scriptModulo = 'assets/js/admin-juegos.js';
require __DIR__ . '/../partes/pie.php';
