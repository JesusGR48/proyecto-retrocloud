<?php
/**
 * RetroCloud · API · /api/stats.php
 * ---------------------------------------------------------------------
 * Consultas de solo lectura apoyadas en las VISTAS de MySQL.
 *
 *   GET perfil    · resumen del usuario en sesion + logros + historial
 *   GET ranking   · juegos mas jugados de la plataforma
 *   GET resumen   · cifras globales (publico: alimenta la portada)
 *   GET dashboard · panel de control del administrador
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Database;
use RetroCloud\Response;
use RetroCloud\Validator;

Response::exigirMetodo('GET');

$accion = (string) ($_GET['accion'] ?? 'resumen');

switch ($accion) {

    // =================================================================
    // PERFIL · requiere sesion
    // =================================================================
    case 'perfil': {
        $usuarioId = Auth::exigirApi();

        // vista_perfil concentra los cinco subconsultas de conteo.
        $perfil = Database::fila(
            'SELECT * FROM vista_perfil WHERE id = :id',
            ['id' => $usuarioId]
        );
        if ($perfil === null) {
            Response::noEncontrado('Perfil no encontrado.');
        }

        $perfil['id']               = (int) $perfil['id'];
        $perfil['partidas']         = (int) $perfil['partidas'];
        $perfil['segundos_jugados'] = (int) $perfil['segundos_jugados'];
        $perfil['juegos_distintos'] = (int) $perfil['juegos_distintos'];
        $perfil['partidas_guardadas'] = (int) $perfil['partidas_guardadas'];
        $perfil['favoritos']        = (int) $perfil['favoritos'];
        $perfil['tiempo_total']     = Validator::tiempoLegible((int) $perfil['segundos_jugados']);

        // Ultimos juegos tocados.
        $historial = Database::filas(
            'SELECT j.id, j.titulo, j.portada,
                    c.nombre AS consola, c.color_hex,
                    MAX(s.iniciada_en)                   AS ultima_vez,
                    COUNT(s.id)                          AS veces,
                    COALESCE(SUM(s.segundos_jugados), 0) AS segundos
               FROM sesiones_juego s
               JOIN juegos   j ON j.id = s.juego_id
               JOIN consolas c ON c.id = j.consola_id
              WHERE s.usuario_id = :u
              GROUP BY j.id, j.titulo, j.portada, c.nombre, c.color_hex
              ORDER BY ultima_vez DESC
              LIMIT 12',
            ['u' => $usuarioId]
        );

        foreach ($historial as &$h) {
            $h['id']          = (int) $h['id'];
            $h['veces']       = (int) $h['veces'];
            $h['segundos']    = (int) $h['segundos'];
            $h['tiempo']      = Validator::tiempoLegible((int) $h['segundos']);
            $h['url_portada'] = $h['portada'] !== null && $h['portada'] !== ''
                ? 'assets/portadas/' . rawurlencode((string) $h['portada']) : null;
            $h['url_jugar']   = 'jugar.php?id=' . $h['id'];
        }
        unset($h);

        // Logros: todos los existentes, marcando los conseguidos.
        $logros = Database::filas(
            'SELECT l.codigo, l.nombre, l.descripcion, l.icono, l.tipo, l.umbral,
                    ul.obtenido_en
               FROM logros l
               LEFT JOIN usuario_logros ul
                      ON ul.logro_id = l.id AND ul.usuario_id = :u
              ORDER BY l.tipo ASC, l.umbral ASC',
            ['u' => $usuarioId]
        );

        $progresoActual = [
            'partidas' => $perfil['partidas'],
            'tiempo'   => $perfil['segundos_jugados'],
            'juegos'   => $perfil['juegos_distintos'],
            'saves'    => $perfil['partidas_guardadas'],
        ];

        foreach ($logros as &$l) {
            $l['umbral']    = (int) $l['umbral'];
            $l['obtenido']  = $l['obtenido_en'] !== null;
            $actual         = (int) ($progresoActual[$l['tipo']] ?? 0);
            $l['progreso']  = min(100, (int) round($actual / max(1, $l['umbral']) * 100));
        }
        unset($l);

        Response::ok([
            'perfil'    => $perfil,
            'historial' => $historial,
            'logros'    => $logros,
            'obtenidos' => count(array_filter($logros, static fn($l) => $l['obtenido'])),
        ]);
    }

    // =================================================================
    // RANKING · publico
    // =================================================================
    case 'ranking': {
        $limite = max(1, min(50, (int) ($_GET['limite'] ?? 10)));

        $ranking = Database::filas(
            "SELECT r.id, r.titulo, r.consola, r.jugadores_unicos,
                    r.total_partidas, r.segundos_totales
               FROM vista_ranking r
               JOIN juegos j ON j.id = r.id
              WHERE j.publicado = 1
              ORDER BY r.segundos_totales DESC, r.total_partidas DESC
              LIMIT $limite"
        );

        $pos = 0;
        foreach ($ranking as &$r) {
            $r['posicion']         = ++$pos;
            $r['id']               = (int) $r['id'];
            $r['jugadores_unicos'] = (int) $r['jugadores_unicos'];
            $r['total_partidas']   = (int) $r['total_partidas'];
            $r['segundos_totales'] = (int) $r['segundos_totales'];
            $r['tiempo']           = Validator::tiempoLegible((int) $r['segundos_totales']);
            $r['url_jugar']        = 'jugar.php?id=' . $r['id'];
        }
        unset($r);

        Response::ok(['ranking' => $ranking]);
    }

    // =================================================================
    // RESUMEN · publico, para la portada
    // =================================================================
    case 'resumen': {
        $resumen = Database::fila(
            'SELECT
               (SELECT COUNT(*) FROM juegos WHERE publicado = 1)        AS juegos,
               (SELECT COUNT(*) FROM consolas WHERE activa = 1)         AS consolas,
               (SELECT COUNT(*) FROM usuarios WHERE activo = 1)         AS usuarios,
               (SELECT COUNT(*) FROM save_states)                       AS partidas_guardadas,
               (SELECT COALESCE(SUM(segundos_jugados),0) FROM sesiones_juego) AS segundos_totales'
        ) ?? [];

        Response::ok([
            'juegos'             => (int) ($resumen['juegos'] ?? 0),
            'consolas'           => (int) ($resumen['consolas'] ?? 0),
            'usuarios'           => (int) ($resumen['usuarios'] ?? 0),
            'partidas_guardadas' => (int) ($resumen['partidas_guardadas'] ?? 0),
            'segundos_totales'   => (int) ($resumen['segundos_totales'] ?? 0),
            'tiempo_total'       => Validator::tiempoLegible((int) ($resumen['segundos_totales'] ?? 0)),
        ]);
    }

    // =================================================================
    // DASHBOARD · solo admin
    // =================================================================
    case 'dashboard': {
        Auth::exigirAdminApi();

        $cifras = Database::fila(
            'SELECT
               (SELECT COUNT(*) FROM juegos)                            AS juegos_total,
               (SELECT COUNT(*) FROM juegos WHERE publicado = 1)        AS juegos_publicos,
               (SELECT COUNT(*) FROM usuarios)                          AS usuarios,
               (SELECT COUNT(*) FROM usuarios WHERE rol = "admin")      AS admins,
               (SELECT COUNT(*) FROM save_states)                       AS saves,
               (SELECT COALESCE(SUM(bytes),0) FROM save_states)         AS bytes_saves,
               (SELECT COALESCE(SUM(rom_bytes),0) FROM juegos)          AS bytes_roms,
               (SELECT COUNT(*) FROM sesiones_juego)                    AS sesiones,
               (SELECT COUNT(*) FROM intentos_login
                 WHERE exito = 0 AND creado_en > (NOW() - INTERVAL 1 DAY)) AS fallos_24h'
        ) ?? [];

        // Juegos por consola, para la grafica de barras del panel.
        $porConsola = Database::filas(
            'SELECT c.nombre, c.color_hex, COUNT(j.id) AS total
               FROM consolas c
               LEFT JOIN juegos j ON j.consola_id = c.id
              WHERE c.activa = 1
              GROUP BY c.id, c.nombre, c.color_hex
              ORDER BY total DESC, c.nombre ASC'
        );

        // Actividad de los ultimos 14 dias.
        $actividad = Database::filas(
            'SELECT DATE(iniciada_en) AS dia, COUNT(*) AS partidas
               FROM sesiones_juego
              WHERE iniciada_en > (NOW() - INTERVAL 14 DAY)
              GROUP BY DATE(iniciada_en)
              ORDER BY dia ASC'
        );

        $ultimosUsuarios = Database::filas(
            'SELECT id, nombre_usuario, correo, rol, creado_en, ultimo_acceso
               FROM usuarios
              ORDER BY creado_en DESC
              LIMIT 8'
        );

        foreach ($porConsola as &$c) {
            $c['total'] = (int) $c['total'];
        }
        unset($c);

        Response::ok([
            'cifras' => [
                'juegos_total'    => (int) ($cifras['juegos_total'] ?? 0),
                'juegos_publicos' => (int) ($cifras['juegos_publicos'] ?? 0),
                'usuarios'        => (int) ($cifras['usuarios'] ?? 0),
                'admins'          => (int) ($cifras['admins'] ?? 0),
                'saves'           => (int) ($cifras['saves'] ?? 0),
                'sesiones'        => (int) ($cifras['sesiones'] ?? 0),
                'fallos_24h'      => (int) ($cifras['fallos_24h'] ?? 0),
                'peso_roms'       => Validator::bytesLegibles((int) ($cifras['bytes_roms'] ?? 0)),
                'peso_saves'      => Validator::bytesLegibles((int) ($cifras['bytes_saves'] ?? 0)),
            ],
            'por_consola'      => $porConsola,
            'actividad'        => $actividad,
            'ultimos_usuarios' => $ultimosUsuarios,
        ]);
    }

    // =================================================================
    default:
        Response::error(
            'Accion no reconocida. Usa: perfil, ranking, resumen o dashboard.',
            400
        );
}
