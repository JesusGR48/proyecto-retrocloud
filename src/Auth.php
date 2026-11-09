<?php
/**
 * RetroCloud · Autenticacion y control de acceso
 * ---------------------------------------------------------------------
 * Puntos clave del diseno:
 *
 *  · La contrasena se guarda como hash bcrypt (password_hash). Bcrypt
 *    incorpora su propio salt aleatorio y es deliberadamente lento, lo
 *    que encarece muchisimo un ataque de diccionario. Nunca se guarda
 *    ni se registra la contrasena en claro.
 *
 *  · La verificacion usa password_verify(), que compara en tiempo
 *    constante. Ante usuario inexistente se ejecuta igualmente un hash
 *    ficticio para que el tiempo de respuesta no delate si la cuenta
 *    existe (enumeracion de usuarios).
 *
 *  · Tras un login correcto se regenera el id de sesion para anular
 *    cualquier id previamente fijado por un atacante (session fixation).
 *
 *  · Si cambian los parametros de bcrypt, el hash se actualiza de forma
 *    transparente la proxima vez que el usuario entra (rehash).
 */

declare(strict_types=1);

namespace RetroCloud;

final class Auth
{
    /** Hash de descarte para igualar tiempos cuando el usuario no existe. */
    private const HASH_SENUELO =
        '$2y$12$usuarioInexistenteXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';

    private function __construct() {}

    // -----------------------------------------------------------------
    // Registro
    // -----------------------------------------------------------------

    /**
     * Crea una cuenta. Devuelve el id nuevo.
     *
     * @throws \RuntimeException si el usuario o el correo ya existen
     */
    public static function registrar(
        string $usuario,
        string $correo,
        string $password,
        string $rol = 'jugador'
    ): int {
        $correo = mb_strtolower(trim($correo));

        $ocupado = Database::fila(
            'SELECT nombre_usuario, correo FROM usuarios
              WHERE nombre_usuario = :u OR correo = :c LIMIT 1',
            ['u' => $usuario, 'c' => $correo]
        );

        if ($ocupado !== null) {
            if (mb_strtolower((string) $ocupado['nombre_usuario']) === mb_strtolower($usuario)) {
                throw new \RuntimeException('Ese nombre de usuario ya esta tomado.');
            }
            throw new \RuntimeException('Ese correo ya tiene una cuenta registrada.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COSTE]);

        return Database::insertar(
            'INSERT INTO usuarios (nombre_usuario, correo, hash_password, rol)
             VALUES (:u, :c, :h, :r)',
            ['u' => $usuario, 'c' => $correo, 'h' => $hash, 'r' => $rol]
        );
    }

    // -----------------------------------------------------------------
    // Inicio de sesion
    // -----------------------------------------------------------------

    /**
     * Valida credenciales y abre la sesion.
     * `$identificador` acepta nombre de usuario o correo.
     *
     * @return array<string, mixed>|null el usuario, o null si fallo
     */
    public static function iniciarSesion(string $identificador, string $password): ?array
    {
        /*
         * Con PDO::ATTR_EMULATE_PREPARES desactivado usamos sentencias
         * preparadas REALES de MySQL, que son posicionales. Por eso un
         * mismo marcador nombrado no puede repetirse en la consulta:
         * hay que declarar :ident_usuario y :ident_correo por separado
         * aunque reciban el mismo valor.
         */
        $usuario = Database::fila(
            'SELECT id, nombre_usuario, correo, hash_password, rol, activo, avatar
               FROM usuarios
              WHERE nombre_usuario = :ident_usuario OR correo = :ident_correo
              LIMIT 1',
            [
                'ident_usuario' => $identificador,
                'ident_correo'  => $identificador,
            ]
        );

        // Cuenta inexistente: gastamos el mismo tiempo que en un fallo real.
        if ($usuario === null) {
            password_verify($password, self::HASH_SENUELO);
            return null;
        }

        if (!password_verify($password, (string) $usuario['hash_password'])) {
            return null;
        }

        if ((int) $usuario['activo'] !== 1) {
            throw new \RuntimeException('Esta cuenta esta deshabilitada.');
        }

        // Rehash transparente si subio el coste de bcrypt.
        if (password_needs_rehash(
            (string) $usuario['hash_password'],
            PASSWORD_BCRYPT,
            ['cost' => BCRYPT_COSTE]
        )) {
            Database::ejecutar(
                'UPDATE usuarios SET hash_password = :h WHERE id = :id',
                [
                    'h'  => password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COSTE]),
                    'id' => $usuario['id'],
                ]
            );
        }

        // Anti session fixation: id nuevo, datos conservados.
        session_regenerate_id(true);
        Csrf::rotar();

        $_SESSION['usuario_id']  = (int) $usuario['id'];
        $_SESSION['usuario']     = (string) $usuario['nombre_usuario'];
        $_SESSION['rol']         = (string) $usuario['rol'];
        $_SESSION['creada_en']   = time();

        Database::ejecutar(
            'UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :id',
            ['id' => $usuario['id']]
        );

        unset($usuario['hash_password']);
        return $usuario;
    }

