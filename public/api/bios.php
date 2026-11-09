<?php
/**
 * RetroCloud · API · /api/bios.php?consola=N
 * ---------------------------------------------------------------------
 * Entrega la firmware de sistema de una consola.
 *
 * Mismo planteamiento que rom.php: el archivo vive en /storage/bios,
 * fuera del document root, y este script es el unico camino hacia el.
 * Antes de entregar un byte comprueba que haya sesion activa y que la
 * consola exista y tenga BIOS cargado.
 *
 * Por que el emulador lo necesita: plataformas como Neo Geo o
 * PlayStation no son cartuchos autonomos. El juego se ejecuta SOBRE la
 * firmware de la maquina, que contiene las rutinas de arranque y las
 * llamadas del sistema. Sin ella el nucleo no sabe ni como empezar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Database;
use RetroCloud\Storage;

$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($metodo, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit('Metodo no permitido.');
}

if (!Auth::autenticado()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Necesitas iniciar sesion.');
}

/* Anti hotlinking, igual que en rom.php. */
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer !== '') {
    $hostReferer = parse_url($referer, PHP_URL_HOST);
    $hostPropio  = explode(':', (string) (parse_url(APP_URL, PHP_URL_HOST)
                 ?: ($_SERVER['HTTP_HOST'] ?? '')))[0];

    if (is_string($hostReferer) && $hostReferer !== '' && $hostReferer !== $hostPropio) {
        http_response_code(403);
        exit('Origen no autorizado.');
    }
}

/*
 * El id de la consola viaja en la RUTA, no en la cadena de consulta:
 *
 *     api/bios.php/13/neogeo.zip
 *
 * Es importante que la URL termine exactamente en `neogeo.zip`, sin
 * ningun `?algo=...` detras. EmulatorJS deriva de la URL el nombre con
 * el que escribe el archivo en el sistema de archivos del emulador, y
 * con una cadena de consulta acababa intentando crear un archivo
 * llamado `neogeo.zip?consola=13`, lo que reventaba con ErrnoError y
 * dejaba la carga colgada al 100%.
 *
 * Se acepta tambien ?consola=N por compatibilidad.
 */
/*
 * Se puede pedir de dos formas:
 *
 *   1. Por NOMBRE   ?archivo=neogeo.zip     <- la que usa el reproductor
 *   2. Por ID       ?consola=13             <- compatibilidad y pruebas
 *
 * La primera existe por una razon muy concreta. EmulatorJS, cuando se
 * le pide que no descomprima el BIOS, usa la MISMA cadena de la URL
 * como ruta donde escribirlo dentro del sistema de archivos virtual del
 * emulador:
 *
 *     this.gameManager.FS.writeFile(url, datos)
 *
 * Si la URL lleva barras (`api/bios.php/13/neogeo.zip`), intenta crear
 * el archivo dentro de carpetas que no existen ahi dentro y falla con
 * ErrnoError, dejando la carga congelada al 100%.
 *
 * Por eso el reproductor pide simplemente `neogeo.zip`, un nombre plano
 * sin barras: una regla de reescritura en public/.htaccess lo encamina
 * hasta aqui. Asi la URL sirve a la vez como direccion de descarga y
 * como nombre de archivo valido, que es justo el que FBNeo busca.
 */
$porNombre = trim((string) ($_GET['archivo'] ?? ''));
$consolaId = (int) ($_GET['consola'] ?? 0);

if ($porNombre !== '') {
    // basename() descarta cualquier intento de colar una ruta.
    $porNombre = basename(str_replace('\\', '/', $porNombre));

    $consola = Database::fila(
        'SELECT id, nombre, archivo_bios, bios_hash, bios_bytes, bios_nombre_original
           FROM consolas
          WHERE bios_nombre_original = :n AND activa = 1
          LIMIT 1',
        ['n' => $porNombre]
    );
} elseif ($consolaId > 0) {
    $consola = Database::fila(
        'SELECT id, nombre, archivo_bios, bios_hash, bios_bytes, bios_nombre_original
           FROM consolas
          WHERE id = :id AND activa = 1',
        ['id' => $consolaId]
    );
} else {
    http_response_code(400);
    exit('Indica el archivo o el identificador de la consola.');
}

if ($consola === null || ($consola['archivo_bios'] ?? null) === null) {
    http_response_code(404);
    exit('Esta consola no tiene BIOS cargado.');
}

$ruta = Storage::rutaBios((string) $consola['archivo_bios']);
if ($ruta === null) {
    error_log(sprintf(
        'BIOS ausente en disco: consola #%d (%s) -> %s',
        (int) $consola['id'],
        (string) $consola['nombre'],
        (string) $consola['archivo_bios']
    ));
    http_response_code(404);
    exit('El archivo de BIOS no esta disponible en el servidor.');
}

/*
 * Se entrega con su NOMBRE ORIGINAL, no con el interno.
 * Algunos nucleos identifican la firmware por el nombre exacto del
 * archivo (por ejemplo `neogeo.zip`), asi que ese dato importa.
 */
$nombreVisible = (string) ($consola['bios_nombre_original'] ?? 'bios.bin');

$etag = '"' . substr((string) $consola['bios_hash'], 0, 32) . '"';
header('ETag: ' . $etag);

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

if ($metodo === 'HEAD') {
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (string) $consola['bios_bytes']);
    exit;
}

Storage::enviarArchivo($ruta, $nombreVisible, 'application/octet-stream', false);
