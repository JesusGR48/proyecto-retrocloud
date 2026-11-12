<?php
/**
 * RetroCloud · API · /api/recomendaciones.php
 * ---------------------------------------------------------------------
 * Expone el motor de recomendaciones.
 *
 *   GET (sin parametros)  · juegos sugeridos para quien pregunta
 *   GET ?explicar=1       · ademas, como razono el modelo
 *
 * No exige sesion a proposito: sin ella el motor devuelve la lista por
 * popularidad y lo dice en el diagnostico. Asi la portada tiene algo
 * que enseñar a un visitante que aun no se registro.
 *
 * El diagnostico completo solo se entrega con ?explicar=1 y sesion
 * iniciada, porque describe el perfil de gustos de esa persona: es un
 * dato personal y no tiene por que viajar en cada peticion.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use RetroCloud\Auth;
use RetroCloud\Recomendador;
use RetroCloud\Response;

Response::exigirMetodo('GET');

$usuarioId = Auth::autenticado() ? Auth::id() : null;

/* Tope defensivo: el calculo de similitudes es cuadratico sobre el
   catalogo, asi que no se deja al cliente pedir una lista arbitraria. */
$limite = (int) ($_GET['limite'] ?? 6);
$limite = max(1, min(12, $limite));

$explicar = isset($_GET['explicar']) && $_GET['explicar'] !== '0';

$inicio = microtime(true);
$resultado = Recomendador::para($usuarioId, $limite);
$ms = (microtime(true) - $inicio) * 1000;

$datos = ['recomendaciones' => $resultado['recomendaciones']];

if ($explicar && $usuarioId !== null) {
    $datos['diagnostico'] = $resultado['diagnostico'];
    $datos['diagnostico']['milisegundos'] = round($ms, 1);
} else {
    // Version reducida: la estrategia no revela nada personal.
    $datos['estrategia'] = $resultado['diagnostico']['estrategia'];
}

Response::ok($datos);
