<?php
/**
 * RetroCloud · API · /api/saves.php
 * ---------------------------------------------------------------------
 * EL NUCLEO FUNCIONAL DEL PROYECTO: sincronizacion de partidas.
 *
 * Un "save state" es el volcado binario completo de la memoria del
 * emulador en un instante exacto: RAM, registros de la CPU, estado del
 * chip de video y del de sonido. Al restaurarlo, la partida continua
 * en el mismo fotograma en que se guardo.
 *
 * Flujo completo:
 *
 *   NAVEGADOR                           SERVIDOR
 *   ---------                           --------
 *   emulador.saveState()
 *     -> Uint8Array                 (volcado en memoria)
 *   new Blob([datos])
 *   FormData + fetch POST  ------>   guardar: valida, calcula SHA-256,
 *                                    escribe en /storage/saves y hace
 *                                    UPSERT en la tabla save_states
 *
 *   fetch GET descargar    <------   enviarArchivo() con streaming
 *     -> ArrayBuffer
 *   emulador.loadState(bytes)        (partida restaurada)
 *
 * Acciones:
 *   GET   listar       · ranuras del usuario para un juego
 *   GET   mis_partidas · todas las partidas guardadas del usuario
 *   GET   descargar    · binario de una ranura  (dueno unicamente)
 *   GET   captura      · miniatura PNG de una ranura
 *   POST  guardar      · sube/sobreescribe una ranura
 *   POST  eliminar     · borra una ranura y sus archivos
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
$accion = (string) ($_GET['accion'] ?? $cuerpo['accion'] ?? '');

// Todo este endpoint es privado: sin sesion no se pasa.
$usuarioId = Auth::exigirApi();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Csrf::exigir($cuerpo);
}

/** Ranuras validas: 0 = autoguardado, 1..4 = manuales. */
const SLOTS_VALIDOS = [0, 1, 2, 3, 4];

