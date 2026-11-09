<?php
/**
 * RetroCloud · Almacenamiento de archivos binarios
 * ---------------------------------------------------------------------
 * ROMs, partidas guardadas y capturas viven en /storage, que esta FUERA
 * del document root. Apache no puede servirlos directamente ni por URL
 * adivinada: el unico camino es un script PHP que antes valida la sesion.
 *
 * Reglas aplicadas a toda subida:
 *   1. Se revisa el codigo de error de PHP antes que nada.
 *   2. Se comprueba el tamano real del archivo en disco, no el que el
 *      cliente declara en el formulario (es falsificable).
 *   3. El nombre original del usuario se DESCARTA. Se genera uno interno
 *      a partir de bytes aleatorios, de modo que no hay forma de colar
 *      rutas como "../../index.php" ni nombres como "shell.php".
 *   4. La extension debe estar en una lista blanca.
 *   5. Se calcula SHA-256 para verificar integridad y detectar duplicados.
 */

declare(strict_types=1);

namespace RetroCloud;

use RuntimeException;

final class Storage
{
    private function __construct() {}

    // -----------------------------------------------------------------
    // Preparacion
    // -----------------------------------------------------------------

    /** Crea las carpetas de storage y las blinda con un .htaccess. */
    public static function preparar(): void
    {
        foreach ([RUTA_ROMS, RUTA_SAVES, RUTA_CAPTURAS, RUTA_PORTADAS, RUTA_LOGS,
                  RUTA_SESIONES, RUTA_BIOS,
                  RUTA_STORAGE . DIRECTORY_SEPARATOR . 'tmp'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        // Cinturon y tirantes: si alguien moviera /storage dentro del
        // document root, este .htaccess seguiria negando el acceso.
        $guardia = RUTA_STORAGE . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($guardia)) {
            @file_put_contents(
                $guardia,
                "# Acceso directo denegado: estos archivos solo se sirven via PHP.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
                . "php_flag engine off\n"
            );
        }
    }

    // -----------------------------------------------------------------
    // Subida de ROMs
    // -----------------------------------------------------------------

    /**
     * Guarda una ROM subida por el panel admin.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $archivo
     * @param list<string> $extensionesPermitidas
     * @return array{nombre:string,hash:string,bytes:int,extension:string,original:string}
     */
    public static function guardarRom(array $archivo, array $extensionesPermitidas): array
    {
        self::preparar();
        self::verificarError($archivo);

        $bytes = self::tamanoReal($archivo['tmp_name']);
        if ($bytes === 0) {
            throw new RuntimeException('El archivo de la ROM llego vacio.');
        }
        if ($bytes > MAX_BYTES_ROM) {
            throw new RuntimeException(sprintf(
                'La ROM pesa %s y el limite es %s.',
                Validator::bytesLegibles($bytes),
                Validator::bytesLegibles(MAX_BYTES_ROM)
            ));
        }

        $ext = self::extensionSegura($archivo['name']);
        $permitidas = array_map('strtolower', array_filter(array_map('trim', $extensionesPermitidas)));

        if ($ext === '' || ($permitidas !== [] && !in_array($ext, $permitidas, true))) {
            throw new RuntimeException(sprintf(
                'Extension ".%s" no valida para esta consola. Se aceptan: %s.',
                $ext,
                implode(', ', $permitidas)
            ));
        }

        $hash = hash_file('sha256', $archivo['tmp_name']);
        if ($hash === false) {
            throw new RuntimeException('No se pudo calcular la firma SHA-256 de la ROM.');
        }

        // Nombre interno: nada del nombre que escribio el usuario sobrevive.
        $nombre  = bin2hex(random_bytes(16)) . '.' . $ext;
        $destino = RUTA_ROMS . DIRECTORY_SEPARATOR . $nombre;

        if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
            throw new RuntimeException('No se pudo mover la ROM a la carpeta de almacenamiento.');
        }
        @chmod($destino, 0644);

        /*
         * El nombre interno descarta por completo el que escribio el
         * usuario (defensa contra rutas y extensiones maliciosas), pero
         * el ORIGINAL se devuelve para guardarlo en la base de datos.
         *
         * Motivo: los nucleos de arcade identifican el romset por el
         * nombre del zip. FBNeo espera `kof2002.zip`; si recibe
         * `King_Of_Fighters_2002.zip` responde "Romset is unknown"
         * aunque el contenido sea correcto. Solo se usa como nombre de
         * descarga, nunca como ruta en disco.
         */
        $original = basename(str_replace('\\', '/', $archivo['name']));
        $original = preg_replace('/[^A-Za-z0-9._-]+/', '_', $original) ?: ('rom.' . $ext);

        return [
            'nombre'    => $nombre,
            'hash'      => $hash,
            'bytes'     => $bytes,
            'extension' => $ext,
            'original'  => mb_substr($original, 0, 255),
        ];
    }

