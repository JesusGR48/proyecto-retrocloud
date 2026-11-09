<?php
/**
 * RetroCloud · Proteccion CSRF (Cross-Site Request Forgery)
 * ---------------------------------------------------------------------
 * El ataque: un sitio malicioso incrusta un formulario oculto que apunta
 * a RetroCloud. Si la victima tiene sesion abierta, el navegador manda
 * la cookie automaticamente y el servidor cree que la peticion es legitima.
 *
 * La defensa: cada formulario lleva un token aleatorio que vive en la
 * sesion del servidor. Un sitio ajeno no puede leer esa sesion, asi que
 * no puede adivinar el token y su peticion falsificada se rechaza.
 *
 * La comparacion usa hash_equals() y no ===, para que el tiempo de
 * respuesta no revele cuantos caracteres del token eran correctos
 * (ataque de temporizacion).
 */

declare(strict_types=1);

namespace RetroCloud;

final class Csrf
{
    private const CLAVE_SESION = '_csrf';
    private const BYTES        = 32;

    /** Cabecera que usa el JavaScript para peticiones fetch(). */
    public const CABECERA = 'HTTP_X_CSRF_TOKEN';

    /** Nombre del campo oculto en formularios HTML. */
    public const CAMPO = 'csrf_token';

    private function __construct() {}

    /** Devuelve el token de la sesion, generandolo la primera vez. */
    public static function token(): string
    {
        if (empty($_SESSION[self::CLAVE_SESION])) {
            $_SESSION[self::CLAVE_SESION] = bin2hex(random_bytes(self::BYTES));
        }
        return (string) $_SESSION[self::CLAVE_SESION];
    }

    /** Campo oculto listo para pegar dentro de un <form>. */
    public static function campo(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::CAMPO,
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }

    /** Etiqueta <meta> que el JavaScript lee para poblar sus cabeceras. */
    public static function meta(): string
    {
        return sprintf(
            '<meta name="csrf-token" content="%s">',
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Valida el token recibido. Lo busca, en orden, en:
     *   1. la cabecera X-CSRF-Token   (peticiones fetch)
     *   2. el campo POST              (formularios clasicos)
     *   3. el cuerpo JSON             (peticiones con application/json)
     *
     * @param array<string, mixed>|null $cuerpoJson
     */
    public static function valido(?array $cuerpoJson = null): bool
    {
        $esperado = $_SESSION[self::CLAVE_SESION] ?? null;
        if (!is_string($esperado) || $esperado === '') {
            return false;
        }

        $recibido = $_SERVER[self::CABECERA]
            ?? $_POST[self::CAMPO]
            ?? ($cuerpoJson[self::CAMPO] ?? null);

        if (!is_string($recibido) || $recibido === '') {
            return false;
        }

        return hash_equals($esperado, $recibido);
    }

    /**
     * Version estricta: si el token no cuadra, corta la peticion.
     * Los GET se consideran seguros (no modifican estado) y se dejan pasar.
     *
     * @param array<string, mixed>|null $cuerpoJson
     */
    public static function exigir(?array $cuerpoJson = null): void
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($metodo, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        if (!self::valido($cuerpoJson)) {
            Response::error(
                'Token de seguridad invalido o expirado. Recarga la pagina e intenta de nuevo.',
                419
            );
        }
    }

    /** Rota el token (tras iniciar o cerrar sesion). */
    public static function rotar(): void
    {
        $_SESSION[self::CLAVE_SESION] = bin2hex(random_bytes(self::BYTES));
    }
}
