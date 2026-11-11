<?php
/**
 * RetroCloud · Arranque de la aplicacion
 * ---------------------------------------------------------------------
 * Todo punto de entrada (paginas publicas, panel admin y endpoints de
 * la API) hace un unico `require` de este archivo. Aqui se concentran:
 *
 *   1. Carga de configuracion
 *   2. Autocargador de clases (PSR-4 simplificado)
 *   3. Manejo de errores y excepciones
 *   4. Cabeceras de seguridad HTTP
 *   5. Redireccion a HTTPS (opcional)
 *   6. Arranque de la sesion endurecida
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_ZONA_HORARIA);

// ---------------------------------------------------------------------
// 1b. HTTPS detras de un proxy inverso
//
//     En un hosting compartido (AlwaysData, Cloudflare, cualquier CDN)
//     el TLS lo termina un proxy delante de PHP. El navegador habla por
//     HTTPS, pero la peticion llega a PHP por HTTP interno, asi que
//     $_SERVER['HTTPS'] viene vacio y el proxy nos avisa del esquema
//     real en la cabecera X-Forwarded-Proto.
//
//     Sin esta normalizacion pasaban tres cosas, todas malas:
//       · FORZAR_HTTPS entraba en un BUCLE INFINITO de 301 (PHP creia
//         que seguia en HTTP y redirigia a HTTPS una y otra vez).
//       · La cabecera HSTS no se emitia nunca.
//       · La cookie de sesion no se marcaba Secure.
//
//     Solo se confia en la cabecera cuando CONFIAR_PROXY esta activo,
//     porque un cliente puede falsificarla si nadie la filtra antes.
//     En local se deja en false.
// ---------------------------------------------------------------------
if (defined('CONFIAR_PROXY') && CONFIAR_PROXY
    && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
) {
    $_SERVER['HTTPS'] = 'on';
}
mb_internal_encoding('UTF-8');

// ---------------------------------------------------------------------
// 1. Autocargador: RetroCloud\Database  ->  src/Database.php
// ---------------------------------------------------------------------
spl_autoload_register(static function (string $clase): void {
    $prefijo = 'RetroCloud\\';
    if (!str_starts_with($clase, $prefijo)) {
        return;
    }
    $relativa = substr($clase, strlen($prefijo));
    $archivo  = __DIR__ . DIRECTORY_SEPARATOR
              . str_replace('\\', DIRECTORY_SEPARATOR, $relativa) . '.php';
    if (is_file($archivo)) {
        require_once $archivo;
    }
});

/**
 * Devuelve la ruta de un archivo estatico con su version incorporada.
 *
 *     recurso('assets/css/estilos.css')
 *     -> 'assets/css/estilos.css?v=1a2b3c4d'
 *
 * POR QUE EXISTE
 * --------------
 * public/.htaccess le pide al navegador que guarde el CSS y el
 * JavaScript durante un mes. Eso es lo correcto para el rendimiento,
 * pero tiene un efecto secundario incomodo: cuando se corrige algo, el
 * navegador sigue usando la copia vieja y el arreglo no llega. En un
 * telefono es aun peor, porque no hay forma comoda de forzar la
 * recarga.
 *
 * La solucion estandar es el "cache busting": se anade a la URL un
 * parametro derivado de la fecha de modificacion del archivo. Mientras
 * el archivo no cambie, la URL es identica y el navegador reutiliza su
 * copia; en cuanto se edita, la URL cambia y la descarga de nuevo. Lo
 * mejor de los dos mundos, y sin tener que acordarse de nada.
 *
 * @param string $relativa ruta relativa a public/, p. ej. 'assets/js/app.js'
 */
function recurso(string $relativa): string
{
    static $cache = [];

    $base = $GLOBALS['base'] ?? '';

    if (isset($cache[$relativa])) {
        return $base . $cache[$relativa];
    }

    $absoluta = RUTA_PUBLICA . DIRECTORY_SEPARATOR
              . str_replace('/', DIRECTORY_SEPARATOR, $relativa);

    $version = is_file($absoluta)
        ? dechex((int) filemtime($absoluta))
        // Si el archivo no existe (ruta mal escrita), se usa la version
        // de la app para no romper la pagina en silencio.
        : APP_VERSION;

    $cache[$relativa] = $relativa . '?v=' . $version;

    return $base . $cache[$relativa];
}

// ---------------------------------------------------------------------
// 2. Errores: en desarrollo a pantalla, en produccion a logs/
// ---------------------------------------------------------------------
if (!is_dir(RUTA_LOGS)) {
    @mkdir(RUTA_LOGS, 0775, true);
}

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', RUTA_LOGS . DIRECTORY_SEPARATOR . 'php-error.log');
ini_set('display_errors', APP_ENTORNO === 'desarrollo' ? '1' : '0');

