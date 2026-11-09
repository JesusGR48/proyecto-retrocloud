<?php
/**
 * RetroCloud · API · /api/sesiones.php
 * ---------------------------------------------------------------------
 * Telemetria de uso: cuanto se juega y a que.
 *
 *   POST iniciar · abre una sesion de juego y devuelve su id
 *   POST latido  · el cliente reporta tiempo acumulado cada 30 s
 *   POST cerrar  · cierra la sesion (invoca el procedimiento almacenado)
 *
 * El "latido" (heartbeat) resuelve un problema real: si el usuario
 * cierra la pestana de golpe, el evento de cierre puede no llegar. Con
 * un latido periodico, el peor caso es perder 30 segundos de registro
 * en lugar de la partida entera.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Csrf;
use RetroCloud\Database;
use RetroCloud\Response;

$cuerpo = Response::cuerpoJson();
$accion = (string) ($_GET['accion'] ?? $cuerpo['accion'] ?? '');

$usuarioId = Auth::exigirApi();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Csrf::exigir($cuerpo);
}

switch ($accion) {

    // =================================================================
    case 'iniciar': {
        Response::exigirMetodo('POST');

        $juegoId = (int) ($cuerpo['juego_id'] ?? 0);
        if ($juegoId <= 0) {
            Response::error('Falta el parametro juego_id.', 422);
        }

        $existe = Database::valor(
            'SELECT 1 FROM juegos WHERE id = :id AND (publicado = 1 OR :admin = 1)',
            ['id' => $juegoId, 'admin' => Auth::esAdmin() ? 1 : 0]
        );
        if ($existe === null) {
            Response::noEncontrado('Ese juego no existe o no esta disponible.');
        }

        $sesionId = Database::insertar(
            'INSERT INTO sesiones_juego (usuario_id, juego_id) VALUES (:u, :j)',
            ['u' => $usuarioId, 'j' => $juegoId]
        );

        Response::ok([
            'sesion_id' => $sesionId,
            'iniciada'  => date('c'),
        ], 201);
    }

    // =================================================================
    case 'latido': {
        Response::exigirMetodo('POST');

        $sesionId = (int) ($cuerpo['sesion_id'] ?? 0);
        $segundos = (int) ($cuerpo['segundos'] ?? 0);

        if ($sesionId <= 0) {
            Response::error('Falta el parametro sesion_id.', 422);
        }

        // Techo de 24 h: un cliente manipulado no puede inflar el ranking.
        $segundos = max(0, min(86400, $segundos));

        $afectadas = Database::afectar(
            'UPDATE sesiones_juego
                SET segundos_jugados = :seg
              WHERE id = :id AND usuario_id = :u AND finalizada_en IS NULL',
            ['seg' => $segundos, 'id' => $sesionId, 'u' => $usuarioId]
        );

        if ($afectadas === 0) {
            Response::error('Sesion de juego no encontrada o ya cerrada.', 404);
        }

        Response::ok(['segundos' => $segundos]);
    }

    // =================================================================
    case 'cerrar': {
        Response::exigirMetodo('POST');

        $sesionId = (int) ($cuerpo['sesion_id'] ?? 0);
        $segundos = max(0, min(86400, (int) ($cuerpo['segundos'] ?? 0)));

        if ($sesionId <= 0) {
            Response::error('Falta el parametro sesion_id.', 422);
        }

        $propia = Database::valor(
            'SELECT 1 FROM sesiones_juego WHERE id = :id AND usuario_id = :u',
            ['id' => $sesionId, 'u' => $usuarioId]
        );
        if ($propia === null) {
            Response::noEncontrado('Sesion de juego no encontrada.');
        }

        // Procedimiento almacenado: cierra la sesion y suma el contador
        // del juego en un unico viaje al servidor de base de datos.
        Database::ejecutar(
            'CALL sp_cerrar_sesion(:sesion, :usuario, :segundos)',
            ['sesion' => $sesionId, 'usuario' => $usuarioId, 'segundos' => $segundos]
        );

        Response::ok([
            'mensaje'  => 'Sesion de juego registrada.',
            'segundos' => $segundos,
        ]);
    }

    // =================================================================
    default:
        Response::error('Accion no reconocida. Usa: iniciar, latido o cerrar.', 400);
}
