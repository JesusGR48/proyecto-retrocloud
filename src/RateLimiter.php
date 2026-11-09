<?php
/**
 * RetroCloud · Limitador de intentos (anti fuerza bruta)
 * ---------------------------------------------------------------------
 * Sin esta pieza, un atacante puede probar miles de contrasenas por
 * minuto contra el formulario de login. Aqui registramos cada intento
 * en la tabla `intentos_login` y bloqueamos temporalmente cuando se
 * excede el umbral configurado.
 *
 * Se cuenta por DOS llaves a la vez:
 *   - por IP      -> frena a quien ataca muchas cuentas distintas
 *   - por cuenta  -> frena a quien ataca una cuenta desde muchas IPs
 */

declare(strict_types=1);

namespace RetroCloud;

final class RateLimiter
{
    private function __construct() {}

    /** Deja constancia del intento. */
    public static function registrar(string $identificador, bool $exito): void
    {
        Database::ejecutar(
            'INSERT INTO intentos_login (identificador, ip, exito, user_agent)
             VALUES (:ident, :ip, :exito, :ua)',
            [
                'ident' => mb_substr($identificador, 0, 190),
                'ip'    => self::ip(),
                'exito' => $exito ? 1 : 0,
                'ua'    => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
    }

    /**
     * Segundos que faltan para poder reintentar.
     * Devuelve 0 cuando no hay bloqueo activo.
     */
    public static function segundosDeBloqueo(string $identificador): int
    {
        $ip = self::ip();

        $fila = Database::fila(
            'SELECT COUNT(*) AS fallos, MAX(creado_en) AS ultimo
               FROM intentos_login
              WHERE exito = 0
                AND creado_en > (NOW() - INTERVAL :ventana SECOND)
                AND (ip = :ip OR identificador = :ident)',
            [
                'ventana' => LOGIN_VENTANA_SEG,
                'ip'      => $ip,
                'ident'   => mb_substr($identificador, 0, 190),
            ]
        );

        $fallos = (int) ($fila['fallos'] ?? 0);
        if ($fallos < LOGIN_MAX_INTENTOS) {
            return 0;
        }

        $ultimo = isset($fila['ultimo']) ? strtotime((string) $fila['ultimo']) : false;
        if ($ultimo === false) {
            return 0;
        }

        $restante = ($ultimo + LOGIN_BLOQUEO_SEG) - time();
        return $restante > 0 ? $restante : 0;
    }

    /** Si hay bloqueo, responde 429 y corta la peticion. */
    public static function exigirLibre(string $identificador): void
    {
        $espera = self::segundosDeBloqueo($identificador);
        if ($espera > 0) {
            Response::demasiadasPeticiones($espera);
        }
    }

    /** Limpia el historial de una cuenta tras un login exitoso. */
    public static function limpiar(string $identificador): void
    {
        Database::ejecutar(
            'DELETE FROM intentos_login
              WHERE exito = 0
                AND (identificador = :ident OR ip = :ip)',
            ['ident' => mb_substr($identificador, 0, 190), 'ip' => self::ip()]
        );
    }

    /** Borra registros viejos. Lo invoca el login de vez en cuando. */
    public static function purgar(int $dias = 7): void
    {
        Database::ejecutar(
            'DELETE FROM intentos_login WHERE creado_en < (NOW() - INTERVAL :d DAY)',
            ['d' => $dias]
        );
    }

    /**
     * IP del cliente.
     *
     * Deliberadamente NO confiamos en X-Forwarded-For: cualquiera puede
     * falsificar esa cabecera y burlar el limitador. Solo se usaria tras
     * un proxy inverso de confianza, declarandolo de forma explicita.
     */
    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}
