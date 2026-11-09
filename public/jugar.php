<?php
/**
 * RetroCloud · Reproductor
 * ---------------------------------------------------------------------
 * Monta el emulador y la barra lateral de partidas guardadas.
 *
 * El servidor pasa la configuracion al cliente dentro de un bloque
 * <script type="application/json">. Se hace asi, y no imprimiendo
 * variables JavaScript sueltas, porque json_encode con
 * JSON_HEX_TAG escapa cualquier "<" del contenido: un titulo con
 * </script> dentro no puede romper la pagina ni inyectar codigo.
 */

declare(strict_types=1);

/*
 * Marca esta pagina como la del emulador ANTES de arrancar la
 * aplicacion. bootstrap.php lo consulta para anadir 'unsafe-eval' a la
 * Content-Security-Policy: los nucleos compilados con Emscripten lo
 * necesitan, y asi el permiso queda acotado a esta unica pagina en
 * lugar de concederse a todo el sitio.
 */
define('PAGINA_EMULADOR', true);

require_once __DIR__ . '/../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Database;
use RetroCloud\Validator;

/* Jugar exige sesion: las ROMs solo se sirven a usuarios identificados. */
Auth::exigirPagina();

$e = static fn(mixed $v): string => Validator::e($v);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php', true, 302);
    exit;
}

$juego = Database::fila(
    'SELECT j.id, j.titulo, j.slug, j.descripcion, j.desarrollador, j.anio,
            j.portada, j.jugadores, j.licencia, j.publicado, j.rom_bytes,
            j.archivo_rom, j.archivo_original, j.veces_jugado,
            c.id AS consola_id, c.nombre AS consola, c.slug AS consola_slug,
            c.core_emulador, c.color_hex, c.fabricante,
            c.bios_requerido, c.archivo_bios, c.bios_nombre_original,
            g.nombre AS genero
       FROM juegos j
       JOIN consolas c ON c.id = j.consola_id
       LEFT JOIN generos g ON g.id = j.genero_id
      WHERE j.id = :id',
    ['id' => $id]
);

if ($juego === null || ((int) $juego['publicado'] !== 1 && !Auth::esAdmin())) {
    http_response_code(404);
    $tituloPagina = 'Juego no encontrado';
    $paginaActual = 'catalogo';
    require __DIR__ . '/partes/cabecera.php';
    echo '<div class="vacio"><h1>404</h1>'
       . '<p>Ese juego no existe o no esta disponible.</p>'
       . '<a class="btn btn--primario" href="index.php">Volver al catalogo</a></div>';
    require __DIR__ . '/partes/pie.php';
    exit;
}

/* Ranura que se pidio continuar (desde el perfil). */
$slotInicial = isset($_GET['slot']) && $_GET['slot'] !== ''
    ? max(0, min(4, (int) $_GET['slot']))
    : null;

/**
 * Origen de los nucleos de emulacion.
 *
 * La decision (local o CDN) se toma en src/config.php, porque la misma
 * eleccion tiene que reflejarse en la Content-Security-Policy que emite
 * src/bootstrap.php. Si esta pagina cargara el script de un origen que
 * la politica no autoriza, el navegador lo bloquearia.
 */
$usaLocal  = EMULADOR_LOCAL;
$rutaDatos = EMULADOR_RUTA_DATOS;

/*
 * URL de la ROM: lleva un nombre de archivo "decorativo" antes de la
 * cadena de consulta.
 *
 * Por que: EmulatorJS deduce el nombre del juego y su extension a
 * partir de la URL. Con 'api/rom.php?id=3' no encuentra ninguno de los
 * dos, y acaba mostrando "undefined" como titulo en su pantalla de
 * pausa. Anadiendo el segmento el resultado queda asi:
 *
 *     api/rom.php/Super_Mario_World.sfc?id=3
 *
 * Apache entrega ese sobrante a PHP en PATH_INFO y rom.php lo ignora
 * por completo: sigue resolviendo el juego unicamente por el `id`, asi
 * que el segmento no es manipulable ni afecta a la seguridad.
 */
