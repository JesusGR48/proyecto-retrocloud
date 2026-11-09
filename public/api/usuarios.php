<?php
/**
 * RetroCloud · API · /api/usuarios.php
 * ---------------------------------------------------------------------
 * Gestion de cuentas. Todo el endpoint exige rol admin.
 *
 *   GET  listar    · usuarios con sus metricas, paginado
 *   POST rol       · cambia el rol de una cuenta
 *   POST activo    · habilita o deshabilita una cuenta
 *   POST eliminar  · borra la cuenta y, en cascada, sus datos
 *
 * Reglas de proteccion implementadas abajo:
 *   · un admin no puede degradarse, desactivarse ni borrarse a si mismo
 *     (asi nadie se queda fuera del panel por accidente);
 *   · el sistema nunca se queda sin administradores activos.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Csrf;
use RetroCloud\Database;
use RetroCloud\Response;
use RetroCloud\Storage;
use RetroCloud\Validator;

$cuerpo = Response::cuerpoJson();
$accion = (string) ($_GET['accion'] ?? $cuerpo['accion'] ?? 'listar');

$adminId = Auth::exigirAdminApi();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Csrf::exigir($cuerpo);
}

/** Cuenta los administradores activos distintos del indicado. */
$otrosAdmins = static fn(int $exceptoId): int => (int) Database::valor(
    'SELECT COUNT(*) FROM usuarios
      WHERE rol = :rol AND activo = 1 AND id <> :id',
    ['rol' => 'admin', 'id' => $exceptoId]
);