    public static function borrarRom(?string $nombre): void
    {
        self::borrarEn(RUTA_ROMS, $nombre);
    }

    public static function rutaRom(string $nombre): ?string
    {
        return self::rutaSegura(RUTA_ROMS, $nombre);
    }

    // -----------------------------------------------------------------
    // BIOS de consola
    //
    // Algunas plataformas (Neo Geo, PlayStation, Sega CD, PSP) no
    // arrancan solo con el juego: el emulador necesita ademas la
    // firmware original del sistema. Se guarda una por consola, con el
    // mismo tratamiento que una ROM: nombre interno aleatorio, SHA-256
    // y almacenamiento fuera del document root.
    //
    // Diferencia importante: del BIOS SI se conserva el nombre original
    // en la base de datos, porque algunos nucleos lo buscan por nombre
    // exacto (por ejemplo `neogeo.zip`). El archivo en disco sigue
    // teniendo un nombre aleatorio; el original solo se usa al
    // entregarlo al navegador.
    // -----------------------------------------------------------------

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $archivo
     * @return array{nombre:string,hash:string,bytes:int,original:string}
     */
    public static function guardarBios(array $archivo): array
    {
        self::preparar();
        self::verificarError($archivo);

        $bytes = self::tamanoReal($archivo['tmp_name']);
        if ($bytes === 0) {
            throw new RuntimeException('El archivo de BIOS llego vacio.');
        }
        if ($bytes > MAX_BYTES_BIOS) {
            throw new RuntimeException(sprintf(
                'El BIOS pesa %s y el limite es %s.',
                Validator::bytesLegibles($bytes),
                Validator::bytesLegibles(MAX_BYTES_BIOS)
            ));
        }

        $ext = self::extensionSegura($archivo['name']);
        // Lista corta: una firmware es un volcado binario o un zip.
        $permitidas = ['zip', 'bin', 'rom', 'bios', 'img', 'pup'];
        if ($ext === '' || !in_array($ext, $permitidas, true)) {
            throw new RuntimeException(
                'Extension no valida para un BIOS. Se aceptan: ' . implode(', ', $permitidas) . '.'
            );
        }

        $hash = hash_file('sha256', $archivo['tmp_name']);
        if ($hash === false) {
            throw new RuntimeException('No se pudo calcular la firma del BIOS.');
        }

        $nombre  = 'bios_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $destino = RUTA_BIOS . DIRECTORY_SEPARATOR . $nombre;

        if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
            throw new RuntimeException('No se pudo guardar el BIOS en el servidor.');
        }
        @chmod($destino, 0644);

        // Nombre original saneado: es el que vera el emulador.
        $original = basename(str_replace('\\', '/', $archivo['name']));
        $original = preg_replace('/[^A-Za-z0-9._-]+/', '_', $original) ?: ('bios.' . $ext);

