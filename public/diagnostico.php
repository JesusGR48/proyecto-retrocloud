<?php
/**
 * RetroCloud · Diagnostico de la instalacion
 * ---------------------------------------------------------------------
 * Revisa de un vistazo que el entorno este listo: version de PHP,
 * extensiones, conexion a MySQL, tablas, permisos de escritura, limites
 * de subida y estado de HTTPS.
 *
 * NOTA: esta pagina revela detalles del servidor, asi que en cuanto el
 * proyecto este desplegado conviene borrarla o protegerla con sesion de
 * administrador. El aviso al final lo recuerda.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Database;
use RetroCloud\Validator;

$e = static fn(mixed $v): string => Validator::e($v);

/** @var list<array{grupo:string,nombre:string,estado:string,detalle:string}> $pruebas */
$pruebas = [];

$agregar = static function (
    string $grupo,
    string $nombre,
    bool|string $estado,
    string $detalle = ''
) use (&$pruebas): void {
    $pruebas[] = [
        'grupo'   => $grupo,
        'nombre'  => $nombre,
        'estado'  => is_string($estado) ? $estado : ($estado ? 'ok' : 'error'),
        'detalle' => $detalle,
    ];
};

// =====================================================================
// 1. PHP
// =====================================================================
$versionOk = PHP_VERSION_ID >= 80000;
$agregar('PHP', 'Version de PHP', $versionOk ? 'ok' : 'error',
    PHP_VERSION . ($versionOk ? '' : ' — se requiere 8.0 o superior'));

foreach ([
    'pdo_mysql' => 'Conexion con MySQL (obligatorio)',
    'mbstring'  => 'Cadenas multibyte / UTF-8 (obligatorio)',
    'fileinfo'  => 'Deteccion de tipo de archivo (recomendado)',
    'gd'        => 'Procesamiento de imagenes (opcional)',
    'openssl'   => 'Criptografia y HTTPS (recomendado)',
    'json'      => 'Serializacion JSON (obligatorio)',
] as $ext => $para) {
    $cargada = extension_loaded($ext);
    $critica = in_array($ext, ['pdo_mysql', 'mbstring', 'json'], true);
    $agregar('PHP', "Extension $ext",
        $cargada ? 'ok' : ($critica ? 'error' : 'aviso'),
        $para);
}

// =====================================================================
// 2. Base de datos
// =====================================================================
$bdViva = false;
try {
    Database::conexion();
    $bdViva = true;
    $agregar('Base de datos', 'Conexion a MySQL', true,
        DB_USUARIO . '@' . DB_HOST . ':' . DB_PUERTO . '/' . DB_NOMBRE);

    $version = Database::valor('SELECT VERSION()');
    $agregar('Base de datos', 'Version del motor', true, (string) $version);
} catch (\Throwable $ex) {
    $agregar('Base de datos', 'Conexion a MySQL', false, $ex->getMessage());
}

if ($bdViva) {
    $esperadas = [
        'usuarios', 'consolas', 'generos', 'juegos', 'save_states',
        'sesiones_juego', 'favoritos', 'intentos_login', 'logros',
        'usuario_logros',
    ];

    $existentes = array_map(
        static fn(array $f): string => (string) reset($f),
        Database::filas('SHOW TABLES')
    );

    $faltan = array_diff($esperadas, $existentes);
    $agregar('Base de datos', 'Tablas del esquema',
        $faltan === [] ? 'ok' : 'error',
        $faltan === []
            ? count($esperadas) . ' tablas presentes'
            : 'Faltan: ' . implode(', ', $faltan) . ' — importa database/schema.sql');

    // Vistas y rutinas
    foreach (['vista_catalogo', 'vista_ranking', 'vista_perfil'] as $vista) {
        $hay = in_array($vista, $existentes, true);
        $agregar('Base de datos', "Vista $vista", $hay ? 'ok' : 'aviso',
            $hay ? 'disponible' : 'no encontrada');
    }

    try {
        $sp = Database::valor(
            "SELECT COUNT(*) FROM information_schema.ROUTINES
              WHERE ROUTINE_SCHEMA = :bd AND ROUTINE_NAME = 'sp_cerrar_sesion'",
            ['bd' => DB_NOMBRE]
        );
        $agregar('Base de datos', 'Procedimiento sp_cerrar_sesion',
            (int) $sp > 0 ? 'ok' : 'aviso',
            (int) $sp > 0 ? 'registrado' : 'no encontrado');
    } catch (\Throwable) {
        $agregar('Base de datos', 'Procedimiento sp_cerrar_sesion', 'aviso',
            'no se pudo comprobar');
    }

    if ($faltan === []) {
        try {
            $admins = (int) Database::valor(
                "SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1"
            );
            $agregar('Base de datos', 'Administradores activos',
                $admins > 0 ? 'ok' : 'error',
                $admins > 0
                    ? "$admins cuenta(s)"
                    : 'Ninguno — importa database/seed.sql');

            $juegos = (int) Database::valor('SELECT COUNT(*) FROM juegos');
            $agregar('Base de datos', 'Juegos en el catalogo',
                $juegos > 0 ? 'ok' : 'aviso',
                $juegos > 0
                    ? "$juegos juego(s)"
                    : 'Catalogo vacio — subelos desde Panel > Juegos');
        } catch (\Throwable $ex) {
            $agregar('Base de datos', 'Lectura de datos', false, $ex->getMessage());
        }
    }
}