$extensionRom = strtolower((string) pathinfo((string) $juego['archivo_rom'], PATHINFO_EXTENSION));

/*
 * Que nombre se le presenta al emulador.
 *
 * Si se guardo el nombre ORIGINAL del archivo, se usa ese. Es
 * imprescindible para los nucleos de arcade: FBNeo identifica el
 * romset por el nombre del zip, no por su contenido. Espera algo como
 * `kof2002.zip`, y si recibe `King_Of_Fighters_2002.zip` responde
 * "Romset is unknown" aunque los datos de dentro sean correctos.
 *
 * Para cartuchos (NES, SNES, GBA...) el nombre da igual, asi que si no
 * hay original se cae al titulo, que al menos se lee bien.
 */
$nombreArchivo = $juego['archivo_original'] !== null
    && (string) $juego['archivo_original'] !== ''
    ? (string) $juego['archivo_original']
    : (trim(
           (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $juego['titulo']),
           '_'
       ) ?: 'rom')
      . ($extensionRom !== '' ? '.' . $extensionRom : '');

$urlRom = 'api/rom.php/' . rawurlencode($nombreArchivo)
        . '?id=' . (int) $juego['id'];

/*
 * Firmware de sistema (BIOS).
 *
 * Neo Geo, PlayStation y otras plataformas no ejecutan el juego por si
 * solas: lo corren sobre la firmware de la maquina. Si la consola tiene
 * una cargada se le pasa su URL al emulador; si la exige y falta, el
 * reproductor avisa antes de intentar arrancar, en vez de dejar al
 * usuario mirando una pantalla negra sin explicacion.
 */
$biosNombre = $juego['bios_nombre_original'] ?? null;
$biosConsolaId = (int) $juego['consola_id'];

/*
 * Respaldo: BIOS compartido entre consolas del mismo nucleo.
 *
 * El problema que resuelve es real y se repite. "Neo Geo" y
 * "Arcade (FBNeo)" son entradas distintas del catalogo pero usan el
 * MISMO nucleo. Si se sube un juego de Neo Geo eligiendo la entrada de
 * arcade, el juego se queda sin firmware y FBNeo responde que le faltan
 * los archivos del romset `neogeo`, aunque el BIOS este cargado en la
 * otra consola.
 *
 * Asi que, cuando la consola del juego no tiene BIOS propio, se busca
 * otra consola activa que comparta nucleo y si lo tenga. Es seguro:
 * FBNeo solo carga el romset de sistema que el juego necesita e ignora
 * lo que le sobre, de modo que un arcade que no sea Neo Geo no se ve
 * afectado por tener `neogeo.zip` disponible.
 */
if ($biosNombre === null) {
    $prestado = Database::fila(
        'SELECT id, bios_nombre_original
           FROM consolas
          WHERE core_emulador = :core
            AND id <> :id
            AND archivo_bios IS NOT NULL
            AND activa = 1
          ORDER BY orden ASC
          LIMIT 1',
        ['core' => (string) $juego['core_emulador'], 'id' => $biosConsolaId]
    );

    if ($prestado !== null) {
        $biosNombre    = (string) $prestado['bios_nombre_original'];
        $biosConsolaId = (int) $prestado['id'];
    }
}

$tieneBios = $biosNombre !== null;

/*
 * La URL del BIOS es un NOMBRE PLANO, sin barras ni cadena de consulta:
 * literalmente `neogeo.zip`.
 *
 * El motivo esta en el codigo de EmulatorJS. Cuando se le pide que no
 * descomprima el BIOS, usa la MISMA cadena de la URL como ruta donde
 * escribirlo en el sistema de archivos virtual del emulador:
 *
 *     this.gameManager.FS.writeFile(url, datos)
 *
 * Con `api/bios.php/13/neogeo.zip` intentaba crear el archivo dentro de
 * carpetas inexistentes y fallaba con ErrnoError, dejando la carga
 * congelada al 100 %.
 *
 * Una regla de reescritura en public/.htaccess encamina ese nombre
 * plano hasta api/bios.php. Asi la cadena vale a la vez como direccion
 * de descarga y como nombre de archivo valido, que ademas es el que
 * FBNeo busca.
 */