switch ($accion) {

    // =================================================================
    case 'listar': {
        Response::exigirMetodo('GET');

        $busqueda  = trim((string) ($_GET['q'] ?? ''));
        $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));
        $porPagina = max(1, min(100, (int) ($_GET['por_pagina'] ?? 25)));
        $offset    = ($pagina - 1) * $porPagina;

        $where  = '';
        $params = [];
        if ($busqueda !== '') {
            // Un marcador por columna: PDO con sentencias preparadas
            // reales no admite repetir el mismo nombre en la consulta.
            $where = ' WHERE (u.nombre_usuario LIKE :q_nombre OR u.correo LIKE :q_correo)';
            $patron = '%' . $busqueda . '%';
            $params['q_nombre'] = $patron;
            $params['q_correo'] = $patron;
        }

        $total = (int) Database::valor(
            "SELECT COUNT(*) FROM usuarios u$where",
            $params
        );

        $usuarios = Database::filas(
            "SELECT u.id, u.nombre_usuario, u.correo, u.rol, u.activo,
                    u.creado_en, u.ultimo_acceso,
                    (SELECT COUNT(*) FROM sesiones_juego s WHERE s.usuario_id = u.id)
                      AS partidas,
                    (SELECT COALESCE(SUM(s.segundos_jugados),0) FROM sesiones_juego s
                      WHERE s.usuario_id = u.id) AS segundos,
                    (SELECT COUNT(*) FROM save_states v WHERE v.usuario_id = u.id)
                      AS saves
               FROM usuarios u
               $where
              ORDER BY u.creado_en DESC
              LIMIT $porPagina OFFSET $offset",
            $params
        );

        foreach ($usuarios as &$u) {
            $u['id']       = (int) $u['id'];
            $u['activo']   = (bool) $u['activo'];
            $u['partidas'] = (int) $u['partidas'];
            $u['saves']    = (int) $u['saves'];
            $u['tiempo']   = Validator::tiempoLegible((int) $u['segundos']);
            $u['es_tu_cuenta'] = $u['id'] === $adminId;
        }
        unset($u);

        Response::ok([
            'usuarios' => $usuarios,
            'paginacion' => [
                'pagina'     => $pagina,
                'por_pagina' => $porPagina,
                'total'      => $total,
                'paginas'    => (int) ceil($total / $porPagina),
            ],
        ]);
    }

    // =================================================================
    case 'rol': {
        Response::exigirMetodo('POST');

        $id  = (int) ($cuerpo['id'] ?? 0);
        $rol = (string) ($cuerpo['rol'] ?? '');

        if ($id <= 0) {
            Response::error('Falta el id del usuario.', 422);
        }
        if (!in_array($rol, ['jugador', 'admin'], true)) {
            Response::error('Rol invalido: usa "jugador" o "admin".', 422);
        }
        if ($id === $adminId) {
            Response::error(
                'No puedes cambiar tu propio rol. Pide a otro administrador que lo haga.',
                409
            );
        }

        $objetivo = Database::fila(
            'SELECT id, nombre_usuario, rol FROM usuarios WHERE id = :id',
            ['id' => $id]
        );
        if ($objetivo === null) {
            Response::noEncontrado('Ese usuario no existe.');
        }

        // Degradar al ultimo admin dejaria el panel inaccesible.
        if ((string) $objetivo['rol'] === 'admin'
            && $rol === 'jugador'
            && $otrosAdmins($id) === 0
        ) {
            Response::error(
                'No se puede degradar al unico administrador activo del sistema.',
                409
            );
        }

        Database::afectar(
            'UPDATE usuarios SET rol = :rol WHERE id = :id',
            ['rol' => $rol, 'id' => $id]
        );

        Response::ok([
            'id'      => $id,
            'rol'     => $rol,
            'mensaje' => sprintf(
                '%s ahora es %s.',
                (string) $objetivo['nombre_usuario'],
                $rol === 'admin' ? 'administrador' : 'jugador'
            ),
        ]);
    }

    // =================================================================
    case 'activo': {
        Response::exigirMetodo('POST');

        $id     = (int) ($cuerpo['id'] ?? 0);
        $activo = !empty($cuerpo['activo']);

        if ($id <= 0) {
            Response::error('Falta el id del usuario.', 422);
        }
        if ($id === $adminId) {
            Response::error('No puedes desactivar tu propia cuenta.', 409);
        }

        $objetivo = Database::fila(
            'SELECT id, nombre_usuario, rol FROM usuarios WHERE id = :id',
            ['id' => $id]
        );
        if ($objetivo === null) {
            Response::noEncontrado('Ese usuario no existe.');
        }

        if (!$activo
            && (string) $objetivo['rol'] === 'admin'
            && $otrosAdmins($id) === 0
        ) {
            Response::error(
                'No se puede desactivar al unico administrador activo del sistema.',
                409
            );
        }

        Database::afectar(
            'UPDATE usuarios SET activo = :a WHERE id = :id',
            ['a' => $activo ? 1 : 0, 'id' => $id]
        );

        Response::ok([
            'id'      => $id,
            'activo'  => $activo,
            'mensaje' => sprintf(
                'Cuenta de %s %s.',
                (string) $objetivo['nombre_usuario'],
                $activo ? 'habilitada' : 'deshabilitada'
            ),
        ]);
    }

    // =================================================================
    case 'eliminar': {
        Response::exigirMetodo('POST');

        $id = (int) ($cuerpo['id'] ?? 0);

        if ($id <= 0) {
            Response::error('Falta el id del usuario.', 422);
        }
        if ($id === $adminId) {
            Response::error('No puedes eliminar tu propia cuenta.', 409);
        }

        $objetivo = Database::fila(
            'SELECT id, nombre_usuario, rol FROM usuarios WHERE id = :id',
            ['id' => $id]
        );
        if ($objetivo === null) {
            Response::noEncontrado('Ese usuario ya no existe.');
        }

        if ((string) $objetivo['rol'] === 'admin' && $otrosAdmins($id) === 0) {
            Response::error(
                'No se puede eliminar al unico administrador activo del sistema.',
                409
            );
        }

        // Las claves foraneas con ON DELETE CASCADE limpian save_states,
        // sesiones_juego, favoritos y usuario_logros. Los ARCHIVOS en
        // disco no los borra la base de datos: hay que recogerlos antes.
        $saves = Database::filas(
            'SELECT archivo, captura FROM save_states WHERE usuario_id = :id',
            ['id' => $id]
        );

        Database::afectar('DELETE FROM usuarios WHERE id = :id', ['id' => $id]);

        foreach ($saves as $s) {
            Storage::borrarSave((string) $s['archivo']);
            Storage::borrarCaptura($s['captura'] !== null ? (string) $s['captura'] : null);
        }

        Response::ok([
            'mensaje' => sprintf(
                'Cuenta de %s eliminada junto con sus %d partida(s) guardada(s).',
                (string) $objetivo['nombre_usuario'],
                count($saves)
            ),
        ]);
    }

    // =================================================================
    default:
        Response::error(
            'Accion no reconocida. Usa: listar, rol, activo o eliminar.',
            400
        );
}
