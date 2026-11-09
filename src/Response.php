<?php
/**
 * RetroCloud · Respuestas HTTP de la API
 * ---------------------------------------------------------------------
 * Toda la API habla un unico dialecto JSON:
 *
 *   exito  -> { "ok": true,  "datos": ... }
 *   fallo  -> { "ok": false, "error": "mensaje", "campos": {...} }
 *
 * Tener un solo formato permite que el JavaScript del cliente tenga un
 * unico manejador de errores en lugar de un `if` por endpoint.
 */

declare(strict_types=1);

namespace RetroCloud;

final class Response
{
    private function __construct() {}

    /** Respuesta correcta. */
    public static function ok(mixed $datos = null, int $codigo = 200): never
    {
        self::enviar(['ok' => true, 'datos' => $datos], $codigo);
    }

    /**
     * Respuesta de error.
     *
     * @param array<string, string> $campos errores por campo de formulario
     */
    public static function error(string $mensaje, int $codigo = 400, array $campos = []): never
    {
        $cuerpo = ['ok' => false, 'error' => $mensaje];
        if ($campos !== []) {
            $cuerpo['campos'] = $campos;
        }
        self::enviar($cuerpo, $codigo);
    }

    /** 401 · no hay sesion activa. */
    public static function noAutenticado(string $mensaje = 'Necesitas iniciar sesion.'): never
    {
        self::error($mensaje, 401);
    }

    /** 403 · hay sesion pero sin permisos suficientes. */
    public static function prohibido(string $mensaje = 'No tienes permiso para esta accion.'): never
    {
        self::error($mensaje, 403);
    }

    /** 404 · recurso inexistente. */
    public static function noEncontrado(string $mensaje = 'Recurso no encontrado.'): never
    {
        self::error($mensaje, 404);
    }

    /** 405 · metodo HTTP equivocado. */
    public static function metodoNoPermitido(string ...$permitidos): never
    {
        if ($permitidos !== [] && !headers_sent()) {
            header('Allow: ' . implode(', ', $permitidos));
        }
        self::error('Metodo HTTP no permitido en este endpoint.', 405);
    }

    /** 429 · demasiadas peticiones. */
    public static function demasiadasPeticiones(int $segundos): never
    {
        if (!headers_sent()) {
            header('Retry-After: ' . $segundos);
        }
        self::error(
            'Demasiados intentos. Espera ' . ceil($segundos / 60) . ' minuto(s).',
            429
        );
    }

    /**
     * Exige que la peticion use uno de los metodos indicados.
     * Responde 405 y corta la ejecucion si no coincide.
     */
    public static function exigirMetodo(string ...$permitidos): void
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($metodo, array_map('strtoupper', $permitidos), true)) {
            self::metodoNoPermitido(...$permitidos);
        }
    }

    /**
     * Lee el cuerpo de la peticion como JSON.
     * Si el cliente envio un formulario clasico, cae de vuelta a $_POST.
     *
     * @return array<string, mixed>
     */
    public static function cuerpoJson(): array
    {
        $tipo = $_SERVER['CONTENT_TYPE'] ?? '';

        if (!str_contains($tipo, 'application/json')) {
            /** @var array<string, mixed> $post */
            $post = $_POST;
            return $post;
        }

        $crudo = file_get_contents('php://input');
        if ($crudo === false || $crudo === '') {
            return [];
        }

        try {
            /** @var array<string, mixed> $datos */
            $datos = json_decode($crudo, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::error('El cuerpo de la peticion no es JSON valido.', 400);
        }

        return is_array($datos) ? $datos : [];
    }

    /** Serializa y termina la ejecucion. */
    private static function enviar(mixed $cuerpo, int $codigo): never
    {
        if (!headers_sent()) {
            http_response_code($codigo);
            header('Content-Type: application/json; charset=utf-8');
            // La API nunca debe quedarse en cache de un proxy intermedio.
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        echo json_encode(
            $cuerpo,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        exit;
    }
}