$urlBios = $tieneBios
    ? rawurlencode((string) $biosNombre)
    : null;

$configJuego = [
    'juegoId'      => (int) $juego['id'],
    'titulo'       => (string) $juego['titulo'],
    'core'         => (string) $juego['core_emulador'],
    'urlRom'       => $urlRom,
    'rutaDatos'    => $rutaDatos,
    'slotInicial'  => $slotInicial,
    'urlBios'      => $urlBios,
    'biosRequerido' => (bool) ($juego['bios_requerido'] ?? false),
    'consola'      => (string) $juego['consola'],
    // Lo usa el reproductor para avisar en moviles cuando el juego es
    // pesado y el navegador puede quedarse sin memoria.
    'romBytes'     => (int) $juego['rom_bytes'],
    'romPeso'      => Validator::bytesLegibles((int) $juego['rom_bytes']),
];

$tituloPagina = (string) $juego['titulo'];
$paginaActual = 'catalogo';
$descripcion  = mb_substr(
    (string) ($juego['descripcion'] ?? $juego['titulo']), 0, 155
);

require __DIR__ . '/partes/cabecera.php';
?>

<nav aria-label="Ruta de navegacion" class="text-sm text-debil mb">
  <a href="index.php">Catalogo</a>
  <span aria-hidden="true"> / </span>
  <a href="index.php?consola=<?= $e($juego['consola_slug']) ?>">
    <?= $e($juego['consola']) ?>
  </a>
  <span aria-hidden="true"> / </span>
  <span><?= $e($juego['titulo']) ?></span>
</nav>

<div class="flex items-center justify-between flex-wrap gap mb">
  <div style="min-width:0">
    <h1 class="mb-0 truncar"><?= $e($juego['titulo']) ?></h1>
    <div class="flex gap-sm flex-wrap" style="margin-top:7px">
      <span class="insignia insignia--consola"
            style="background:<?= $e($juego['color_hex']) ?>">
        <?= $e($juego['consola']) ?>
      </span>
      <?php if ($juego['genero'] !== null): ?>
        <span class="insignia"><?= $e($juego['genero']) ?></span>
      <?php endif; ?>
      <?php if ($juego['anio'] !== null): ?>
        <span class="insignia"><?= (int) $juego['anio'] ?></span>
      <?php endif; ?>
      <span class="insignia">
        <?= (int) $juego['jugadores'] ?>
        jugador<?= (int) $juego['jugadores'] === 1 ? '' : 'es' ?>
      </span>
      <?php if ((int) $juego['publicado'] !== 1): ?>
        <span class="insignia insignia--alerta">borrador</span>
      <?php endif; ?>
    </div>
  </div>

  <div id="indicador-mando" class="mando">
    <span class="mando__punto"></span>
    <span>Buscando mando...</span>
  </div>
</div>

