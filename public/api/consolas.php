<?php
/**
 * RetroCloud · API · /api/consolas.php
 * ---------------------------------------------------------------------
 * Gestion de plataformas y de su firmware de sistema.
 *
 *   GET  listar       · consolas con el estado de su BIOS   (admin)
 *   POST subir_bios   · carga o reemplaza el BIOS            (admin)
 *   POST borrar_bios  · elimina el BIOS de una consola       (admin)
 *   POST exigir_bios  · marca si la consola lo necesita      (admin)
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

Auth::exigirAdminApi();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Csrf::exigir($cuerpo);
}

switch ($accion) {

    // =================================================================
    case 'listar': {
        Response::exigirMetodo('GET');

        $consolas = Database::filas(
            'SELECT c.id, c.nombre, c.slug, c.core_emulador, c.fabricante,
                    c.extensiones, c.color_hex, c.activa,
                    c.bios_requerido, c.archivo_bios, c.bios_hash,
                    c.bios_bytes, c.bios_nombre_original,
                    COUNT(j.id) AS total_juegos
               FROM consolas c
               LEFT JOIN juegos j ON j.consola_id = c.id
              GROUP BY c.id
              ORDER BY c.orden ASC, c.nombre ASC'
        );

        foreach ($consolas as &$c) {
            $c['id']             = (int) $c['id'];
            $c['activa']         = (bool) $c['activa'];
            $c['total_juegos']   = (int) $c['total_juegos'];
            $c['bios_requerido'] = (bool) $c['bios_requerido'];
            $c['tiene_bios']     = $c['archivo_bios'] !== null;
            $c['bios_peso']      = $c['tiene_bios']
                ? Validator::bytesLegibles((int) $c['bios_bytes']) : null;
            $c['bios_hash_corto'] = $c['bios_hash'] !== null
                ? substr((string) $c['bios_hash'], 0, 12) : null;

            // Senal para el panel: exige BIOS y no lo tiene cargado.
            $c['falta_bios'] = $c['bios_requerido'] && !$c['tiene_bios'];

            unset($c['archivo_bios'], $c['bios_hash']);
        }
        unset($c);

        Response::ok([
            'consolas'     => $consolas,
            'sin_bios'     => count(array_filter($consolas, static fn($c) => $c['falta_bios'])),
            'limite_bios'  => Validator::bytesLegibles(MAX_BYTES_BIOS),
        ]);
    }

    // =================================================================
    case 'subir_bios': {
        Response::exigirMetodo('POST');

        $id = (int) ($_POST['consola_id'] ?? 0);
        if ($id <= 0) {
            Response::error('Falta el id de la consola.', 422);
        }

        $consola = Database::fila(
            'SELECT id, nombre, archivo_bios FROM consolas WHERE id = :id',
            ['id' => $id]
        );
        if ($consola === null) {
            Response::noEncontrado('Esa consola no existe.');
        }

        if (!isset($_FILES['bios'])) {
            Response::error('Adjunta el archivo de BIOS.', 422, [
                'bios' => 'Archivo obligatorio.',
            ]);
        }

        try {
            /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $subida */
            $subida = $_FILES['bios'];
            $bios = Storage::guardarBios($subida);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 422, ['bios' => $e->getMessage()]);
        }

        $anterior = $consola['archivo_bios'] !== null
            ? (string) $consola['archivo_bios'] : null;

        Database::afectar(
            'UPDATE consolas
                SET archivo_bios         = :archivo,
                    bios_hash            = :hash,
                    bios_bytes           = :bytes,
                    bios_nombre_original = :original
              WHERE id = :id',
            [
                'archivo'  => $bios['nombre'],
                'hash'     => $bios['hash'],
                'bytes'    => $bios['bytes'],
                'original' => $bios['original'],
                'id'       => $id,
            ]
        );

        // El anterior se borra solo despues de que el UPDATE haya pasado.
        if ($anterior !== null && $anterior !== $bios['nombre']) {
            Storage::borrarBios($anterior);
        }

        Response::ok([
            'consola_id' => $id,
            'nombre'     => $bios['original'],
            'peso'       => Validator::bytesLegibles($bios['bytes']),
            'hash_corto' => substr($bios['hash'], 0, 12),
            'mensaje'    => 'BIOS cargado para ' . (string) $consola['nombre'] . '.',
        ], 201);
    }

    // =================================================================
    case 'borrar_bios': {
        Response::exigirMetodo('POST');

        $id = (int) ($cuerpo['consola_id'] ?? $_POST['consola_id'] ?? 0);
        if ($id <= 0) {
            Response::error('Falta el id de la consola.', 422);
        }

        $consola = Database::fila(
            'SELECT id, nombre, archivo_bios FROM consolas WHERE id = :id',
            ['id' => $id]
        );
        if ($consola === null) {
            Response::noEncontrado('Esa consola no existe.');
        }
        if ($consola['archivo_bios'] === null) {
            Response::error('Esa consola no tiene BIOS cargado.', 409);
        }

        Database::afectar(
            'UPDATE consolas
                SET archivo_bios = NULL, bios_hash = NULL,
                    bios_bytes = 0, bios_nombre_original = NULL
              WHERE id = :id',
            ['id' => $id]
        );

        Storage::borrarBios((string) $consola['archivo_bios']);

        Response::ok([
            'mensaje' => 'BIOS de ' . (string) $consola['nombre'] . ' eliminado.',
        ]);
    }

    // =================================================================
    case 'exigir_bios': {
        Response::exigirMetodo('POST');

        $id       = (int) ($cuerpo['consola_id'] ?? 0);
        $requerido = !empty($cuerpo['requerido']);

        if ($id <= 0) {
            Response::error('Falta el id de la consola.', 422);
        }

        $afectadas = Database::afectar(
            'UPDATE consolas SET bios_requerido = :r WHERE id = :id',
            ['r' => $requerido ? 1 : 0, 'id' => $id]
        );

        if ($afectadas === 0
            && Database::valor('SELECT 1 FROM consolas WHERE id = :id', ['id' => $id]) === null
        ) {
            Response::noEncontrado('Esa consola no existe.');
        }

        Response::ok([
            'consola_id' => $id,
            'requerido'  => $requerido,
            'mensaje'    => $requerido
                ? 'La consola ahora exige BIOS para jugar.'
                : 'La consola ya no exige BIOS.',
        ]);
    }

    // =================================================================
    default:
        Response::error(
            'Accion no reconocida. Usa: listar, subir_bios, borrar_bios o exigir_bios.',
            400
        );
}
