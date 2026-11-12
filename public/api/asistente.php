<?php
/**
 * RetroCloud · API · /api/asistente.php
 * ---------------------------------------------------------------------
 * POST { mensaje: string, filtros: object }
 *   -> { respuesta, opciones[], juegos[], filtros }
 *
 * El asistente es sin estado: no guarda nada entre peticiones. Los
 * filtros acumulados viajan al cliente y vuelven en la siguiente
 * llamada, lo que evita tocar la sesion de PHP en cada tecla y permite
 * tener varias pestañas abiertas sin que se pisen.
 *
 * La contrapartida es que esos filtros son entrada del usuario y NO se
 * pueden dar por buenos: Asistente::fusionar() solo conserva las claves
 * conocidas y las convierte al tipo que toca, de modo que nadie puede
 * inyectar criterios arbitrarios en la consulta.
 *
 * Es POST y no GET porque lleva cuerpo y porque asi exige token CSRF
 * como el resto de la API, aunque no modifique nada.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Asistente;
use RetroCloud\Auth;
use RetroCloud\Csrf;
use RetroCloud\Response;

Response::exigirMetodo('POST');
Csrf::exigir();

$cuerpo = Response::cuerpoJson();

$mensaje = $cuerpo['mensaje'] ?? '';
$mensaje = is_string($mensaje) ? mb_substr(trim($mensaje), 0, 200) : '';

$filtros = $cuerpo['filtros'] ?? [];
$filtros = is_array($filtros) ? $filtros : [];

$usuarioId = Auth::autenticado() ? Auth::id() : null;

Response::ok(Asistente::responder($usuarioId, $mensaje, $filtros));