/** Convierte cualquier warning/notice en excepcion: nada pasa inadvertido. */
set_error_handler(static function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
    if (!(error_reporting() & $nivel)) {
        return false;
    }
    throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
});

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        "[%s] %s en %s:%d\n%s",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    $esApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');

    if (!headers_sent()) {
        http_response_code(500);
    }

    if ($esApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => false,
            'error'   => 'Error interno del servidor',
            'detalle' => APP_ENTORNO === 'desarrollo' ? $e->getMessage() : null,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (APP_ENTORNO === 'desarrollo') {
        echo '<pre style="padding:1rem;font:14px/1.5 monospace;background:#1a1a2e;color:#ff6b6b">';
        echo htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8');
        echo '</pre>';
    } else {
        echo '<h1>500 · Error interno</h1><p>Algo salio mal. Intenta mas tarde.</p>';
    }
});

// ---------------------------------------------------------------------
// 3. Cabeceras de seguridad
//    (se explican una por una en el documento tecnico, seccion Seguridad)
// ---------------------------------------------------------------------
if (!headers_sent()) {
    // Impide que el navegador "adivine" el tipo MIME de una respuesta.
    header('X-Content-Type-Options: nosniff');
    // Bloquea el sitio dentro de un <iframe> ajeno (anti clickjacking).
    header('X-Frame-Options: SAMEORIGIN');
    // No filtra la URL completa como referente hacia sitios externos.
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Desactiva APIs del navegador que la app no necesita.
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    /*
     * Content-Security-Policy: lista blanca de origenes ejecutables.
     *
     * Dos permisos merecen explicacion:
     *
     *  · 'wasm-unsafe-eval' es imprescindible: los nucleos del emulador
     *    son modulos WebAssembly y sin ese permiso el navegador se
     *    niega a compilarlos.
     *
     *  · EMULADOR_ORIGEN autoriza el CDN de EmulatorJS. Solo se anade
     *    cuando el emulador NO esta instalado en local; si se copia a
     *    public/emulatorjs/, la constante queda vacia y la politica se
     *    cierra automaticamente a 'self'.
     */
    $ejs = EMULADOR_ORIGEN !== '' ? ' ' . EMULADOR_ORIGEN : '';

    /*
     * 'unsafe-eval' SOLO en la pagina del reproductor.
     *
     * Los nucleos de libretro se compilan con Emscripten, cuyo codigo
     * de enlace construye funciones a partir de cadenas. Sin
     * 'unsafe-eval' el navegador lanza EvalError y el emulador no
     * arranca. No hay forma de concederlo por origen: la directiva es
     * global a la pagina.
     *
     * La mitigacion es acotarlo: jugar.php define PAGINA_EMULADOR antes
     * de cargar este archivo, de modo que el permiso existe unicamente
     * donde corre el emulador. El catalogo, el login, el perfil y todo
     * el panel de administracion -donde se manejan formularios y datos
     * sensibles- conservan la politica estricta.
     */
    $permiteEval = (defined('PAGINA_EMULADOR') && PAGINA_EMULADOR)
        ? " 'unsafe-eval'"
        : '';

    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'$permiteEval blob:$ejs; "
        . "worker-src 'self' blob:$ejs; "
        . "child-src 'self' blob:$ejs; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com$ejs; "
        . "font-src 'self' https://fonts.gstatic.com data:$ejs; "
        . "img-src 'self' data: blob:$ejs; "
        . "media-src 'self' blob: data:$ejs; "
        . "connect-src 'self' blob: data:$ejs; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'"
    );

    // HSTS: solo tiene sentido servido por HTTPS.
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ---------------------------------------------------------------------
// 4. Redireccion forzada a HTTPS
// ---------------------------------------------------------------------
if (FORZAR_HTTPS
    && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')
    && PHP_SAPI !== 'cli'
) {
    $destino = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
             . ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . $destino, true, 301);
    exit;
}

