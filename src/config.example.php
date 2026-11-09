<?php
/**
 * RetroCloud - Plantilla de configuracion
 * ---------------------------------------------------------------------
 * COPIA ESTE ARCHIVO COMO  src/config.php  Y RELLENA TUS DATOS.
 *
 * config.php NO esta en el repositorio a proposito: contiene las
 * credenciales de tu base de datos y esas no se comparten. Este archivo
 * es la plantilla con los valores vacios o por omision.
 *
 *     copy src\config.example.php src\config.php      (Windows)
 *     cp  src/config.example.php  src/config.php       (Linux / Mac)
 */
declare(strict_types=1);

// ---------------------------------------------------------------------
// Entorno:  'desarrollo'  ->  muestra errores en pantalla
//           'produccion'  ->  los registra en logs/ y muestra pagina 500
// ---------------------------------------------------------------------
define('APP_ENTORNO', 'desarrollo');

define('APP_NOMBRE',  'RetroCloud');
define('APP_VERSION', '1.0.0');

/**
 * URL base de la aplicacion (sin diagonal final).
 * Se detecta sola; cambiala a mano si despliegas en un subdominio.
 */
define('APP_URL', (static function (): string {
    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    // Si el script esta en /api o /admin, subimos un nivel.
    foreach (['/api', '/admin'] as $sub) {
        if (str_ends_with($dir, $sub)) {
            $dir = substr($dir, 0, -strlen($sub));
        }
    }
    return $esquema . '://' . $host . $dir;
})());

// ---------------------------------------------------------------------
// Base de datos
// ---------------------------------------------------------------------
define('DB_HOST',   '127.0.0.1');
define('DB_PUERTO', 3306);
define('DB_NOMBRE', 'retrocloud');
define('DB_USUARIO', 'root');
define('DB_PASSWORD', '');   // MAMP por defecto: root / root
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------
// Rutas del sistema de archivos
// ---------------------------------------------------------------------
define('RUTA_RAIZ',     dirname(__DIR__));
define('RUTA_PUBLICA',  RUTA_RAIZ . DIRECTORY_SEPARATOR . 'public');
define('RUTA_STORAGE',  RUTA_RAIZ . DIRECTORY_SEPARATOR . 'storage');
define('RUTA_ROMS',     RUTA_STORAGE . DIRECTORY_SEPARATOR . 'roms');
define('RUTA_SAVES',    RUTA_STORAGE . DIRECTORY_SEPARATOR . 'saves');
define('RUTA_CAPTURAS', RUTA_STORAGE . DIRECTORY_SEPARATOR . 'capturas');
define('RUTA_PORTADAS', RUTA_PUBLICA . DIRECTORY_SEPARATOR . 'assets'
                                     . DIRECTORY_SEPARATOR . 'portadas');
define('RUTA_LOGS',     RUTA_RAIZ . DIRECTORY_SEPARATOR . 'logs');
define('RUTA_SESIONES', RUTA_STORAGE . DIRECTORY_SEPARATOR . 'sesiones');
define('RUTA_BIOS',     RUTA_STORAGE . DIRECTORY_SEPARATOR . 'bios');

// ---------------------------------------------------------------------
// Motor de emulacion
// ---------------------------------------------------------------------
/**
 * Si existe una instalacion local de EmulatorJS en
 * public/emulatorjs/data/, se usa esa (permite demostrar el proyecto
 * sin conexion). Si no, se recurre al CDN oficial.
 *
 * Estas tres constantes deben decidirse en UN solo lugar, porque las
 * consumen dos sitios que tienen que coincidir:
 *   · jugar.php          -> de donde carga el script el navegador
 *   · bootstrap.php      -> que origen autoriza la Content-Security-Policy
 * Si discrepan, la CSP bloquea el emulador.
 */
define('EMULADOR_LOCAL', is_file(
    RUTA_PUBLICA . DIRECTORY_SEPARATOR . 'emulatorjs'
                 . DIRECTORY_SEPARATOR . 'data'
                 . DIRECTORY_SEPARATOR . 'loader.js'
));

define('EMULADOR_CDN', 'https://cdn.emulatorjs.org');

define('EMULADOR_RUTA_DATOS', EMULADOR_LOCAL
    ? 'emulatorjs/data/'
    : EMULADOR_CDN . '/stable/data/');

/** Origen externo a autorizar en la CSP ('' cuando es local). */
define('EMULADOR_ORIGEN', EMULADOR_LOCAL ? '' : EMULADOR_CDN);

// ---------------------------------------------------------------------
// Limites de subida
// ---------------------------------------------------------------------
/*
 * El limite de la ROM se subio a 1 GB para admitir discos de
 * PlayStation, que rondan los 300-700 MB (los cartuchos no pasan de
 * unas decenas de MB).
 *
 * IMPORTANTE: este numero solo marca el techo de la aplicacion. PHP
 * impone el suyo antes, con upload_max_filesize y post_max_size; si el
 * de PHP es menor, manda PHP y el archivo se rechaza antes de llegar
 * aqui. Los dos deben subirse a la vez (ver docs/documento-tecnico.md).
 * La pagina diagnostico.php compara ambos y avisa si no cuadran.
 *
 * Las partidas de PlayStation tambien son mayores que las de un
 * cartucho, de ahi que el limite de save suba a 32 MB.
 */
define('MAX_BYTES_ROM',   1024 * 1024 * 1024);  // 1 GB
define('MAX_BYTES_SAVE',    32 * 1024 * 1024);  //  32 MB
/* Las firmwares de sistema son pequenas: la de PlayStation ronda los
   512 KB y la de Neo Geo unos pocos MB dentro de un zip. */
define('MAX_BYTES_BIOS',    16 * 1024 * 1024);  //  16 MB
define('MAX_BYTES_PORTADA',  3 * 1024 * 1024);  //   3 MB
define('MAX_BYTES_CAPTURA',  1 * 1024 * 1024);  //   1 MB

/** Extensiones de imagen aceptadas para portadas. */
define('EXT_PORTADA', ['jpg', 'jpeg', 'png', 'webp', 'gif']);

// ---------------------------------------------------------------------
// Sesiones y seguridad
// ---------------------------------------------------------------------
define('SESION_NOMBRE',        'retrocloud_sid');
define('SESION_VIDA_SEGUNDOS', 60 * 60 * 8);   // 8 horas
define('SESION_ROTAR_CADA',    60 * 15);       // regenerar id cada 15 min

/** Fuerza HTTPS con una redireccion 301. Activar al desplegar con SSL. */
define('FORZAR_HTTPS', false);

/** Fuerza bruta: N intentos fallidos por ventana de M segundos. */
define('LOGIN_MAX_INTENTOS', 5);
define('LOGIN_VENTANA_SEG',  900);    // 15 minutos
define('LOGIN_BLOQUEO_SEG',  900);    // 15 minutos de castigo

/** Coste del algoritmo bcrypt (10-12 es el rango sano). */
define('BCRYPT_COSTE', 12);

/** Longitud minima de contrasena al registrarse. */
define('PASSWORD_MIN', 8);

// ---------------------------------------------------------------------
// Zona horaria
// ---------------------------------------------------------------------
define('APP_ZONA_HORARIA', 'America/Mexico_City');