// =====================================================================
// 3. Sistema de archivos
// =====================================================================
foreach ([
    'storage/roms'           => RUTA_ROMS,
    'storage/saves'          => RUTA_SAVES,
    'storage/capturas'       => RUTA_CAPTURAS,
    'public/assets/portadas' => RUTA_PORTADAS,
    'logs'                   => RUTA_LOGS,
] as $etiqueta => $ruta) {
    if (!is_dir($ruta)) {
        $agregar('Archivos', $etiqueta, 'error', 'La carpeta no existe');
        continue;
    }
    $escribible = is_writable($ruta);
    $cuantos = count(array_diff((array) @scandir($ruta) ?: [], ['.', '..', '.htaccess']));
    $agregar('Archivos', $etiqueta, $escribible ? 'ok' : 'error',
        ($escribible ? 'escritura permitida' : 'SIN permiso de escritura')
        . " · $cuantos archivo(s)");
}

/* El storage debe quedar FUERA del document root. */
$storageReal = realpath(RUTA_STORAGE);
$publicoReal = realpath(RUTA_PUBLICA);
$fuera = $storageReal !== false && $publicoReal !== false
      && !str_starts_with($storageReal, $publicoReal);

$agregar('Archivos', 'storage/ fuera del document root',
    $fuera ? 'ok' : 'error',
    $fuera
        ? 'Las ROMs no son accesibles por URL directa'
        : 'RIESGO: las ROMs podrian descargarse sin pasar por PHP');

// =====================================================================
// 4. Limites de subida
// =====================================================================
$aBytes = static function (string $valor): int {
    $valor = trim($valor);
    if ($valor === '') return 0;
    $ultimo = strtolower($valor[strlen($valor) - 1]);
    $n = (int) $valor;
    return match ($ultimo) {
        'g' => $n * 1024 * 1024 * 1024,
        'm' => $n * 1024 * 1024,
        'k' => $n * 1024,
        default => $n,
    };
};

$subidaMax = $aBytes((string) ini_get('upload_max_filesize'));
$postMax   = $aBytes((string) ini_get('post_max_size'));

$agregar('Subidas', 'upload_max_filesize',
    $subidaMax >= MAX_BYTES_ROM ? 'ok' : 'aviso',
    ini_get('upload_max_filesize')
    . ' (la app espera hasta ' . Validator::bytesLegibles(MAX_BYTES_ROM) . ')');

$agregar('Subidas', 'post_max_size',
    $postMax > $subidaMax ? 'ok' : 'aviso',
    ini_get('post_max_size') . ' — debe superar a upload_max_filesize');

$agregar('Subidas', 'max_execution_time',
    (int) ini_get('max_execution_time') >= 120
        || (int) ini_get('max_execution_time') === 0 ? 'ok' : 'aviso',
    ini_get('max_execution_time') . ' s');

$agregar('Subidas', 'memory_limit', 'ok', (string) ini_get('memory_limit'));

// =====================================================================
// 5. Seguridad y servidor
// =====================================================================
$esHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$agregar('Seguridad', 'HTTPS activo', $esHttps ? 'ok' : 'aviso',
    $esHttps
        ? 'La conexion esta cifrada'
        : 'Sirviendo por HTTP — ver docs/configuracion-ssl.md');

$agregar('Seguridad', 'Sesion iniciada',
    session_status() === PHP_SESSION_ACTIVE,
    'Cookie: ' . SESION_NOMBRE . ' · HttpOnly, SameSite=Lax');

$agregar('Seguridad', 'Modo de errores',
    APP_ENTORNO === 'produccion' ? 'ok' : 'aviso',
    APP_ENTORNO === 'desarrollo'
        ? 'desarrollo — los errores se muestran en pantalla'
        : 'produccion — los errores van a logs/');

$agregar('Seguridad', 'Coste de bcrypt', BCRYPT_COSTE >= 10,
    'cost = ' . BCRYPT_COSTE . (BCRYPT_COSTE >= 10 ? '' : ' — sube a 12'));

$agregar('Servidor', 'Software', 'ok',
    (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'desconocido'));

$modRewrite = function_exists('apache_get_modules')
    ? in_array('mod_rewrite', apache_get_modules(), true)
    : null;

$agregar('Servidor', 'mod_rewrite',
    $modRewrite === null ? 'aviso' : ($modRewrite ? 'ok' : 'aviso'),
    $modRewrite === null
        ? 'no se pudo comprobar (PHP no corre como modulo de Apache)'
        : ($modRewrite ? 'disponible' : 'no disponible'));

// =====================================================================
// 6. Emulador
// =====================================================================
$agregar('Emulador', 'Instalacion de EmulatorJS', 'ok',
    EMULADOR_LOCAL
        ? 'Local: public/emulatorjs/data/ (funciona sin internet)'
        : 'CDN: cdn.emulatorjs.org (requiere conexion a internet)');

