<?php
/**
 * RetroCloud · API · /api/rom.php?id=N
 * ---------------------------------------------------------------------
 * Portero de las ROMs.
 *
 * Las ROMs NO son accesibles por URL directa: viven en /storage/roms,
 * fuera del document root. Este script es el unico camino hacia ellas y
 * antes de entregar un solo byte comprueba que:
 *
 *   1. haya una sesion valida,
 *   2. el juego exista y este publicado (o quien pide sea admin),
 *   3. la peticion venga del propio sitio (anti hotlinking),
 *   4. la ruta resuelta siga dentro de /storage/roms.
 *
 * Despues delega en Storage::enviarArchivo(), que hace streaming por
 * trozos con soporte de peticiones Range (necesario para que Chrome no
 * aborte la descarga de archivos grandes).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Database;
use RetroCloud\Storage;

// ---------------------------------------------------------------------
// 1. Metodo
// ---------------------------------------------------------------------
$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($metodo, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit('Metodo no permitido.');
}

// ---------------------------------------------------------------------
// 2. Sesion
//    Aqui no respondemos JSON: el consumidor es el emulador, no el JS
//    de la interfaz. Un 401 seco es lo correcto.
// ---------------------------------------------------------------------
if (!Auth::autenticado()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Necesitas iniciar sesion para cargar un juego.');
}

// ---------------------------------------------------------------------
// 3. Anti hotlinking
//    Si llega un Referer y apunta a otro host, se rechaza. Cuando no
//    hay Referer se deja pasar: muchos navegadores lo omiten por
//    politica de privacidad y bloquearlo romperia usuarios legitimos.
// ---------------------------------------------------------------------
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer !== '') {
    $hostReferer = parse_url($referer, PHP_URL_HOST);
    $hostPropio  = parse_url(APP_URL, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? '');
    // Quita el puerto del Host para comparar manzanas con manzanas.
    $hostPropio  = explode(':', (string) $hostPropio)[0];

    if (is_string($hostReferer) && $hostReferer !== '' && $hostReferer !== $hostPropio) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Origen no autorizado.');
    }
}

// ---------------------------------------------------------------------
// 4. El juego
// ---------------------------------------------------------------------
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Falta el parametro id.');
}

$juego = Database::fila(
    'SELECT j.id, j.titulo, j.archivo_rom, j.rom_hash, j.rom_bytes, j.publicado,
            c.slug AS consola_slug
       FROM juegos j
       JOIN consolas c ON c.id = j.consola_id
      WHERE j.id = :id',
    ['id' => $id]
);

if ($juego === null) {
    http_response_code(404);
    exit('Juego no encontrado.');
}

if ((int) $juego['publicado'] !== 1 && !Auth::esAdmin()) {
    http_response_code(404);
    exit('Juego no disponible.');
}

// ---------------------------------------------------------------------
// 5. El archivo
// ---------------------------------------------------------------------
$ruta = Storage::rutaRom((string) $juego['archivo_rom']);
if ($ruta === null) {
    // La fila existe pero el binario no: inconsistencia que conviene
    // dejar registrada para el administrador.
    error_log(sprintf(
        'ROM ausente en disco: juego #%d (%s) -> %s',
        (int) $juego['id'],
        (string) $juego['titulo'],
        (string) $juego['archivo_rom']
    ));
    http_response_code(404);
    exit('El archivo de este juego no esta disponible en el servidor.');
}

// Nombre con el que el emulador vera el archivo. EmulatorJS decide el
// manejador por la extension, asi que esta debe conservarse intacta.
$extension = strtolower((string) pathinfo((string) $juego['archivo_rom'], PATHINFO_EXTENSION));
$nombreVisible = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $juego['titulo'])
               . '.' . $extension;

// ETag basado en el SHA-256: si el navegador ya tiene la ROM en cache,
// responde 304 y se ahorra volver a bajar decenas de megas.
$etag = '"' . substr((string) $juego['rom_hash'], 0, 32) . '"';
header('ETag: ' . $etag);

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

if ($metodo === 'HEAD') {
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (string) $juego['rom_bytes']);
    header('Accept-Ranges: bytes');
    exit;
}

Storage::enviarArchivo(
    $ruta,
    $nombreVisible,
    'application/octet-stream',
    false
);