        return [
            'nombre'   => $nombre,
            'hash'     => $hash,
            'bytes'    => $bytes,
            'original' => mb_substr($original, 0, 255),
        ];
    }

    public static function rutaBios(string $nombre): ?string
    {
        return self::rutaSegura(RUTA_BIOS, $nombre);
    }

    public static function borrarBios(?string $nombre): void
    {
        self::borrarEn(RUTA_BIOS, $nombre);
    }

    // -----------------------------------------------------------------
    // Portadas (si van en /public porque no son sensibles)
    // -----------------------------------------------------------------

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $archivo
     */
    public static function guardarPortada(array $archivo): string
    {
        self::preparar();
        self::verificarError($archivo);

        $bytes = self::tamanoReal($archivo['tmp_name']);
        if ($bytes > MAX_BYTES_PORTADA) {
            throw new RuntimeException(sprintf(
                'La portada pesa %s y el limite es %s.',
                Validator::bytesLegibles($bytes),
                Validator::bytesLegibles(MAX_BYTES_PORTADA)
            ));
        }

        $ext = self::extensionSegura($archivo['name']);
        if (!in_array($ext, EXT_PORTADA, true)) {
            throw new RuntimeException(
                'La portada debe ser ' . implode(', ', EXT_PORTADA) . '.'
            );
        }

        // Verificacion por contenido, no por extension ni por el
        // Content-Type que manda el cliente (ambos son falsificables).
        // getimagesize() falla si el archivo no es una imagen real, asi
        // que un PHP renombrado a .png queda rechazado aqui.
        $info = @getimagesize($archivo['tmp_name']);
        if ($info === false) {
            throw new RuntimeException('Ese archivo no es una imagen valida.');
        }

        $tiposValidos = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
        if (!in_array($info[2], $tiposValidos, true)) {
            throw new RuntimeException('Formato de imagen no soportado.');
        }

        $nombre  = bin2hex(random_bytes(12)) . '.' . $ext;
        $destino = RUTA_PORTADAS . DIRECTORY_SEPARATOR . $nombre;

        if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
            throw new RuntimeException('No se pudo guardar la portada.');
        }
        @chmod($destino, 0644);

        return $nombre;
    }

    public static function borrarPortada(?string $nombre): void
    {
        self::borrarEn(RUTA_PORTADAS, $nombre);
    }

    // -----------------------------------------------------------------
    // Partidas guardadas (save states)
    // -----------------------------------------------------------------

    /**
     * Escribe el volcado binario que mando el emulador.
     * Llega por fetch() como Blob dentro de un FormData.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $archivo
     * @return array{nombre:string,hash:string,bytes:int}
     */
    public static function guardarSave(array $archivo, int $usuarioId, int $juegoId, int $slot): array
    {
        self::preparar();
        self::verificarError($archivo);

        $bytes = self::tamanoReal($archivo['tmp_name']);
        if ($bytes === 0) {
            throw new RuntimeException('La partida guardada llego vacia.');
        }
        if ($bytes > MAX_BYTES_SAVE) {
            throw new RuntimeException(sprintf(
                'La partida pesa %s y el limite es %s.',
                Validator::bytesLegibles($bytes),
                Validator::bytesLegibles(MAX_BYTES_SAVE)
            ));
        }

        $hash = hash_file('sha256', $archivo['tmp_name']);
        if ($hash === false) {
            throw new RuntimeException('No se pudo calcular la firma de la partida.');
        }

        // Nombre determinista: usuario-juego-slot. Al sobreescribir una
        // ranura no quedan archivos huerfanos acumulandose en disco.
        $nombre  = sprintf('u%d_j%d_s%d.state', $usuarioId, $juegoId, $slot);
        $destino = RUTA_SAVES . DIRECTORY_SEPARATOR . $nombre;

        if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
            throw new RuntimeException('No se pudo almacenar la partida en el servidor.');
        }
        @chmod($destino, 0644);

        return ['nombre' => $nombre, 'hash' => $hash, 'bytes' => $bytes];
    }

    public static function rutaSave(string $nombre): ?string
    {
        return self::rutaSegura(RUTA_SAVES, $nombre);
    }

    public static function borrarSave(?string $nombre): void
    {
        self::borrarEn(RUTA_SAVES, $nombre);
    }

    // -----------------------------------------------------------------
    // Capturas de pantalla de las partidas
    // -----------------------------------------------------------------

    /**
     * Decodifica el PNG en base64 que manda el canvas del emulador
     * (`canvas.toDataURL()`) y lo escribe como archivo.
     */
    public static function guardarCapturaBase64(
        string $dataUrl,
        int $usuarioId,
        int $juegoId,
        int $slot
    ): ?string {
        self::preparar();

        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            return null;
        }

        $binario = base64_decode($m[1], true);
        if ($binario === false || $binario === '') {
            return null;
        }
        if (strlen($binario) > MAX_BYTES_CAPTURA) {
            return null;
        }

        // Firma PNG: los 8 primeros bytes deben ser exactamente estos.
        if (substr($binario, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }

        $nombre = sprintf('u%d_j%d_s%d.png', $usuarioId, $juegoId, $slot);
        $ok = @file_put_contents(RUTA_CAPTURAS . DIRECTORY_SEPARATOR . $nombre, $binario);

        return $ok === false ? null : $nombre;
    }

    public static function rutaCaptura(string $nombre): ?string
    {
        return self::rutaSegura(RUTA_CAPTURAS, $nombre);
    }

    public static function borrarCaptura(?string $nombre): void
    {
        self::borrarEn(RUTA_CAPTURAS, $nombre);
    }

    // -----------------------------------------------------------------
    // Entrega de archivos al navegador
    // -----------------------------------------------------------------

    /**
     * Envia un archivo por streaming con soporte de peticiones Range.
     *
     * El Range importa de verdad aqui: algunos nucleos y el reproductor
     * de audio piden trozos del archivo en lugar de todo de golpe, y sin
     * esto Chrome aborta la descarga de ROMs grandes.
     */
    public static function enviarArchivo(
        string $ruta,
        string $nombreDescarga,
        string $mime = 'application/octet-stream',
        bool $descargar = false
    ): never {
        if (!is_file($ruta)) {
            http_response_code(404);
            exit('Archivo no encontrado.');
        }

        $tamano = (int) filesize($ruta);

        // Limpia cualquier buffer: un solo byte extra corrompe el binario.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        header(sprintf(
            'Content-Disposition: %s; filename="%s"',
            $descargar ? 'attachment' : 'inline',
            str_replace(['"', "\r", "\n"], '', $nombreDescarga)
        ));
        header('Cache-Control: private, max-age=86400');

        $inicio = 0;
        $fin    = $tamano - 1;
        $rango  = $_SERVER['HTTP_RANGE'] ?? null;

        if (is_string($rango) && preg_match('/bytes=(\d*)-(\d*)/', $rango, $m)) {
            if ($m[1] !== '') {
                $inicio = (int) $m[1];
            }
            if ($m[2] !== '') {
                $fin = (int) $m[2];
            }
            if ($inicio > $fin || $inicio >= $tamano) {
                http_response_code(416);
                header("Content-Range: bytes */$tamano");
                exit;
            }
            $fin = min($fin, $tamano - 1);
            http_response_code(206);
            header("Content-Range: bytes $inicio-$fin/$tamano");
        }

        header('Content-Length: ' . (string) ($fin - $inicio + 1));

        $fh = fopen($ruta, 'rb');
        if ($fh === false) {
            http_response_code(500);
            exit('No se pudo leer el archivo.');
        }

        fseek($fh, $inicio);
        $restante = $fin - $inicio + 1;
        $trozo    = 256 * 1024;   // 256 KB por iteracion

        while ($restante > 0 && !feof($fh) && !connection_aborted()) {
            $leer   = (int) min($trozo, $restante);
            $buffer = fread($fh, $leer);
            if ($buffer === false) {
                break;
            }
            echo $buffer;
            flush();
            $restante -= strlen($buffer);
        }

        fclose($fh);
        exit;
    }

    // -----------------------------------------------------------------
    // Internos
    // -----------------------------------------------------------------

    /** @param array{error:int,name:string} $archivo */
    private static function verificarError(array $archivo): void
    {
        $codigo = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($codigo === UPLOAD_ERR_OK) {
            // Confirma que el archivo venga del mecanismo de subida de PHP
            // y no sea una ruta arbitraria inyectada en la peticion.
            if (!is_uploaded_file($archivo['tmp_name'] ?? '')) {
                throw new RuntimeException('Subida invalida.');
            }
            return;
        }

        throw new RuntimeException(match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'El archivo excede el tamano maximo permitido por el servidor. '
                . 'Revisa upload_max_filesize y post_max_size en php.ini.',
            UPLOAD_ERR_PARTIAL   => 'La subida se interrumpio a medias. Intenta de nuevo.',
            UPLOAD_ERR_NO_FILE   => 'No se recibio ningun archivo.',
            UPLOAD_ERR_NO_TMP_DIR=> 'El servidor no tiene carpeta temporal configurada.',
            UPLOAD_ERR_CANT_WRITE=> 'El servidor no pudo escribir el archivo en disco.',
            UPLOAD_ERR_EXTENSION => 'Una extension de PHP bloqueo la subida.',
            default              => 'Error desconocido al subir el archivo.',
        });
    }

    private static function tamanoReal(string $tmp): int
    {
        $n = @filesize($tmp);
        return $n === false ? 0 : (int) $n;
    }

    /** Extrae la extension en minusculas, ignorando rutas y caracteres raros. */
    private static function extensionSegura(string $nombreOriginal): string
    {
        $base = basename(str_replace('\\', '/', $nombreOriginal));
        $ext  = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        return preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : '';
    }

    /**
     * Resuelve una ruta dentro de `$base` impidiendo escapar de ella.
     *
     * Esta es la defensa contra path traversal: realpath() normaliza
     * cualquier "../" y despues comprobamos que el resultado siga
     * empezando por la carpeta base. Si no, devolvemos null.
     */
    private static function rutaSegura(string $base, string $nombre): ?string
    {
        $nombre = basename(str_replace('\\', '/', $nombre));
        if ($nombre === '' || $nombre === '.' || $nombre === '..') {
            return null;
        }

        $completa = $base . DIRECTORY_SEPARATOR . $nombre;
        $real     = realpath($completa);
        $baseReal = realpath($base);

        if ($real === false || $baseReal === false) {
            return null;
        }
        if (!str_starts_with($real, $baseReal)) {
            return null;
        }

        return is_file($real) ? $real : null;
    }

    private static function borrarEn(string $base, ?string $nombre): void
    {
        if ($nombre === null || $nombre === '') {
            return;
        }
        $ruta = self::rutaSegura($base, $nombre);
        if ($ruta !== null) {
            @unlink($ruta);
        }
    }
}
