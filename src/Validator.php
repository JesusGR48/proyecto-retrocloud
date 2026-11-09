<?php
/**
 * RetroCloud · Validacion y saneado de entrada
 * ---------------------------------------------------------------------
 * Regla de oro del proyecto: ningun dato que venga del cliente se usa
 * sin pasar por aqui. El navegador tambien valida (atributos required,
 * pattern, minlength), pero esa validacion es solo comodidad para el
 * usuario: se puede desactivar desde las herramientas de desarrollador.
 * La validacion que de verdad protege al sistema es esta, la del servidor.
 */

declare(strict_types=1);

namespace RetroCloud;

final class Validator
{
    /** @var array<string, string> */
    private array $errores = [];

    /** @var array<string, mixed> */
    private array $datos;

    /** @param array<string, mixed> $datos */
    public function __construct(array $datos)
    {
        $this->datos = $datos;
    }

    /** @param array<string, mixed> $datos */
    public static function de(array $datos): self
    {
        return new self($datos);
    }

    // -----------------------------------------------------------------
    // Lectura
    // -----------------------------------------------------------------

    /** Valor crudo recortado, o null si no vino. */
    public function crudo(string $campo): ?string
    {
        $v = $this->datos[$campo] ?? null;
        if (is_array($v) || is_object($v)) {
            return null;
        }
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    // -----------------------------------------------------------------
    // Reglas
    // -----------------------------------------------------------------

    public function requerido(string $campo, string $etiqueta): self
    {
        if ($this->crudo($campo) === null) {
            $this->fallo($campo, "El campo $etiqueta es obligatorio.");
        }
        return $this;
    }

    public function longitud(string $campo, string $etiqueta, int $min, int $max): self
    {
        $v = $this->crudo($campo);
        if ($v === null) {
            return $this;
        }
        $n = mb_strlen($v);
        if ($n < $min) {
            $this->fallo($campo, "$etiqueta debe tener al menos $min caracteres.");
        } elseif ($n > $max) {
            $this->fallo($campo, "$etiqueta no puede pasar de $max caracteres.");
        }
        return $this;
    }

    public function correo(string $campo, string $etiqueta = 'El correo'): self
    {
        $v = $this->crudo($campo);
        if ($v === null) {
            return $this;
        }
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->fallo($campo, "$etiqueta no tiene un formato valido.");
        } elseif (mb_strlen($v) > 190) {
            $this->fallo($campo, "$etiqueta es demasiado largo.");
        }
        return $this;
    }

    /** Solo letras, numeros, guion y guion bajo: evita nombres raros. */
    public function nombreUsuario(string $campo): self
    {
        $v = $this->crudo($campo);
        if ($v === null) {
            return $this;
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $v)) {
            $this->fallo(
                $campo,
                'El usuario solo admite letras, numeros, guion y guion bajo (3 a 32 caracteres).'
            );
        }
        return $this;
    }

    /**
     * Exige longitud minima y algo de variedad. No pedimos simbolos
     * obligatorios: la evidencia dice que largo > complicado.
     */
    public function password(string $campo, int $min = PASSWORD_MIN): self
    {
        $v = $this->datos[$campo] ?? null;
        $v = is_string($v) ? $v : '';

        if (mb_strlen($v) < $min) {
            $this->fallo($campo, "La contrasena debe tener al menos $min caracteres.");
            return $this;
        }
        if (mb_strlen($v) > 200) {
            $this->fallo($campo, 'La contrasena es excesivamente larga.');
            return $this;
        }
        if (!preg_match('/[a-zA-Z]/', $v) || !preg_match('/[0-9]/', $v)) {
            $this->fallo($campo, 'La contrasena debe combinar letras y numeros.');
        }
        return $this;
    }

    public function coincide(string $campo, string $contra, string $etiqueta): self
    {
        $a = $this->datos[$campo] ?? null;
        $b = $this->datos[$contra] ?? null;
        if ($a !== $b) {
            $this->fallo($campo, "$etiqueta no coincide.");
        }
        return $this;
    }

    public function entero(string $campo, string $etiqueta, ?int $min = null, ?int $max = null): self
    {
        $v = $this->crudo($campo);
        if ($v === null) {
            return $this;
        }
        if (filter_var($v, FILTER_VALIDATE_INT) === false) {
            $this->fallo($campo, "$etiqueta debe ser un numero entero.");
            return $this;
        }
        $n = (int) $v;
        if ($min !== null && $n < $min) {
            $this->fallo($campo, "$etiqueta no puede ser menor que $min.");
        }
        if ($max !== null && $n > $max) {
            $this->fallo($campo, "$etiqueta no puede ser mayor que $max.");
        }
        return $this;
    }

    /** @param list<string> $permitidos */
    public function enLista(string $campo, string $etiqueta, array $permitidos): self
    {
        $v = $this->crudo($campo);
        if ($v === null) {
            return $this;
        }
        if (!in_array($v, $permitidos, true)) {
            $this->fallo($campo, "$etiqueta tiene un valor no permitido.");
        }
        return $this;
    }

    public function anio(string $campo, string $etiqueta = 'El anio'): self
    {
        return $this->entero($campo, $etiqueta, 1950, (int) date('Y') + 1);
    }

    // -----------------------------------------------------------------
    // Resultado
    // -----------------------------------------------------------------

    public function fallo(string $campo, string $mensaje): self
    {
        // Solo el primer error por campo: no abrumamos al usuario.
        $this->errores[$campo] ??= $mensaje;
        return $this;
    }

    public function haFallado(): bool
    {
        return $this->errores !== [];
    }

    /** @return array<string, string> */
    public function errores(): array
    {
        return $this->errores;
    }

    public function primerError(): ?string
    {
        foreach ($this->errores as $mensaje) {
            return $mensaje;
        }
        return null;
    }

    /** Si hubo errores responde 422 con el detalle por campo y corta. */
    public function oResponder(): void
    {
        if ($this->haFallado()) {
            Response::error(
                $this->primerError() ?? 'Datos invalidos.',
                422,
                $this->errores
            );
        }
    }

    // -----------------------------------------------------------------
    // Utilidades estaticas
    // -----------------------------------------------------------------

    /**
     * Escapa texto para interpolarlo en HTML.
     * Esta es la defensa contra XSS: convierte < > " & en entidades, de
     * modo que un <script> enviado por un usuario se muestre como texto
     * en lugar de ejecutarse.
     */
    public static function e(mixed $texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Convierte un titulo en slug apto para URL.
     *   "Pac-Man: Edicion Deluxe!"  ->  "pac-man-edicion-deluxe"
     */
    public static function slug(string $texto, int $max = 160): string
    {
        $s = $texto;
        // Quita acentos: a con tilde -> a
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
            if ($conv !== false) {
                $s = $conv;
            }
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        $s = substr($s, 0, $max);
        return $s === '' ? 'juego-' . bin2hex(random_bytes(4)) : $s;
    }

    /** Formatea bytes a una cadena legible: 1536 -> "1.5 KB". */
    public static function bytesLegibles(int|float $bytes): string
    {
        $unidades = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($unidades) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $n : number_format($n, 1)) . ' ' . $unidades[$i];
    }

    /** Formatea segundos a "2 h 15 min". */
    public static function tiempoLegible(int $segundos): string
    {
        if ($segundos < 60) {
            return $segundos . ' s';
        }
        $h = intdiv($segundos, 3600);
        $m = intdiv($segundos % 3600, 60);
        if ($h === 0) {
            return $m . ' min';
        }
        return $m === 0 ? $h . ' h' : "$h h $m min";
    }
}
