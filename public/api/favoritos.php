<?php
/**
 * RetroCloud · API · /api/favoritos.php
 * ---------------------------------------------------------------------
 * Relacion N:M entre usuarios y juegos.
 *
 *   GET  listar   · los favoritos del usuario en sesion
 *   POST alternar · agrega si no estaba, quita si estaba (toggle)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Csrf;
use RetroCloud\Database;
use RetroCloud\Response;

$cuerpo = Response::cuerpoJson();
$accion = (string) ($_GET['accion'] ?? $cuerpo['accion'] ?? 'listar');

$usuarioId = Auth::exigirApi();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Csrf::exigir($cuerpo);
}

switch ($accion) {

    case 'listar': {
        Response::exigirMetodo('GET');

        $favoritos = Database::filas(
            'SELECT j.id, j.titulo, j.slug, j.portada, j.anio,
                    c.nombre AS consola, c.slug AS consola_slug, c.color_hex,
                    f.creado_en AS agregado_en
               FROM favoritos f
               JOIN juegos   j ON j.id = f.juego_id
               JOIN consolas c ON c.id = j.consola_id
              WHERE f.usuario_id = :u AND j.publicado = 1
              ORDER BY f.creado_en DESC',
            ['u' => $usuarioId]
        );

        foreach ($favoritos as &$f) {
            $f['id']          = (int) $f['id'];
            $f['url_portada'] = $f['portada'] !== null && $f['portada'] !== ''
                ? 'assets/portadas/' . rawurlencode((string) $f['portada']) : null;
            $f['url_jugar']   = 'jugar.php?id=' . $f['id'];
        }
        unset($f);

        Response::ok(['favoritos' => $favoritos, 'total' => count($favoritos)]);
    }

    case 'alternar': {
        Response::exigirMetodo('POST');

        $juegoId = (int) ($cuerpo['juego_id'] ?? $_POST['juego_id'] ?? 0);
        if ($juegoId <= 0) {
            Response::error('Falta el parametro juego_id.', 422);
        }

        if (Database::valor('SELECT 1 FROM juegos WHERE id = :id', ['id' => $juegoId]) === null) {
            Response::noEncontrado('Ese juego no existe.');
        }

        $borradas = Database::afectar(
            'DELETE FROM favoritos WHERE usuario_id = :u AND juego_id = :j',
            ['u' => $usuarioId, 'j' => $juegoId]
        );

        if ($borradas > 0) {
            Response::ok(['favorito' => false, 'mensaje' => 'Quitado de favoritos.']);
        }

        Database::ejecutar(
            'INSERT INTO favoritos (usuario_id, juego_id) VALUES (:u, :j)',
            ['u' => $usuarioId, 'j' => $juegoId]
        );

        Response::ok(['favorito' => true, 'mensaje' => 'Agregado a favoritos.']);
    }

    default:
        Response::error('Accion no reconocida. Usa: listar o alternar.', 400);
}