<div class="reproductor">

  <!-- ============== COLUMNA IZQUIERDA: EL EMULADOR ============== -->
  <div>

    <div class="escenario" id="escenario">
      <div id="juego"></div>

      <?php /*
        Boton de salida de pantalla completa.

        Vive DENTRO de #escenario a proposito: cuando el navegador pone
        un elemento en pantalla completa, solo se dibujan ese elemento y
        sus descendientes. Un boton colocado fuera simplemente no se
        veria, que es justo lo que pasaba antes: al entrar en pantalla
        completa en el telefono no quedaba ninguna forma visible de
        salir.

        Permanece oculto y solo lo muestra el CSS cuando el escenario
        esta en pantalla completa (nativa o simulada).
      */ ?>
      <button type="button" class="escenario__salir" id="btn-salir-pantalla"
              aria-label="Salir de pantalla completa">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true">
          <path d="M9 3v4a2 2 0 0 1-2 2H3m18 0h-4a2 2 0 0 1-2-2V3M3 15h4a2 2 0 0 1 2 2v4m6 0v-4a2 2 0 0 1 2-2h4"/>
        </svg>
        <span>Salir</span>
      </button>

      <div class="escenario__cargando" id="cargando">
        <div class="contenido">
          <span class="girador girador--grande" style="color:var(--primario-claro)"></span>
          <div>
            <strong>Cargando <?= $e($juego['consola']) ?></strong>
            <div class="text-sm text-debil" id="mensaje-carga">
              Descargando el nucleo de emulacion (WebAssembly)...
            </div>
          </div>
          <div class="barra-progreso">
            <div class="barra-progreso__relleno" id="progreso-relleno"></div>
          </div>
          <div class="text-xs text-debil">
            ROM: <?= $e(Validator::bytesLegibles((int) $juego['rom_bytes'])) ?>
            <?php if (!$usaLocal): ?>
              &middot; nucleos desde el CDN de EmulatorJS
            <?php else: ?>
              &middot; nucleos locales
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="acciones-juego">
      <button type="button" class="btn btn--primario" id="btn-guardar-rapido">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
          <path d="M17 21v-8H7v8M7 3v5h8"/>
        </svg>
        Guardar partida
      </button>

      <button type="button" class="btn btn--acento" id="btn-cargar-rapido">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
          <path d="M7 10l5 5 5-5M12 15V3"/>
        </svg>
        Cargar ultima
      </button>

      <span class="separador" aria-hidden="true"></span>

      <button type="button" class="btn btn--fantasma" id="btn-pantalla-completa">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3"/>
        </svg>
        Pantalla completa
      </button>

      <button type="button" class="btn btn--peligro" id="btn-terminar">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <path d="M16 17l5-5-5-5M21 12H9"/>
        </svg>
        Terminar partida
      </button>

      <span class="ml-auto mando">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
        </svg>
        <span class="mono" id="cronometro">00:00</span>
      </span>
    </div>

    <!-- Ficha del juego -->
    <div class="panel mt-lg">
      <div class="panel__cabecera">
        <h2 style="font-size:1.05rem">Sobre este juego</h2>
      </div>
      <div class="panel__cuerpo">
        <?php if (($juego['descripcion'] ?? '') !== ''): ?>
          <p><?= nl2br($e($juego['descripcion'])) ?></p>
        <?php else: ?>
          <p class="text-debil">Este juego no tiene descripcion todavia.</p>
        <?php endif; ?>

        <div class="controles mt">
          <div class="controles__fila">
            <span>Consola</span>
            <strong><?= $e($juego['consola']) ?></strong>
          </div>
          <div class="controles__fila">
            <span>Nucleo de emulacion</span>
            <strong class="mono"><?= $e($juego['core_emulador']) ?></strong>
          </div>
          <?php if (($juego['desarrollador'] ?? '') !== ''): ?>
            <div class="controles__fila">
              <span>Desarrollador</span>
              <strong><?= $e($juego['desarrollador']) ?></strong>
            </div>
          <?php endif; ?>
          <div class="controles__fila">
            <span>Tamano de la ROM</span>
            <strong><?= $e(Validator::bytesLegibles((int) $juego['rom_bytes'])) ?></strong>
          </div>
          <div class="controles__fila">
            <span>Licencia declarada</span>
            <strong><?= $e($juego['licencia']) ?></strong>
          </div>
          <div class="controles__fila">
            <span>Partidas jugadas</span>
            <strong><?= (int) $juego['veces_jugado'] ?></strong>
          </div>
        </div>
      </div>
    </div>

    <!-- Controles -->
    <div class="panel mt">
      <div class="panel__cabecera">
        <h2 style="font-size:1.05rem">Controles</h2>
      </div>
      <div class="panel__cuerpo">

        <h3 style="font-size:.92rem;color:var(--texto-tenue)">Teclado</h3>
        <div class="controles mb">
          <div class="controles__fila">
            <span>Movimiento</span>
            <strong><kbd>&larr;</kbd> <kbd>&uarr;</kbd> <kbd>&darr;</kbd> <kbd>&rarr;</kbd></strong>
          </div>
          <div class="controles__fila">
            <span>Boton A / B</span>
            <strong><kbd>X</kbd> <kbd>Z</kbd></strong>
          </div>
          <div class="controles__fila">
            <span>Start / Select</span>
            <strong><kbd>Enter</kbd> <kbd>Shift</kbd></strong>
          </div>
          <div class="controles__fila">
            <span>Gatillos L / R</span>
            <strong><kbd>Q</kbd> <kbd>E</kbd></strong>
          </div>
        </div>

        <h3 style="font-size:.92rem;color:var(--texto-tenue)">Atajos de RetroCloud</h3>
        <div class="controles mb">
          <div class="controles__fila">
            <span>Guardar en ranura 1-4</span>
            <strong><kbd>F1</kbd> &hellip; <kbd>F4</kbd></strong>
          </div>
          <div class="controles__fila">
            <span>Cargar de ranura 1-4</span>
            <strong><kbd>Shift</kbd>+<kbd>F1</kbd> &hellip; <kbd>F4</kbd></strong>
          </div>
          <div class="controles__fila">
            <span>Guardado rapido en la nube</span>
            <strong><kbd>F5</kbd></strong>
          </div>
        </div>

        <div class="aviso aviso--info mb-0">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <rect x="2" y="8" width="20" height="11" rx="5"/>
            <path d="M7 13h2.5M8.25 11.75v2.5"/>
          </svg>
          <div class="aviso__cuerpo">
            <strong>Mandos y moviles</strong>
            Conecta un mando de Xbox, PlayStation o cualquier control compatible
            y el navegador lo reconoce por la <em>Gamepad API</em>; puedes
            reasignar botones desde el engranaje del emulador. En telefonos y
            tabletas aparecen controles tactiles sobre la pantalla.
          </div>
        </div>

      </div>
    </div>

  </div>

  <!-- ============== COLUMNA DERECHA: PARTIDAS ============== -->
  <aside>
    <div class="panel">
      <div class="panel__cabecera">
        <h2 style="font-size:1.05rem;flex:1">Partidas en la nube</h2>
        <span class="insignia insignia--exito">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
            <path d="M18 10h-1.3A7 7 0 1 0 4 15.5"/>
            <path d="M18 10a4 4 0 0 1 0 8H7"/>
          </svg>
          Sincronizado
        </span>
      </div>

      <div class="panel__cuerpo">
        <p class="text-sm text-debil" style="margin-bottom:14px">
          Cada ranura guarda un volcado binario completo del estado del
          emulador en el servidor. La ranura de autoguardado se actualiza
          sola cada 3 minutos mientras juegas.
        </p>

        <div class="ranuras" id="lista-ranuras" aria-live="polite">
          <div class="esqueleto" style="height:84px"></div>
          <div class="esqueleto" style="height:84px"></div>
          <div class="esqueleto" style="height:84px"></div>
        </div>
      </div>
    </div>

    <div class="tarjeta tarjeta--plana mt text-xs text-debil">
      <strong style="color:var(--texto-tenue)">¿Como funciona?</strong>
      <ol style="margin:8px 0 0;padding-left:18px;line-height:1.7">
        <li>El emulador vuelca su memoria a un arreglo de bytes.</li>
        <li>El navegador lo envia como <code>Blob</code> por <code>fetch()</code>.</li>
        <li>PHP valida la sesion, calcula el <code>SHA-256</code> y lo escribe
            fuera del <em>document root</em>.</li>
        <li>MySQL registra la ranura, su firma y el tiempo jugado.</li>
      </ol>
    </div>
  </aside>

</div>

<?php /*
  Configuracion para el modulo JS.
  JSON_HEX_TAG convierte < y > en < y >: aunque un titulo
  contuviera </script>, no puede cerrar esta etiqueta.
*/ ?>
<script type="application/json" id="config-juego"><?=
  json_encode(
      $configJuego,
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
  )
?></script>

<?php
$scriptModulo = 'assets/js/emulador.js';
require __DIR__ . '/partes/pie.php';
