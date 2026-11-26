<?php
/**
 * RetroCloud · Restablecer la contrasena de una cuenta
 * =====================================================================
 *
 * Genera la sentencia SQL que cambia la contrasena de un usuario, para
 * pegarla en phpMyAdmin cuando se ha perdido el acceso al panel.
 *
 * Uso:
 *     php deploy/restablecer-password.php admin
 *
 * Pide la contrasena por teclado y NO la recibe como argumento a
 * proposito: un argumento queda guardado en el historial del terminal y
 * es visible para cualquier otro proceso de la maquina mientras el
 * comando corre. Leida por teclado no deja rastro.
 *
 * Lo que se imprime es el HASH, no la contrasena. Ese hash se puede
 * pegar sin miedo en un chat o un correo: de el no se puede recuperar
 * la contrasena original, y es exactamente lo que la base de datos
 * guarda. El texto en claro no sale de esta ventana.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Este script solo corre desde la terminal.\n");
}

$usuario = $argv[1] ?? 'admin';

if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $usuario)) {
    exit("Nombre de usuario invalido.\n");
}

echo "\n";
echo "===========================================================\n";
echo "  Restablecer contrasena de: $usuario\n";
echo "===========================================================\n\n";

/* Intento de ocultar lo que se teclea. En Windows no siempre se puede,
   asi que se avisa en lugar de fingir que esta oculto. */
$oculto = false;
if (DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
    @shell_exec('stty -echo 2>/dev/null');
    $oculto = true;
}

if (!$oculto) {
    echo "  Aviso: lo que escribas se vera en pantalla.\n";
    echo "  Asegurate de que nadie este mirando.\n\n";
}

echo "  Contrasena nueva: ";
$pass = trim((string) fgets(STDIN));

if ($oculto) {
    @shell_exec('stty echo 2>/dev/null');
    echo "\n";
}

// Mismas reglas que exige el registro del sitio.
if (mb_strlen($pass) < 8) {
    exit("\n  Demasiado corta: minimo 8 caracteres.\n\n");
}
if (!preg_match('/[a-zA-Z]/', $pass) || !preg_match('/[0-9]/', $pass)) {
    exit("\n  Debe combinar letras y numeros.\n\n");
}

$hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);

// La contrasena en claro deja de existir en memoria cuanto antes.
$pass = str_repeat("\0", 64);
unset($pass);

$sql = sprintf(
    "UPDATE usuarios SET hash_password = '%s' WHERE nombre_usuario = '%s';",
    $hash,
    $usuario
);

echo "\n";
echo "-----------------------------------------------------------\n";
echo "  Pega ESTO en phpMyAdmin, pestana SQL:\n";
echo "-----------------------------------------------------------\n\n";
echo $sql, "\n\n";
echo "-----------------------------------------------------------\n";
echo "  Donde: panel de AlwaysData -> Bases de datos -> phpMyAdmin\n";
echo "  Elige tu base (TUCUENTA_retrocloud) y la pestana SQL.\n";
echo "  Debe responder: 1 fila afectada.\n";
echo "-----------------------------------------------------------\n\n";