switch ($accion) {

    // =================================================================
    // LISTAR · las ranuras de un juego para el usuario en sesion
    // =================================================================
    case 'listar': {
        Response::exigirMetodo('GET');

        $juegoId = (int) ($_GET['juego_id'] ?? 0);
        if ($juegoId <= 0) {
            Response::error('Falta el parametro juego_id.', 422);
        }

        $filas = Database::filas(
            'SELECT id, slot, nombre, bytes, hash, captura, segundos_juego,
                    creado_en, actualizado_en
               FROM save_states
              WHERE usuario_id = :u AND juego_id = :j
              ORDER BY slot ASC',
            ['u' => $usuarioId, 'j' => $juegoId]
        );

        $porSlot = [];
        foreach ($filas as $f) {
            $slot = (int) $f['slot'];
            $porSlot[$slot] = [
                'id'             => (int) $f['id'],
                'slot'           => $slot,
                'nombre'         => $f['nombre'],
                'bytes'          => (int) $f['bytes'],
                'peso'           => Validator::bytesLegibles((int) $f['bytes']),
                'hash_corto'     => substr((string) $f['hash'], 0, 12),
                'segundos_juego' => (int) $f['segundos_juego'],
                'tiempo'         => Validator::tiempoLegible((int) $f['segundos_juego']),
                'creado_en'      => $f['creado_en'],
                'actualizado_en' => $f['actualizado_en'],
                'url_captura'    => $f['captura'] !== null
                    ? 'api/saves.php?accion=captura&id=' . (int) $f['id']
                    : null,
                'url_descarga'   => 'api/saves.php?accion=descargar&id=' . (int) $f['id'],
            ];
        }

        // Devolvemos las 5 ranuras siempre, vacias o no: asi el cliente
        // dibuja la rejilla sin tener que inventarse los huecos.
        $ranuras = [];
        foreach (SLOTS_VALIDOS as $slot) {
            $ranuras[] = $porSlot[$slot] ?? [
                'slot'   => $slot,
                'vacia'  => true,
                'nombre' => $slot === 0 ? 'Autoguardado' : 'Ranura ' . $slot,
            ];
        }

        Response::ok(['ranuras' => $ranuras, 'juego_id' => $juegoId]);
    }

    // =================================================================
    // MIS PARTIDAS · vista global para el perfil
    // =================================================================
    case 'mis_partidas': {
        Response::exigirMetodo('GET');

        $filas = Database::filas(
            'SELECT s.id, s.slot, s.nombre, s.bytes, s.captura, s.segundos_juego,
                    s.actualizado_en,
                    j.id AS juego_id, j.titulo, j.portada,
                    c.nombre AS consola, c.color_hex
               FROM save_states s
               JOIN juegos   j ON j.id = s.juego_id
               JOIN consolas c ON c.id = j.consola_id
              WHERE s.usuario_id = :u
              ORDER BY s.actualizado_en DESC',
            ['u' => $usuarioId]
        );

        foreach ($filas as &$f) {
            $f['id']           = (int) $f['id'];
            $f['juego_id']     = (int) $f['juego_id'];
            $f['slot']         = (int) $f['slot'];
            $f['peso']         = Validator::bytesLegibles((int) $f['bytes']);
            $f['tiempo']       = Validator::tiempoLegible((int) $f['segundos_juego']);
            $f['url_captura']  = $f['captura'] !== null
                ? 'api/saves.php?accion=captura&id=' . $f['id'] : null;
            $f['url_portada']  = $f['portada'] !== null && $f['portada'] !== ''
                ? 'assets/portadas/' . rawurlencode((string) $f['portada']) : null;
            $f['url_continuar'] = 'jugar.php?id=' . $f['juego_id'] . '&slot=' . $f['slot'];
        }
        unset($f);

        Response::ok(['partidas' => $filas, 'total' => count($filas)]);
    }

    // =================================================================
    // GUARDAR · sube el volcado binario (UPSERT sobre la ranura)
    // =================================================================
    case 'guardar': {
        Response::exigirMetodo('POST');

        $juegoId = (int) ($_POST['juego_id'] ?? 0);
        $slot    = (int) ($_POST['slot'] ?? 1);

        if ($juegoId <= 0) {
            Response::error('Falta el parametro juego_id.', 422);
        }
        if (!in_array($slot, SLOTS_VALIDOS, true)) {
            Response::error('Ranura invalida: usa 0 (autoguardado) o 1 a 4.', 422);
        }

        // El juego debe existir y estar disponible.
        $juego = Database::fila(
            'SELECT id, titulo, publicado FROM juegos WHERE id = :id',
            ['id' => $juegoId]
        );
        if ($juego === null
            || ((int) $juego['publicado'] !== 1 && !Auth::esAdmin())
        ) {
            Response::noEncontrado('Ese juego no existe o no esta disponible.');
        }

        if (!isset($_FILES['estado'])) {
            Response::error('No se recibio el archivo de la partida.', 422);
        }

        // Lo que habia antes en esa ranura (para borrar el archivo viejo
        // solo si el nuevo se escribe bien).
        $anterior = Database::fila(
            'SELECT id, archivo, captura FROM save_states
              WHERE usuario_id = :u AND juego_id = :j AND slot = :s',
            ['u' => $usuarioId, 'j' => $juegoId, 's' => $slot]
        );

        try {
            /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $subida */
            $subida = $_FILES['estado'];
            $save = Storage::guardarSave($subida, $usuarioId, $juegoId, $slot);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }

        // Miniatura opcional: el canvas del emulador en base64.
        $captura = null;
        $dataUrl = (string) ($_POST['captura'] ?? '');
        if ($dataUrl !== '') {
            $captura = Storage::guardarCapturaBase64($dataUrl, $usuarioId, $juegoId, $slot);
        }

        $segundos = max(0, min(999999, (int) ($_POST['segundos'] ?? 0)));
        $nombre   = trim((string) ($_POST['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = $slot === 0 ? 'Autoguardado' : 'Ranura ' . $slot;
        }
        $nombre = mb_substr($nombre, 0, 80);

        // UPSERT: una sola sentencia resuelve insercion y actualizacion
        // apoyandose en la restriccion UNIQUE (usuario, juego, slot).
        Database::ejecutar(
            'INSERT INTO save_states
               (usuario_id, juego_id, slot, nombre, archivo, bytes, hash,
                captura, segundos_juego)
             VALUES
               (:u, :j, :s, :nombre, :archivo, :bytes, :hash, :captura, :seg)
             ON DUPLICATE KEY UPDATE
                nombre         = VALUES(nombre),
                archivo        = VALUES(archivo),
                bytes          = VALUES(bytes),
                hash           = VALUES(hash),
                captura        = COALESCE(VALUES(captura), captura),
                segundos_juego = VALUES(segundos_juego),
                actualizado_en = NOW()',
            [
                'u'       => $usuarioId,
                'j'       => $juegoId,
                's'       => $slot,
                'nombre'  => $nombre,
                'archivo' => $save['nombre'],
                'bytes'   => $save['bytes'],
                'hash'    => $save['hash'],
                'captura' => $captura,
                'seg'     => $segundos,
            ]
        );

        $fila = Database::fila(
            'SELECT id, actualizado_en FROM save_states
              WHERE usuario_id = :u AND juego_id = :j AND slot = :s',
            ['u' => $usuarioId, 'j' => $juegoId, 's' => $slot]
        );

        // El nombre del archivo es determinista (u/j/slot), asi que el
        // anterior ya quedo sobreescrito; solo limpiamos si cambio.
        if ($anterior !== null
            && (string) $anterior['archivo'] !== $save['nombre']
        ) {
            Storage::borrarSave((string) $anterior['archivo']);
        }

        otorgarLogros($usuarioId);

        Response::ok([
            'id'          => (int) ($fila['id'] ?? 0),
            'slot'        => $slot,
            'nombre'      => $nombre,
            'bytes'       => $save['bytes'],
            'peso'        => Validator::bytesLegibles($save['bytes']),
            'hash_corto'  => substr($save['hash'], 0, 12),
            'guardado_en' => $fila['actualizado_en'] ?? date('Y-m-d H:i:s'),
            'url_captura' => $captura !== null
                ? 'api/saves.php?accion=captura&id=' . (int) ($fila['id'] ?? 0)
                : null,
            'mensaje'     => $slot === 0
                ? 'Progreso autoguardado en la nube.'
                : 'Partida guardada en la ranura ' . $slot . '.',
        ], 201);
    }

    // =================================================================
    // DESCARGAR · entrega el binario de la ranura
    // =================================================================
    case 'descargar': {
        Response::exigirMetodo('GET');

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            exit('Falta el parametro id.');
        }

        // La consulta filtra por usuario_id: un id ajeno simplemente no
        // devuelve fila. Ese filtro ES la autorizacion (IDOR cubierto).
        $save = Database::fila(
            'SELECT s.archivo, s.bytes, s.hash, s.slot, j.titulo
               FROM save_states s
               JOIN juegos j ON j.id = s.juego_id
              WHERE s.id = :id AND s.usuario_id = :u',
            ['id' => $id, 'u' => $usuarioId]
        );

        if ($save === null) {
            http_response_code(404);
            exit('Partida no encontrada.');
        }

        $ruta = Storage::rutaSave((string) $save['archivo']);
        if ($ruta === null) {
            http_response_code(404);
            exit('El archivo de la partida no esta en el servidor.');
        }

        $etag = '"' . substr((string) $save['hash'], 0, 32) . '"';
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }

        $nombreVisible = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $save['titulo'])
                       . '_slot' . (int) $save['slot'] . '.state';

        Storage::enviarArchivo(
            $ruta,
            $nombreVisible,
            'application/octet-stream',
            ($_GET['descargar'] ?? '') === '1'
        );
    }

    // =================================================================
    // CAPTURA · miniatura PNG de la ranura
    // =================================================================
    case 'captura': {
        Response::exigirMetodo('GET');

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            exit('Falta el parametro id.');
        }

        $save = Database::fila(
            'SELECT captura FROM save_states WHERE id = :id AND usuario_id = :u',
            ['id' => $id, 'u' => $usuarioId]
        );

        if ($save === null || $save['captura'] === null) {
            http_response_code(404);
            exit('Sin captura.');
        }

        $ruta = Storage::rutaCaptura((string) $save['captura']);
        if ($ruta === null) {
            http_response_code(404);
            exit('Captura no disponible.');
        }

        Storage::enviarArchivo($ruta, 'captura.png', 'image/png', false);
    }

    // =================================================================
    // ELIMINAR
    // =================================================================
    case 'eliminar': {
        Response::exigirMetodo('POST');

        $id = (int) ($cuerpo['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Falta el id de la partida.', 422);
        }

        $save = Database::fila(
            'SELECT archivo, captura, slot FROM save_states
              WHERE id = :id AND usuario_id = :u',
            ['id' => $id, 'u' => $usuarioId]
        );

        if ($save === null) {
            Response::noEncontrado('Esa partida no existe o no es tuya.');
        }

        Database::afectar(
            'DELETE FROM save_states WHERE id = :id AND usuario_id = :u',
            ['id' => $id, 'u' => $usuarioId]
        );

        Storage::borrarSave((string) $save['archivo']);
        Storage::borrarCaptura($save['captura'] !== null ? (string) $save['captura'] : null);

        Response::ok([
            'mensaje' => 'Ranura ' . (int) $save['slot'] . ' liberada.',
            'slot'    => (int) $save['slot'],
        ]);
    }

    // =================================================================
    default:
        Response::error(
            'Accion no reconocida. Usa: listar, mis_partidas, guardar, '
            . 'descargar, captura o eliminar.',
            400
        );
}

