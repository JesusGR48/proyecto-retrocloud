<?php
/**
 * RetroCloud · Plantilla de cabecera
 * ---------------------------------------------------------------------
 * Cada pagina define sus variables y despues incluye este archivo:
 *
 *   $tituloPagina   string  titulo del <head>
 *   $paginaActual   string  clave para marcar el enlace activo
 *   $descripcion    string  meta description (opcional)
 *   $enRaiz         bool    false si la pagina vive en /admin (opcional)
 *   $scriptModulo   string  ruta del modulo JS a cargar (opcional)
 *   $sinApp         bool    true para no cargar app.js (opcional)
 */

declare(strict_types=1);

use RetroCloud\Auth;
use RetroCloud\Csrf;
use RetroCloud\Validator;

$tituloPagina = $tituloPagina ?? APP_NOMBRE;
$paginaActual = $paginaActual ?? '';
$descripcion  = $descripcion  ?? 'Plataforma de emulacion retro con sincronizacion de partidas en la nube.';
$enRaiz       = $enRaiz       ?? true;

/** Prefijo de rutas: las paginas de /admin necesitan subir un nivel. */
$base = $enRaiz ? '' : '../';

/** Marca el enlace de navegacion activo. */
$activo = static fn(string $clave): string =>
    $clave === $paginaActual ? ' aria-current="page"' : '';

$e = static fn(mixed $v): string => Validator::e($v);
?>
<!DOCTYPE html>
<html lang="es" data-tema="oscuro">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

  <title><?= $e($tituloPagina) ?> · <?= $e(APP_NOMBRE) ?></title>
  <meta name="description" content="<?= $e($descripcion) ?>">

  <?php /* Token CSRF: el JavaScript lo lee de aqui para sus peticiones. */ ?>
  <?= Csrf::meta() ?>

  <meta name="theme-color" content="#0d0d16">
  <meta name="color-scheme" content="dark light">
  <meta name="robots" content="noindex, nofollow">

  <?php /* Logo propio en SVG embebido: sin peticiones extra ni archivos. */ ?>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<?=
    rawurlencode(
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
      . '<rect width="64" height="64" rx="14" fill="#7d5fff"/>'
      . '<rect x="12" y="24" width="40" height="22" rx="10" fill="none" '
      . 'stroke="#fff" stroke-width="4"/>'
      . '<circle cx="23" cy="35" r="3" fill="#fff"/>'
      . '<circle cx="41" cy="35" r="3" fill="#00e5c0"/>'
      . '<path d="M26 16h12" stroke="#00e5c0" stroke-width="4" stroke-linecap="round"/>'
      . '</svg>'
    ) ?>">

  <link rel="manifest" href="<?= $base ?>manifest.webmanifest">

  <?php /*
    Soporte de aplicacion instalada en iPhone y iPad.

    Safari NO permite que una pagina web oculte su barra de direcciones
    ni la barra inferior: no existe ninguna API para hacerlo, y la
    pantalla completa nativa solo acepta elementos <video>. Por eso, en
    el telefono, el juego siempre convive con la interfaz del navegador.

    La unica via para ganar la pantalla entera es que el usuario anada
    el sitio a la pantalla de inicio: iOS lo abre entonces en modo
    "standalone", sin barras. Estas tres etiquetas son las que iOS lee
    para hacerlo bien (el manifest por si solo no basta en Safari).
  */ ?>
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="RetroCloud">
  <link rel="apple-touch-icon" href="<?= $base ?>assets/img/icono-192.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Press+Start+2P&family=JetBrains+Mono:wght@400;600&display=swap">

  <?php /* recurso() anade ?v=<fecha> para que el navegador descargue la
           version nueva en cuanto el archivo cambie, pese a la cache
           larga que declara .htaccess. */ ?>
  <link rel="stylesheet" href="<?= $e(recurso('assets/css/estilos.css')) ?>">

  <script>
    /* Prefijo de rutas para el JavaScript. Las paginas de /admin viven
       un nivel mas abajo, asi que api.js necesita saberlo para no pedir
       /admin/api/juegos.php en lugar de /api/juegos.php. */
    window.RETROCLOUD_BASE = <?= json_encode($base, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;

    /* Aplica el tema guardado antes del primer pintado para evitar el
       destello blanco en quien usa el tema claro. */
    (function () {
      try {
        var t = localStorage.getItem('retrocloud:tema');
        if (t !== 'claro' && t !== 'oscuro') {
          t = window.matchMedia('(prefers-color-scheme: light)').matches ? 'claro' : 'oscuro';
        }
        document.documentElement.dataset.tema = t;
      } catch (e) { /* localStorage bloqueado: se queda el tema oscuro */ }
    })();
  </script>
</head>
<body>

<a class="salto-contenido" href="#contenido">Saltar al contenido</a>

<header class="barra">
  <div class="contenedor barra__inner">

    <a class="marca" href="<?= $base ?>index.php">
      <span class="marca__logo" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="8" width="20" height="11" rx="5"/>
          <path d="M7 13h2.5M8.25 11.75v2.5"/>
          <circle cx="16" cy="12.5" r="1.1" fill="#fff" stroke="none"/>
          <circle cx="18.5" cy="14.8" r="1.1" fill="#fff" stroke="none"/>
          <path d="M9 5h6" stroke-width="2.2"/>
        </svg>
      </span>
      <span>Retro<em>Cloud</em></span>
    </a>

    <button type="button" class="barra__menu" id="btn-menu"
            aria-label="Abrir menu de navegacion"
            aria-expanded="false" aria-controls="nav-principal">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <path d="M3 6h18M3 12h18M3 18h18"/>
      </svg>
    </button>

    <nav class="barra__nav" id="nav-principal" aria-label="Navegacion principal">

      <a class="barra__link" href="<?= $base ?>index.php"<?= $activo('catalogo') ?>>
        Catalogo
      </a>

      <?php if (Auth::autenticado()): ?>
        <a class="barra__link" href="<?= $base ?>perfil.php"<?= $activo('perfil') ?>>
          Mis partidas
        </a>

        <?php if (Auth::esAdmin()): ?>
          <a class="barra__link" href="<?= $base ?>admin/index.php"<?= $activo('admin') ?>>
            Panel
          </a>
        <?php endif; ?>

        <span class="barra__link text-debil" style="pointer-events:none">
          <?= $e(Auth::nombre()) ?>
          <?php if (Auth::esAdmin()): ?>
            <span class="insignia insignia--exito">admin</span>
          <?php endif; ?>
        </span>

        <button type="button" class="btn btn--fantasma btn--sm" id="btn-logout">
          Salir
        </button>

      <?php else: ?>
        <a class="barra__link" href="<?= $base ?>login.php"<?= $activo('login') ?>>
          Entrar
        </a>
        <a class="btn btn--primario btn--sm" href="<?= $base ?>registro.php">
          Crear cuenta
        </a>
      <?php endif; ?>

      <button type="button" class="btn btn--fantasma btn--icono" id="btn-tema"
              aria-label="Cambiar tema"></button>
    </nav>

  </div>
</header>

<main class="principal" id="contenido">
  <div class="contenedor">
