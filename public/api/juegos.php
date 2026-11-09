<?php
/**
 * RetroCloud · API · /api/juegos.php
 * ---------------------------------------------------------------------
 * Catalogo (lectura publica) y CRUD completo (solo administradores).
 *
 *   GET   listar      · catalogo con filtros, busqueda y paginacion
 *   GET   detalle     · un juego por id o slug
 *   GET   consolas    · plataformas activas
 *   GET   generos     · generos disponibles
 *   POST  crear       · alta de juego + subida de ROM      (admin)
 *   POST  actualizar  · edicion, con reemplazo opcional     (admin)
 *   POST  eliminar    · baja del juego y de sus archivos    (admin)
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

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Csrf::exigir($cuerpo);
}

switch ($accion) {

    // =================================================================
    // LISTAR · catalogo paginado
    // =================================================================
    case 'listar': {
        Response::exigirMetodo('GET');

        $consola  = trim((string) ($_GET['consola'] ?? ''));
        $genero   = trim((string) ($_GET['genero'] ?? ''));
        $busqueda = trim((string) ($_GET['q'] ?? ''));
        $pagina   = max(1, (int) ($_GET['pagina'] ?? 1));
        $porPagina = (int) ($_GET['por_pagina'] ?? 24);
        $porPagina = max(1, min(60, $porPagina));   // techo: evita volcar la tabla
        $orden     = (string) ($_GET['orden'] ?? 'recientes');

        // Los administradores pueden ver tambien los no publicados.
        $verOcultos = Auth::esAdmin() && ($_GET['incluir_ocultos'] ?? '') === '1';

        $where  = [];
        $params = [];

        if (!$verOcultos) {
            $where[] = 'publicado = 1';
        }
        if ($consola !== '') {
            $where[] = 'consola_slug = :consola';
            $params['consola'] = $consola;
        }
        if ($genero !== '') {
            $where[] = 'genero = :genero';
            $params['genero'] = $genero;
        }
        if ($busqueda !== '') {
            // LIKE con parametro preparado: el comodin se concatena al
            // VALOR, nunca al SQL, asi que no hay inyeccion posible.
            //
            // Un marcador distinto por columna: las sentencias preparadas
            // reales de MySQL son posicionales y PDO rechaza repetir el
            // mismo nombre dentro de una consulta.
            $where[] = '(titulo LIKE :q_titulo'
                     . ' OR desarrollador LIKE :q_dev'
                     . ' OR descripcion LIKE :q_desc)';
            $patron = '%' . $busqueda . '%';
            $params['q_titulo'] = $patron;
            $params['q_dev']    = $patron;
            $params['q_desc']   = $patron;
        }

        $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        // Lista blanca de ordenamientos: el usuario elige una CLAVE, no
        // escribe SQL. Interpolar $_GET en un ORDER BY seria inyectable.
        $ordenes = [
            'recientes' => 'creado_en DESC, id DESC',
            'antiguos'  => 'creado_en ASC, id ASC',
            'titulo'    => 'titulo ASC',
            'populares' => 'veces_jugado DESC, titulo ASC',
            'anio'      => 'anio DESC, titulo ASC',
        ];
        $orderBy = $ordenes[$orden] ?? $ordenes['recientes'];

        $total = (int) Database::valor(
            "SELECT COUNT(*) FROM vista_catalogo$sqlWhere",
            $params
        );

        $offset = ($pagina - 1) * $porPagina;

        // LIMIT/OFFSET van interpolados porque ya son enteros validados
        // con (int); MySQL no acepta parametros preparados ahi cuando
        // EMULATE_PREPARES esta desactivado.
        $juegos = Database::filas(
            "SELECT id, titulo, slug, descripcion, desarrollador, anio, portada,
                    jugadores, licencia, publicado, veces_jugado, rom_bytes,
                    consola, consola_slug, core_emulador, color_hex, genero
               FROM vista_catalogo
               $sqlWhere
              ORDER BY $orderBy
              LIMIT $porPagina OFFSET $offset",
            $params
        );

        $usuarioId = Auth::id();
        $favoritos = [];
        if ($usuarioId !== null && $juegos !== []) {
            $ids = array_map(static fn(array $j): int => (int) $j['id'], $juegos);
            // Marcadores dinamicos pero en numero controlado: uno por id.
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $favoritos = Database::ejecutar(
                "SELECT juego_id FROM favoritos
                  WHERE usuario_id = ? AND juego_id IN ($marcas)",
                array_merge([$usuarioId], $ids)
            )->fetchAll(PDO::FETCH_COLUMN);
            $favoritos = array_map('intval', $favoritos);
        }

        foreach ($juegos as &$j) {
            $j['id']           = (int) $j['id'];
            $j['anio']         = $j['anio'] !== null ? (int) $j['anio'] : null;
            $j['jugadores']    = (int) $j['jugadores'];
            $j['publicado']    = (bool) $j['publicado'];
            $j['veces_jugado'] = (int) $j['veces_jugado'];
            $j['peso']         = Validator::bytesLegibles((int) $j['rom_bytes']);
            $j['favorito']     = in_array($j['id'], $favoritos, true);
            $j['url_portada']  = $j['portada'] !== null && $j['portada'] !== ''
                ? 'assets/portadas/' . rawurlencode((string) $j['portada'])
                : null;
            $j['url_jugar']    = 'jugar.php?id=' . $j['id'];
        }
        unset($j);

        Response::ok([
            'juegos' => $juegos,
            'paginacion' => [
                'pagina'      => $pagina,
                'por_pagina'  => $porPagina,
                'total'       => $total,
                'paginas'     => (int) ceil($total / $porPagina),
                'hay_mas'     => ($offset + count($juegos)) < $total,
            ],
            'filtros' => [
                'consola' => $consola !== '' ? $consola : null,
                'genero'  => $genero !== '' ? $genero : null,
                'q'       => $busqueda !== '' ? $busqueda : null,
                'orden'   => $orden,
            ],
        ]);
    }

    // =================================================================
    // DETALLE
    // =================================================================
    case 'detalle': {
        Response::exigirMetodo('GET');

        $id   = (int) ($_GET['id'] ?? 0);
        $slug = trim((string) ($_GET['slug'] ?? ''));

        if ($id <= 0 && $slug === '') {
            Response::error('Indica el id o el slug del juego.', 422);
        }

        $juego = $id > 0
            ? Database::fila('SELECT * FROM vista_catalogo WHERE id = :id', ['id' => $id])
            : Database::fila('SELECT * FROM vista_catalogo WHERE slug = :s', ['s' => $slug]);

        if ($juego === null) {
            Response::noEncontrado('Ese juego no existe en el catalogo.');
        }
        if (!(bool) $juego['publicado'] && !Auth::esAdmin()) {
            Response::noEncontrado('Ese juego no esta disponible.');
        }

        $juego['id']          = (int) $juego['id'];
        $juego['publicado']   = (bool) $juego['publicado'];
        $juego['peso']        = Validator::bytesLegibles((int) $juego['rom_bytes']);
        $juego['url_rom']     = 'api/rom.php?id=' . $juego['id'];
        $juego['url_portada'] = $juego['portada'] !== null && $juego['portada'] !== ''
            ? 'assets/portadas/' . rawurlencode((string) $juego['portada'])
            : null;

        $usuarioId = Auth::id();
        $juego['favorito'] = false;
        if ($usuarioId !== null) {
            $juego['favorito'] = Database::valor(
                'SELECT 1 FROM favoritos WHERE usuario_id = :u AND juego_id = :j',
                ['u' => $usuarioId, 'j' => $juego['id']]
            ) !== null;
        }

        Response::ok(['juego' => $juego]);
    }

    // =================================================================
    // CONSOLAS · GENEROS
    // =================================================================
    case 'consolas': {
        Response::exigirMetodo('GET');

        $consolas = Database::filas(
            'SELECT c.id, c.nombre, c.slug, c.core_emulador, c.fabricante,
                    c.anio_lanzamiento, c.extensiones, c.bits, c.color_hex,
                    COUNT(j.id) AS total_juegos
               FROM consolas c
               LEFT JOIN juegos j ON j.consola_id = c.id AND j.publicado = 1
              WHERE c.activa = 1
              GROUP BY c.id
              ORDER BY c.orden ASC, c.nombre ASC'
        );

        foreach ($consolas as &$c) {
            $c['id']           = (int) $c['id'];
            $c['total_juegos'] = (int) $c['total_juegos'];
            $c['extensiones']  = array_values(array_filter(
                array_map('trim', explode(',', (string) $c['extensiones']))
            ));
        }
        unset($c);

        Response::ok(['consolas' => $consolas]);
    }

    case 'generos': {
        Response::exigirMetodo('GET');
        Response::ok(['generos' => Database::filas(
            'SELECT id, nombre, slug FROM generos ORDER BY nombre ASC'
        )]);
    }

    // =================================================================
    // CREAR  (admin)
    // =================================================================
    case 'crear': {
        Response::exigirMetodo('POST');
        $adminId = Auth::exigirAdminApi();

        $v = Validator::de($_POST);
        $v->requerido('titulo', 'titulo')->longitud('titulo', 'El titulo', 2, 150)
          ->requerido('consola_id', 'consola')->entero('consola_id', 'La consola', 1)
          ->oResponder();

        $consola = Database::fila(
            'SELECT id, extensiones FROM consolas WHERE id = :id AND activa = 1',
            ['id' => (int) $_POST['consola_id']]
        );
        if ($consola === null) {
            Response::error('La consola seleccionada no existe o esta inactiva.', 422, [
                'consola_id' => 'Consola invalida.',
            ]);
        }

        if (!isset($_FILES['rom'])) {
            Response::error('Debes adjuntar el archivo de la ROM.', 422, [
                'rom' => 'Archivo obligatorio.',
            ]);
        }

        $extensiones = explode(',', (string) $consola['extensiones']);

        try {
            /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $subida */
            $subida = $_FILES['rom'];
            $rom = Storage::guardarRom($subida, $extensiones);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 422, ['rom' => $e->getMessage()]);
        }

        // Rechazo de duplicados por firma, no por nombre de archivo.
        $yaExiste = Database::fila(
            'SELECT id, titulo FROM juegos WHERE rom_hash = :h',
            ['h' => $rom['hash']]
        );
        if ($yaExiste !== null) {
            Storage::borrarRom($rom['nombre']);
            Response::error(
                'Esa ROM ya esta en el catalogo como "' . $yaExiste['titulo'] . '".',
                409,
                ['rom' => 'ROM duplicada.']
            );
        }

        $portada = null;
        if (isset($_FILES['portada']) && ($_FILES['portada']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
            try {
                /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $sp */
                $sp = $_FILES['portada'];
                $portada = Storage::guardarPortada($sp);
            } catch (RuntimeException $e) {
                Storage::borrarRom($rom['nombre']);
                Response::error($e->getMessage(), 422, ['portada' => $e->getMessage()]);
            }
        }

        $titulo = (string) $v->crudo('titulo');
        $slug   = Validator::slug($titulo);

        // Garantiza unicidad del slug.
        $base = $slug;
        $n    = 2;
        while (Database::valor('SELECT 1 FROM juegos WHERE slug = :s', ['s' => $slug]) !== null) {
            $slug = $base . '-' . $n++;
        }

        $generoId = ($_POST['genero_id'] ?? '') !== '' ? (int) $_POST['genero_id'] : null;
        if ($generoId !== null
            && Database::valor('SELECT 1 FROM generos WHERE id = :g', ['g' => $generoId]) === null
        ) {
            $generoId = null;
        }

        try {
            $id = Database::insertar(
                'INSERT INTO juegos
                   (consola_id, genero_id, titulo, slug, descripcion, desarrollador,
                    anio, archivo_rom, archivo_original, rom_hash, rom_bytes,
                    portada, jugadores, licencia, publicado, creado_por)
                 VALUES
                   (:consola, :genero, :titulo, :slug, :desc, :dev,
                    :anio, :rom, :original, :hash, :bytes,
                    :portada, :jugadores, :licencia, :publicado, :autor)',
                [
                    'consola'   => (int) $consola['id'],
                    'genero'    => $generoId,
                    'titulo'    => $titulo,
                    'slug'      => $slug,
                    'desc'      => $v->crudo('descripcion'),
                    'dev'       => $v->crudo('desarrollador'),
                    'anio'      => ($_POST['anio'] ?? '') !== '' ? (int) $_POST['anio'] : null,
                    'rom'       => $rom['nombre'],
                    'original'  => $rom['original'],
                    'hash'      => $rom['hash'],
                    'bytes'     => $rom['bytes'],
                    'portada'   => $portada,
                    'jugadores' => max(1, min(8, (int) ($_POST['jugadores'] ?? 1))),
                    'licencia'  => $v->crudo('licencia') ?? 'No especificada',
                    'publicado' => isset($_POST['publicado']) && $_POST['publicado'] !== '0' ? 1 : 0,
                    'autor'     => $adminId,
                ]
            );
        } catch (\Throwable $e) {
            // Si la BD rechaza el registro, no dejamos el archivo huerfano.
            Storage::borrarRom($rom['nombre']);
            Storage::borrarPortada($portada);
            throw $e;
        }

        Response::ok([
            'id'      => $id,
            'slug'    => $slug,
            'hash'    => $rom['hash'],
            'peso'    => Validator::bytesLegibles($rom['bytes']),
            'mensaje' => 'Juego "' . $titulo . '" agregado al catalogo.',
        ], 201);
    }

    // =================================================================
    // ACTUALIZAR  (admin)
    // =================================================================
    case 'actualizar': {
        Response::exigirMetodo('POST');
        Auth::exigirAdminApi();

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Falta el id del juego.', 422);
        }

        $actual = Database::fila('SELECT * FROM juegos WHERE id = :id', ['id' => $id]);
        if ($actual === null) {
            Response::noEncontrado('Ese juego no existe.');
        }

        $v = Validator::de($_POST);
        $v->requerido('titulo', 'titulo')->longitud('titulo', 'El titulo', 2, 150)
          ->requerido('consola_id', 'consola')->entero('consola_id', 'La consola', 1)
          ->oResponder();

        $consola = Database::fila(
            'SELECT id, extensiones FROM consolas WHERE id = :id',
            ['id' => (int) $_POST['consola_id']]
        );
        if ($consola === null) {
            Response::error('Consola invalida.', 422, ['consola_id' => 'Consola invalida.']);
        }

        // Reemplazo opcional de la ROM.
        $romNueva = null;
        if (isset($_FILES['rom']) && ($_FILES['rom']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
            try {
                /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $sr */
                $sr = $_FILES['rom'];
                $romNueva = Storage::guardarRom($sr, explode(',', (string) $consola['extensiones']));
            } catch (RuntimeException $e) {
                Response::error($e->getMessage(), 422, ['rom' => $e->getMessage()]);
            }

            $dup = Database::fila(
                'SELECT id, titulo FROM juegos WHERE rom_hash = :h AND id <> :id',
                ['h' => $romNueva['hash'], 'id' => $id]
            );
            if ($dup !== null) {
                Storage::borrarRom($romNueva['nombre']);
                Response::error(
                    'Esa ROM ya pertenece a "' . $dup['titulo'] . '".',
                    409,
                    ['rom' => 'ROM duplicada.']
                );
            }
        }

        // Reemplazo opcional de la portada.
        $portadaNueva = null;
        if (isset($_FILES['portada']) && ($_FILES['portada']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
            try {
                /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $sp */
                $sp = $_FILES['portada'];
                $portadaNueva = Storage::guardarPortada($sp);
            } catch (RuntimeException $e) {
                if ($romNueva !== null) {
                    Storage::borrarRom($romNueva['nombre']);
                }
                Response::error($e->getMessage(), 422, ['portada' => $e->getMessage()]);
            }
        }

        $generoId = ($_POST['genero_id'] ?? '') !== '' ? (int) $_POST['genero_id'] : null;
        if ($generoId !== null
            && Database::valor('SELECT 1 FROM generos WHERE id = :g', ['g' => $generoId]) === null
        ) {
            $generoId = null;
        }

        Database::afectar(
            'UPDATE juegos SET
                consola_id    = :consola,
                genero_id     = :genero,
                titulo        = :titulo,
                descripcion   = :desc,
                desarrollador = :dev,
                anio          = :anio,
                jugadores     = :jugadores,
                licencia      = :licencia,
                publicado     = :publicado,
                archivo_rom      = COALESCE(:rom, archivo_rom),
                archivo_original = COALESCE(:original, archivo_original),
                rom_hash         = COALESCE(:hash, rom_hash),
                rom_bytes        = COALESCE(:bytes, rom_bytes),
                portada          = COALESCE(:portada, portada)
              WHERE id = :id',
            [
                'consola'   => (int) $consola['id'],
                'genero'    => $generoId,
                'titulo'    => (string) $v->crudo('titulo'),
                'desc'      => $v->crudo('descripcion'),
                'dev'       => $v->crudo('desarrollador'),
                'anio'      => ($_POST['anio'] ?? '') !== '' ? (int) $_POST['anio'] : null,
                'jugadores' => max(1, min(8, (int) ($_POST['jugadores'] ?? 1))),
                'licencia'  => $v->crudo('licencia') ?? 'No especificada',
                'publicado' => isset($_POST['publicado']) && $_POST['publicado'] !== '0' ? 1 : 0,
                'rom'       => $romNueva['nombre'] ?? null,
                'original'  => $romNueva['original'] ?? null,
                'hash'      => $romNueva['hash'] ?? null,
                'bytes'     => $romNueva['bytes'] ?? null,
                'portada'   => $portadaNueva,
                'id'        => $id,
            ]
        );

        // Los archivos viejos se borran solo DESPUES de que el UPDATE
        // haya pasado: si fallara, la fila seguiria apuntando a un
        // archivo que si existe.
        if ($romNueva !== null) {
            Storage::borrarRom((string) $actual['archivo_rom']);
        }
        if ($portadaNueva !== null && $actual['portada'] !== null) {
            Storage::borrarPortada((string) $actual['portada']);
        }

        Response::ok(['mensaje' => 'Juego actualizado.', 'id' => $id]);
    }

    // =================================================================
    // ELIMINAR  (admin)
    // =================================================================
    case 'eliminar': {
        Response::exigirMetodo('POST');
        Auth::exigirAdminApi();

        $id = (int) ($cuerpo['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Falta el id del juego.', 422);
        }

        $juego = Database::fila(
            'SELECT titulo, archivo_rom, portada FROM juegos WHERE id = :id',
            ['id' => $id]
        );
        if ($juego === null) {
            Response::noEncontrado('Ese juego ya no existe.');
        }

        // Las partidas guardadas apuntan a archivos propios: los
        // recogemos antes de que ON DELETE CASCADE borre las filas.
        $saves = Database::filas(
            'SELECT archivo, captura FROM save_states WHERE juego_id = :id',
            ['id' => $id]
        );

        Database::afectar('DELETE FROM juegos WHERE id = :id', ['id' => $id]);

        Storage::borrarRom((string) $juego['archivo_rom']);
        Storage::borrarPortada($juego['portada'] !== null ? (string) $juego['portada'] : null);
        foreach ($saves as $s) {
            Storage::borrarSave((string) $s['archivo']);
            Storage::borrarCaptura($s['captura'] !== null ? (string) $s['captura'] : null);
        }

        Response::ok([
            'mensaje' => 'Juego "' . $juego['titulo'] . '" eliminado junto con sus archivos.',
        ]);
    }

    // =================================================================
    default:
        Response::error('Accion no reconocida.', 400);
}