$agregar('Emulador', 'Origen autorizado en la CSP', 'ok',
    EMULADOR_ORIGEN !== ''
        ? EMULADOR_ORIGEN . ' (coincide con el origen de carga)'
        : "Solo 'self' — el emulador se sirve en local");

// =====================================================================
// Resumen
// =====================================================================
$errores = count(array_filter($pruebas, static fn($p) => $p['estado'] === 'error'));
$avisos  = count(array_filter($pruebas, static fn($p) => $p['estado'] === 'aviso'));

$tituloPagina = 'Diagnostico';
$paginaActual = '';

require __DIR__ . '/partes/cabecera.php';
?>

<h1>Diagnostico de la instalacion</h1>
<p class="text-tenue">
  Comprobacion automatica del entorno de RetroCloud.
</p>

<?php if ($errores === 0 && $avisos === 0): ?>
  <div class="aviso aviso--exito">
    <div class="aviso__cuerpo">
      <strong>Todo en orden</strong>
      Las <?= count($pruebas) ?> comprobaciones pasaron sin incidencias.
    </div>
  </div>
<?php else: ?>
  <div class="aviso <?= $errores > 0 ? 'aviso--error' : 'aviso--alerta' ?>">
    <div class="aviso__cuerpo">
      <strong>
        <?= $errores ?> error<?= $errores === 1 ? '' : 'es' ?>
        y <?= $avisos ?> aviso<?= $avisos === 1 ? '' : 's' ?>
      </strong>
      <?= $errores > 0
        ? 'Los errores impiden que la aplicacion funcione correctamente.'
        : 'Los avisos no bloquean el funcionamiento, pero conviene revisarlos.' ?>
    </div>
  </div>
<?php endif; ?>

<?php
$porGrupo = [];
foreach ($pruebas as $p) {
    $porGrupo[$p['grupo']][] = $p;
}
?>

<?php foreach ($porGrupo as $grupo => $items): ?>
  <section class="panel mb">
    <div class="panel__cabecera">
      <h2 style="font-size:1.02rem"><?= $e($grupo) ?></h2>
    </div>
    <div class="tabla-envoltorio">
      <table class="tabla" style="min-width:480px">
        <tbody>
          <?php foreach ($items as $p): ?>
            <tr>
              <td style="width:40px">
                <?php if ($p['estado'] === 'ok'): ?>
                  <span style="color:var(--exito)" title="Correcto">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.6" stroke-linecap="round">
                      <path d="M20 6 9 17l-5-5"/>
                    </svg>
                  </span>
                <?php elseif ($p['estado'] === 'aviso'): ?>
                  <span style="color:var(--aviso)" title="Aviso">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                      <path d="M12 8v5m0 3h.01"/><circle cx="12" cy="12" r="9"/>
                    </svg>
                  </span>
                <?php else: ?>
                  <span style="color:var(--error)" title="Error">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.6" stroke-linecap="round">
                      <path d="M18 6 6 18M6 6l12 12"/>
                    </svg>
                  </span>
                <?php endif; ?>
              </td>
              <td><strong><?= $e($p['nombre']) ?></strong></td>
              <td class="text-sm text-tenue"><?= $e($p['detalle']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endforeach; ?>

<section class="panel mb">
  <div class="panel__cabecera">
    <h2 style="font-size:1.02rem">Rutas del sistema</h2>
  </div>
  <div class="panel__cuerpo text-sm mono" style="word-break:break-all;line-height:2">
    <div><span class="text-debil">RUTA_RAIZ:</span> <?= $e(RUTA_RAIZ) ?></div>
    <div><span class="text-debil">RUTA_PUBLICA:</span> <?= $e(RUTA_PUBLICA) ?></div>
    <div><span class="text-debil">RUTA_STORAGE:</span> <?= $e(RUTA_STORAGE) ?></div>
    <div><span class="text-debil">APP_URL:</span> <?= $e(APP_URL) ?></div>
  </div>
</section>

<div class="aviso aviso--alerta">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-width="2" stroke-linecap="round" aria-hidden="true">
    <path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z"/>
  </svg>
  <div class="aviso__cuerpo">
    <strong>Antes de entregar o publicar</strong>
    Esta pagina expone detalles del servidor. Borra
    <code>public/diagnostico.php</code> o protegela exigiendo sesion de
    administrador, y cambia <code>APP_ENTORNO</code> a
    <code>'produccion'</code> en <code>src/config.php</code>.
  </div>
</div>

<div class="flex gap flex-wrap mt">
  <a class="btn btn--primario" href="index.php">Ir al catalogo</a>
  <?php if (Auth::esAdmin()): ?>
    <a class="btn btn--fantasma" href="admin/index.php">Panel de administracion</a>
  <?php endif; ?>
  <button type="button" class="btn btn--fantasma" onclick="location.reload()">
    Volver a comprobar
  </button>
</div>

<?php
$sinApp = false;
require __DIR__ . '/partes/pie.php';
