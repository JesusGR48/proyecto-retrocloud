<?php
/**
 * RetroCloud · API · /api/auth.php
 * ---------------------------------------------------------------------
 * Acciones (via ?accion= o campo "accion" del cuerpo):
 *
 *   POST  registro   · crea una cuenta nueva
 *   POST  login      · abre sesion (limitado contra fuerza bruta)
 *   POST  logout     · cierra sesion
 *   POST  password   · cambia la contrasena del usuario en sesion
 *   GET   sesion     · devuelve el estado de la sesion actual
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Csrf;
use RetroCloud\Database;
use RetroCloud\RateLimiter;
use RetroCloud\Response;
use RetroCloud\Validator;

$cuerpo = Response::cuerpoJson();
$accion = (string) ($_GET['accion'] ?? $cuerpo['accion'] ?? '');

// El token CSRF se exige en todo lo que modifique estado.
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Csrf::exigir($cuerpo);
}

switch ($accion) {

    // =================================================================
    case 'registro':
        Response::exigirMetodo('POST');

        $v = Validator::de($cuerpo);
        $v->requerido('usuario', 'usuario')->nombreUsuario('usuario')
          ->requerido('correo', 'correo')->correo('correo')
          ->requerido('password', 'contrasena')->password('password')
          ->coincide('password', 'password2', 'La confirmacion de contrasena')
          ->oResponder();

        try {
            $id = Auth::registrar(
                (string) $v->crudo('usuario'),
                (string) $v->crudo('correo'),
                (string) $cuerpo['password']
            );
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 409);
        }

        // Cortesia: deja la sesion abierta tras registrarse.
        Auth::iniciarSesion((string) $v->crudo('usuario'), (string) $cuerpo['password']);

        Response::ok([
            'id'       => $id,
            'usuario'  => Auth::nombre(),
            'rol'      => Auth::rol(),
            'mensaje'  => 'Cuenta creada. Bienvenido a RetroCloud.',
            'csrf'     => Csrf::token(),
        ], 201);
        // no break: Response::ok() termina la ejecucion

    // =================================================================
    case 'login':
        Response::exigirMetodo('POST');

        $identificador = trim((string) ($cuerpo['usuario'] ?? ''));
        $password      = (string) ($cuerpo['password'] ?? '');

        if ($identificador === '' || $password === '') {
            Response::error('Escribe tu usuario y tu contrasena.', 422);
        }

        // 1. Puerta del limitador: bloquea antes de tocar la BD de usuarios.
        RateLimiter::exigirLibre($identificador);

        // 2. Verificacion de credenciales.
        try {
            $usuario = Auth::iniciarSesion($identificador, $password);
        } catch (RuntimeException $e) {
            RateLimiter::registrar($identificador, false);
            Response::error($e->getMessage(), 403);
        }

        if ($usuario === null) {
            RateLimiter::registrar($identificador, false);

            $restantes = LOGIN_MAX_INTENTOS
                       - (int) Database::valor(
                           'SELECT COUNT(*) FROM intentos_login
                             WHERE exito = 0
                               AND creado_en > (NOW() - INTERVAL :v SECOND)
                               AND (ip = :ip OR identificador = :i)',
                           ['v' => LOGIN_VENTANA_SEG, 'ip' => RateLimiter::ip(), 'i' => $identificador]
                       );

            // Mensaje deliberadamente vago: no revelamos si el usuario
            // existe o si fue la contrasena la que estaba mal.
            $msg = 'Usuario o contrasena incorrectos.';
            if ($restantes > 0 && $restantes <= 2) {
                $msg .= " Te quedan $restantes intento(s) antes del bloqueo temporal.";
            }
            Response::error($msg, 401);
        }

        // 3. Exito: limpiamos el historial de fallos.
        RateLimiter::registrar($identificador, true);
        RateLimiter::limpiar($identificador);

        // Mantenimiento oportunista (1 de cada 20 logins).
        if (random_int(1, 20) === 1) {
            RateLimiter::purgar();
        }

        Response::ok([
            'id'      => (int) $usuario['id'],
            'usuario' => (string) $usuario['nombre_usuario'],
            'rol'     => (string) $usuario['rol'],
            'csrf'    => Csrf::token(),
            'mensaje' => 'Sesion iniciada.',
        ]);

    // =================================================================
    case 'logout':
        Response::exigirMetodo('POST');
        Auth::cerrarSesion();
        Response::ok(['mensaje' => 'Sesion cerrada.']);

    // =================================================================
    case 'password':
        Response::exigirMetodo('POST');
        $usuarioId = Auth::exigirApi();

        $v = Validator::de($cuerpo);
        $v->requerido('actual', 'contrasena actual')
          ->requerido('nueva', 'contrasena nueva')->password('nueva')
          ->coincide('nueva', 'nueva2', 'La confirmacion de contrasena')
          ->oResponder();

        try {
            Auth::cambiarPassword(
                $usuarioId,
                (string) $cuerpo['actual'],
                (string) $cuerpo['nueva']
            );
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 403);
        }

        Response::ok(['mensaje' => 'Contrasena actualizada.']);

    // =================================================================
    case 'sesion':
        Response::exigirMetodo('GET');

        if (!Auth::autenticado()) {
            Response::ok(['autenticado' => false, 'csrf' => Csrf::token()]);
        }

        Response::ok([
            'autenticado' => true,
            'id'          => Auth::id(),
            'usuario'     => Auth::nombre(),
            'rol'         => Auth::rol(),
            'es_admin'    => Auth::esAdmin(),
            'csrf'        => Csrf::token(),
        ]);

    // =================================================================
    default:
        Response::error(
            'Accion no reconocida. Usa: registro, login, logout, password o sesion.',
            400
        );
}