// ---------------------------------------------------------------------
// 5. Sesion endurecida
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $seguro = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    /*
     * Directorio propio para los archivos de sesion.
     *
     * Dos motivos:
     *  1. Robustez. Si el php.ini no define session.save_path (pasa con
     *     el servidor integrado de PHP y con algunas instalaciones de
     *     MAMP/XAMPP), PHP intenta escribir en /tmp, que en Windows no
     *     existe: session_start() falla en silencio y el usuario nunca
     *     logra iniciar sesion.
     *  2. Aislamiento. En un hosting compartido, las sesiones de todos
     *     los sitios acaban en la misma carpeta temporal y son legibles
     *     entre ellos. Aqui viven dentro de storage/, fuera del
     *     document root.
     */
    if (!is_dir(RUTA_SESIONES)) {
        @mkdir(RUTA_SESIONES, 0770, true);
    }
    if (is_dir(RUTA_SESIONES) && is_writable(RUTA_SESIONES)) {
        session_save_path(RUTA_SESIONES);
    } elseif (session_save_path() === '') {
        // Ultimo recurso: la carpeta temporal del sistema operativo.
        session_save_path(sys_get_temp_dir());
    }

    ini_set('session.use_strict_mode', '1');   // rechaza ids inventados
    ini_set('session.use_only_cookies', '1');  // nunca el id en la URL
    ini_set('session.cookie_httponly', '1');   // invisible para JavaScript
    ini_set('session.gc_maxlifetime', (string) SESION_VIDA_SEGUNDOS);

    session_name(SESION_NOMBRE);
    session_set_cookie_params([
        'lifetime' => 0,            // cookie de sesion (muere al cerrar)
        'path'     => '/',
        'domain'   => '',
        'secure'   => $seguro,      // solo por HTTPS cuando hay HTTPS
        'httponly' => true,
        'samesite' => 'Lax',        // mitiga CSRF desde otros sitios
    ]);

    session_start();

    // Expiracion por inactividad.
    $ahora = time();
    if (isset($_SESSION['ultima_actividad'])
        && ($ahora - (int) $_SESSION['ultima_actividad']) > SESION_VIDA_SEGUNDOS
    ) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['ultima_actividad'] = $ahora;

    /*
     * Rotacion periodica del id de sesion (anti session fixation).
     *
     * OJO CON EL PARAMETRO: va en `false` a proposito.
     *
     * Con `true`, PHP BORRA el archivo de la sesion anterior en el acto.
     * El navegador lanza varias peticiones en paralelo (la pagina, el
     * CSS, los modulos JS y dos o tres llamadas a la API), y Apache las
     * atiende a la vez: si una de ellas rota el identificador y destruye
     * el archivo, las que ya iban en camino siguen presentando el id
     * viejo, no encuentran nada y el usuario ve "tu sesion expiro" sin
     * haber hecho nada.
     *
     * Con `false` el archivo antiguo sobrevive unos minutos hasta que lo
     * recoge el recolector de basura de PHP, asi que las peticiones en
     * vuelo terminan bien. La proteccion se mantiene igual: lo que
     * frustra el ataque de fijacion es que el identificador CAMBIE, no
     * que el archivo viejo se borre al instante (ademas, ese archivo ya
     * no contiene credenciales utiles).
     *
     * Este fallo no se veia con el servidor integrado de PHP, que es
     * monohilo y atiende las peticiones de una en una.
     */
    if (!isset($_SESSION['creada_en'])) {
        $_SESSION['creada_en'] = $ahora;
    } elseif ($ahora - (int) $_SESSION['creada_en'] > SESION_ROTAR_CADA) {
        session_regenerate_id(false);
        $_SESSION['creada_en'] = $ahora;
    }
}

// ---------------------------------------------------------------------
// 6. Subida que excede post_max_size
// ---------------------------------------------------------------------
/*
 * Cuando el cuerpo de una peticion supera post_max_size, PHP lo DESCARTA
 * ENTERO antes de que el script arranque: $_POST y $_FILES llegan
 * vacios, y ni siquiera queda un codigo de error en $_FILES al que
 * agarrarse.
 *
 * El efecto practico es un mensaje enganoso: el validador no encuentra
 * los campos del formulario y responde "el campo titulo es obligatorio"
 * aunque el usuario lo hubiera rellenado. El problema real era el
 * tamano del archivo.
 *
 * Aqui se detecta esa situacion concreta y se responde con la verdad.
 * Las tres condiciones juntas no se dan por casualidad:
 *   · es un envio multipart (un formulario con archivo),
 *   · el navegador declaro un cuerpo de tamano mayor que cero,
 *   · y aun asi no llego ningun campo ni ningun archivo.
 */
if (PHP_SAPI !== 'cli'
    && strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && $_POST === []
    && $_FILES === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')
) {
    $enviado = (int) $_SERVER['CONTENT_LENGTH'];

    /** Convierte "1100M" a bytes. */
    $aBytes = static function (string $valor): int {
        $valor = trim($valor);
        if ($valor === '') {
            return 0;
        }
        $sufijo = strtolower($valor[strlen($valor) - 1]);
        $n = (int) $valor;
        return match ($sufijo) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    };

    $limite = $aBytes((string) ini_get('post_max_size'));

    $legible = static function (int|float $bytes): string {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($u) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $n : number_format($n, 1)) . ' ' . $u[$i];
    };

    $mensaje = sprintf(
        'El archivo es demasiado grande para el servidor. Enviaste %s y el '
        . 'limite actual es %s. Subelo en public/.htaccess (upload_max_filesize '
        . 'y post_max_size) y vuelve a intentarlo.',
        $legible($enviado),
        $limite > 0 ? $legible($limite) : (string) ini_get('post_max_size')
    );

    if (!headers_sent()) {
        // 413 Payload Too Large: el codigo que describe exactamente esto.
        http_response_code(413);
    }

    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => false, 'error' => $mensaje, 'campos' => ['rom' => 'Archivo demasiado grande.']],
            JSON_UNESCAPED_UNICODE
        );
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $mensaje;
    }
    exit;
}