    public static function cerrarSesion(): void
    {
        $_SESSION = [];

        /*
         * Solo se puede borrar la cookie si todavia no se envio nada al
         * navegador. Si el cierre ocurre a media pagina (por ejemplo al
         * detectar una sesion invalida mientras se pinta la cabecera),
         * setcookie() emitiria un warning y, con nuestro manejador de
         * errores, abortaria el renderizado. Vaciar y destruir la sesion
         * en el servidor basta: la cookie que quede en el navegador ya
         * no apunta a ningun dato.
         */
        if (!headers_sent() && ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    // -----------------------------------------------------------------
    // Estado
    // -----------------------------------------------------------------

    public static function autenticado(): bool
    {
        return !empty($_SESSION['usuario_id']);
    }

    public static function id(): ?int
    {
        return self::autenticado() ? (int) $_SESSION['usuario_id'] : null;
    }

    public static function nombre(): ?string
    {
        return self::autenticado() ? (string) $_SESSION['usuario'] : null;
    }

    public static function rol(): ?string
    {
        return self::autenticado() ? (string) $_SESSION['rol'] : null;
    }

    public static function esAdmin(): bool
    {
        return self::autenticado() && ($_SESSION['rol'] ?? '') === 'admin';
    }

    /** @return array<string, mixed>|null */
    public static function usuario(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }
        return Database::fila(
            'SELECT id, nombre_usuario, correo, rol, avatar, creado_en, ultimo_acceso
               FROM usuarios WHERE id = :id',
            ['id' => $id]
        );
    }

    // -----------------------------------------------------------------
    // Guardianes
    // -----------------------------------------------------------------

    /** Para endpoints de la API: responde 401 en JSON. */
    public static function exigirApi(): int
    {
        $id = self::id();
        if ($id === null) {
            Response::noAutenticado();
        }
        return $id;
    }

    /** Para endpoints de la API que piden rol admin: responde 403. */
    public static function exigirAdminApi(): int
    {
        $id = self::exigirApi();
        if (!self::esAdmin()) {
            Response::prohibido();
        }
        return $id;
    }

    /** Para paginas HTML: redirige al login conservando el destino. */
    public static function exigirPagina(string $login = 'login.php'): int
    {
        $id = self::id();
        if ($id === null) {
            $destino = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: ' . $login . '?volver=' . urlencode($destino), true, 302);
            exit;
        }
        return $id;
    }

    /** Para paginas del panel: exige rol admin. */
    public static function exigirAdminPagina(string $login = '../login.php'): int
    {
        $id = self::exigirPagina($login);
        if (!self::esAdmin()) {
            http_response_code(403);
            echo '<!doctype html><meta charset="utf-8">'
               . '<title>403</title>'
               . '<body style="font:16px/1.6 system-ui;padding:3rem;background:#12121c;color:#eee">'
               . '<h1>403 &middot; Acceso restringido</h1>'
               . '<p>Esta seccion es solo para administradores.</p>'
               . '<p><a style="color:#7d5fff" href="../index.php">Volver al catalogo</a></p>';
            exit;
        }
        return $id;
    }

    /**
     * Cambio de contrasena con verificacion de la actual.
     *
     * @throws \RuntimeException
     */
    public static function cambiarPassword(int $usuarioId, string $actual, string $nueva): void
    {
        $hash = Database::valor(
            'SELECT hash_password FROM usuarios WHERE id = :id',
            ['id' => $usuarioId]
        );

        if (!is_string($hash) || !password_verify($actual, $hash)) {
            throw new \RuntimeException('La contrasena actual no es correcta.');
        }

        Database::ejecutar(
            'UPDATE usuarios SET hash_password = :h WHERE id = :id',
            [
                'h'  => password_hash($nueva, PASSWORD_BCRYPT, ['cost' => BCRYPT_COSTE]),
                'id' => $usuarioId,
            ]
        );
    }

    /*
     * NOTA DE DISENO · por que NO atamos la sesion a una huella del cliente
     * -----------------------------------------------------------------
     * Una practica difundida es guardar un hash de la IP y del
     * User-Agent al iniciar sesion y cerrar la sesion si cambian. Se
     * implemento y se descarto tras comprobarlo en el navegador:
     *
     *  · La IP cambia sola al pasar de WiFi a datos moviles.
     *
     *  · Accept-Language NO viaja igual en una navegacion de pagina que
     *    en una peticion fetch().
     *
     *  · El propio User-Agent tampoco es estable: con la reduccion de
     *    UA y los client hints de los navegadores actuales, la cadena
     *    que llega en una navegacion puede diferir de la que llega en
     *    una peticion de la API. En las pruebas, la sesion se cerraba
     *    sola en la siguiente peticion.
     *
     * El resultado era expulsar a usuarios legitimos sin frenar a un
     * atacante real, que puede copiar esas cabeceras tan facilmente
     * como la cookie. Las defensas que si sostienen la sesion son las
     * que quedan activas: cookie HttpOnly (invisible para JavaScript),
     * SameSite=Lax, regeneracion del id al entrar y cada 15 minutos,
     * caducidad por inactividad y HTTPS en produccion.
     */
}