/**
 * Concede los logros cuyo umbral ya alcanzo el usuario.
 *
 * INSERT IGNORE se apoya en la clave primaria compuesta
 * (usuario_id, logro_id): si el logro ya estaba, la insercion se
 * descarta en silencio y no hay que consultar antes.
 */
function otorgarLogros(int $usuarioId): void
{
    $metricas = Database::fila(
        'SELECT
            (SELECT COUNT(*) FROM sesiones_juego WHERE usuario_id = :u1) AS partidas,
            (SELECT COALESCE(SUM(segundos_jugados),0) FROM sesiones_juego WHERE usuario_id = :u2) AS tiempo,
            (SELECT COUNT(DISTINCT juego_id) FROM sesiones_juego WHERE usuario_id = :u3) AS juegos,
            (SELECT COUNT(*) FROM save_states WHERE usuario_id = :u4) AS saves',
        ['u1' => $usuarioId, 'u2' => $usuarioId, 'u3' => $usuarioId, 'u4' => $usuarioId]
    ) ?? [];

    foreach (['partidas', 'tiempo', 'juegos', 'saves'] as $tipo) {
        Database::ejecutar(
            'INSERT IGNORE INTO usuario_logros (usuario_id, logro_id)
             SELECT :u, id FROM logros
              WHERE tipo = :tipo AND umbral <= :valor',
            [
                'u'     => $usuarioId,
                'tipo'  => $tipo,
                'valor' => (int) ($metricas[$tipo] ?? 0),
            ]
        );
    }
}
