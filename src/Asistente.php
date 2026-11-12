<?php
/**
 * RetroCloud · Asistente de catalogo
 * =====================================================================
 *
 * El ayudante flotante que pregunta "¿necesitas ayuda para escoger un
 * juego?". Entiende lo que escribe la persona, lo traduce a filtros y
 * se apoya en el motor de recomendaciones para ordenar el resultado.
 *
 * ---------------------------------------------------------------------
 * QUE ES Y QUE NO ES
 * ---------------------------------------------------------------------
 * NO es un modelo de lenguaje. No hay ninguna llamada a un servicio
 * externo, ninguna clave de API y ningun coste por consulta. Es un
 * clasificador de intenciones por reglas: normaliza el texto, lo
 * compara contra un vocabulario y decide que quiere la persona.
 *
 * Esa decision es deliberada y tiene ventajas reales frente a un modelo
 * de lenguaje para este trabajo concreto:
 *
 *   · Es DETERMINISTA. La misma frase da siempre la misma respuesta,
 *     asi que se puede probar automaticamente. Un modelo de lenguaje
 *     no se puede probar asi.
 *   · No se INVENTA juegos. Solo puede contestar con filas que existen
 *     de verdad en la base de datos, de modo que el problema de las
 *     alucinaciones no existe.
 *   · Responde en milisegundos y funciona sin internet.
 *   · Se puede AUDITAR: cada respuesta se explica mirando que regla
 *     disparo, cosa que con una red neuronal no es posible.
 *
 * La contrapartida honesta es que no mantiene una conversacion abierta:
 * entiende lo que esta en su vocabulario y poco mas. Para escoger un
 * juego de un catalogo de quince, eso sobra.
 *
 * ---------------------------------------------------------------------
 * COMO ENTIENDE
 * ---------------------------------------------------------------------
 *   1. Normaliza   · minusculas, sin acentos, sin signos
 *   2. Reconoce    · generos, consolas, decadas, numero de jugadores,
 *                    titulos concretos e intenciones sueltas
 *   3. Acumula     · los filtros se van sumando entre turnos, asi que
 *                    "de peleas" + "de neo geo" da la interseccion
 *   4. Consulta    · Recomendador::filtrar() aplica y ordena
 *   5. Responde    · texto + botones con lo que tenga sentido ahora
 *
 * El vocabulario de consolas y generos se lee de la BASE DE DATOS, no
 * esta escrito aqui: si manana se publica un juego de una consola nueva,
 * el asistente la entiende sin tocar este archivo. Lo unico fijo es la
 * tabla de sinonimos, que es lo que no se puede deducir.
 */

declare(strict_types=1);

namespace RetroCloud;

final class Asistente
{
    /**
     * Sinonimos por slug de consola.
     *
     * Se escriben ya normalizados (sin acentos, en minusculas) porque
     * el texto de entrada llega asi. Se comparan de mas largo a mas
     * corto, para que "super nintendo" gane a "nintendo".
     *
     * @var array<string, list<string>>
     */
    private const CONSOLAS = [
        'psx'    => ['playstation 1', 'play station', 'playstation', 'psone', 'ps one',
                     'ps1', 'psx', 'sony', 'la play', 'play'],
        'snes'   => ['super nintendo', 'supernintendo', 'super nes', 'snes'],
        'nes'    => ['nintendo entertainment', 'famicom', 'nintendito', 'nes'],
        'gba'    => ['game boy advance', 'gameboy advance', 'advance', 'gba'],
        'gb'     => ['game boy color', 'gameboy color', 'game boy', 'gameboy', 'gbc', 'gb'],
        'neogeo' => ['neo geo', 'neogeo', 'snk'],
        'n64'    => ['nintendo 64', 'nintendo64', 'n64'],
        'nds'    => ['nintendo ds', 'nds'],
        'psp'    => ['psp'],
        'segamd' => ['mega drive', 'megadrive', 'genesis', 'sega'],
        'segams' => ['master system'],
        'arcade' => ['maquinita', 'maquinitas', 'arcade', 'fbneo'],
        'vb'     => ['virtual boy'],
        'a2600'  => ['atari 2600', 'atari'],
    ];

    /**
     * Sinonimos por slug de genero.
     *
     * @var array<string, list<string>>
     */
    private const GENEROS = [
        'disparos'     => ['disparos', 'disparar', 'shooter', 'shoot', 'naves',
                           'balas', 'bala', 'pistolas', 'tiros'],
        'peleas'       => ['peleas', 'pelea', 'pelear', 'lucha', 'luchas', 'luchar',
                           'golpes', 'fighting', 'combate', 'versus'],
        'carreras'     => ['carreras', 'carrera', 'correr', 'coches', 'carros',
                           'autos', 'racing', 'velocidad', 'karts'],
        'aventura'     => ['aventuras', 'aventura', 'plataformas', 'plataforma',
                           'explorar', 'historia'],
        'accion'       => ['accion', 'acciones'],
        'estrategia'   => ['estrategia', 'estrategias', 'tactica', 'pensar'],
        'rompecabezas' => ['rompecabezas', 'puzzle', 'puzle', 'logica'],
        'deportes'     => ['deportes', 'deporte', 'futbol', 'basquet'],
        'rpg'          => ['rpg', 'rol'],
    ];

    /** @var array<string, int> */
    private const DECADAS = [
        'ochentas' => 1980, 'ochenta' => 1980, '80s' => 1980, '80' => 1980,
        'noventas' => 1990, 'noventa' => 1990, '90s' => 1990, '90' => 1990,
        'dos mil' => 2000, '2000' => 2000,
    ];

    private function __construct() {}

    // =================================================================
    // ENTRADA PRINCIPAL
    // =================================================================

    /**
     * @param array<string,mixed> $filtros filtros acumulados en turnos previos
     * @return array<string,mixed>
     */
    public static function responder(?int $usuarioId, string $mensaje, array $filtros): array
    {
        $texto = self::normalizar($mensaje);

        // --- Intenciones que cortan antes de filtrar -----------------
        if ($texto === '' || self::contiene($texto, ['hola', 'buenas', 'hey', 'que tal',
                'buenos dias', 'buenas tardes', 'buenas noches'])) {
            return self::saludo($usuarioId, $filtros);
        }

        if (self::contiene($texto, ['gracias', 'grax', 'thanks', 'muchas gracias'])) {
            return self::mensaje(
                'A mandar. Si quieres otra cosa, dime el genero o la consola y te busco.',
                self::opcionesBase(),
                [],
                $filtros
            );
        }

        if (self::contiene($texto, ['empezar de nuevo', 'reiniciar', 'de cero',
                'limpiar', 'otra cosa', 'cambiar de tema'])) {
            return self::mensaje(
                'Listo, borre los filtros. ¿Que buscamos?',
                self::opcionesBase(),
                [],
                []
            );
        }

        // --- Reconocimiento ------------------------------------------
        $nuevos = self::reconocer($texto);

        /* "Sorprendeme" tira del motor de recomendaciones completo, que
           es otra cosa que filtrar: ahi decide el modelo, no la persona. */
        if (self::contiene($texto, ['sorprendeme', 'sorprender', 'lo que sea',
                'cualquiera', 'me da igual', 'aleatorio', 'random', 'tu elige',
                'elige tu', 'no se que', 'no se cual'])) {
            return self::sorpresa($usuarioId, $filtros);
        }

        // Busqueda por titulo: si nombra un juego concreto, gana a todo.
        $porTitulo = self::buscarPorTitulo($texto, $filtros);
        if ($porTitulo !== null) {
            return $porTitulo;
        }

        if ($nuevos === []) {
            return self::noEntendi($filtros);
        }

        // Los filtros se acumulan entre turnos.
        $filtros = self::fusionar($filtros, $nuevos);

        return self::buscar($usuarioId, $filtros);
    }

    // =================================================================
    // RECONOCIMIENTO
    // =================================================================

    /**
     * Quita acentos, signos y dobles espacios.
     *
     * Normalizar es lo que permite que "Acción", "accion" y "ACCION!"
     * sean la misma palabra sin escribir tres reglas.
     */
    private static function normalizar(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = strtr($t, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i',
            'ò' => 'o', 'ù' => 'u',
        ]);
        $t = preg_replace('/[^a-z0-9 ]+/', ' ', $t) ?? '';
        $t = preg_replace('/\s+/', ' ', $t) ?? '';
        return trim($t);
    }

    /** @param list<string> $agujas */
    private static function contiene(string $texto, array $agujas): bool
    {
        foreach ($agujas as $a) {
            if (str_contains($texto, $a)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extrae todos los criterios que aparezcan en la frase.
     *
     * @return array<string,mixed>
     */
    private static function reconocer(string $texto): array
    {
        $f = [];

        // --- Consola ---------------------------------------------------
        // De mas largo a mas corto: "super nintendo" antes que "nintendo".
        foreach (self::CONSOLAS as $slug => $sinonimos) {
            foreach ($sinonimos as $s) {
                if (str_contains($texto, $s)) {
                    $f['consola'] = $slug;
                    break 2;
                }
            }
        }

        // --- Genero ----------------------------------------------------
        foreach (self::GENEROS as $slug => $sinonimos) {
            foreach ($sinonimos as $s) {
                if (str_contains($texto, $s)) {
                    $f['genero'] = $slug;
                    break 2;
                }
            }
        }

        // --- Numero de jugadores ---------------------------------------
        if (self::contiene($texto, ['dos jugadores', '2 jugadores', 'multijugador',
                'con un amigo', 'con mi amigo', 'con alguien', 'acompanado',
                'companero', 'con mi hermano', 'de a dos'])) {
            $f['jugadores'] = 2;
        }
        if (self::contiene($texto, ['solo', 'yo solo', 'un jugador', '1 jugador'])) {
            unset($f['jugadores']);
        }

        // --- Decada ----------------------------------------------------
        /* Ojo con el casteo: PHP convierte las claves numericas de un
           array en enteros, asi que '80' y '2000' llegan aqui como int
           y str_contains() los rechaza. De ahi el (string). */
        foreach (self::DECADAS as $palabra => $decada) {
            if (str_contains($texto, (string) $palabra)) {
                $f['decada'] = $decada;
                break;
            }
        }

        return $f;
    }

    /**
     * Fusiona criterios nuevos sobre los acumulados.
     *
     * Solo se conservan las claves conocidas: los filtros viajan al
     * cliente y vuelven, asi que son entrada del usuario y no se pueden
     * dar por buenos. Sin este filtrado, cualquiera podria inyectar
     * claves arbitrarias en la consulta.
     *
     * @param array<string,mixed> $viejos
     * @param array<string,mixed> $nuevos
     * @return array<string,mixed>
     */
    private static function fusionar(array $viejos, array $nuevos): array
    {
        $limpio = [];

        foreach (['consola', 'genero'] as $clave) {
            $v = $nuevos[$clave] ?? $viejos[$clave] ?? null;
            if (is_string($v) && $v !== '') {
                $limpio[$clave] = mb_substr($v, 0, 40);
            }
        }
        foreach (['decada', 'jugadores'] as $clave) {
            $v = $nuevos[$clave] ?? $viejos[$clave] ?? null;
            if (is_numeric($v)) {
                $limpio[$clave] = (int) $v;
            }
        }

        return $limpio;
    }

    // =================================================================
    // RESPUESTAS
    // =================================================================

    /** @return array<string,mixed> */
    private static function saludo(?int $usuarioId, array $filtros): array
    {
        $nombre = $usuarioId !== null ? Auth::nombre() : null;
        $hola = $nombre !== null ? "Hola, $nombre." : 'Hola.';

        return self::mensaje(
            $hola . ' Dime que te apetece y te busco en el catalogo: un genero, '
            . 'una consola, o las dos cosas.',
            self::opcionesBase(),
            [],
            $filtros
        );
    }

    /**
     * Ejecuta la busqueda y redacta la respuesta segun lo que salga.
     *
     * @param array<string,mixed> $filtros
     * @return array<string,mixed>
     */
    private static function buscar(?int $usuarioId, array $filtros): array
    {
        /** @var array{genero?:string, consola?:string, decada?:int, jugadores?:int} $filtros */
        $r = Recomendador::filtrar($usuarioId, $filtros, 4);
        $desc = self::describir($filtros);

        // --- Sin resultados -------------------------------------------
        if ($r['total'] === 0) {
            /* Aflojar el filtro mas reciente en lugar de rendirse: si no
               hay peleas de Game Boy, al menos enseñar las peleas que si
               hay. Un buscador que solo sabe decir "no hay nada" obliga
               a la persona a adivinar que combinaciones existen. */
            if (count($filtros) > 1) {
                $suelto = $filtros;
                unset($suelto['consola']);
                $alt = Recomendador::filtrar($usuarioId, $suelto, 4);

                if ($alt['total'] > 0) {
                    return self::mensaje(
                        'No tengo ' . $desc . ' en el catalogo. Pero si tengo '
                        . self::describir($suelto) . ', por si te sirve:',
                        self::opcionesTras($suelto),
                        $alt['juegos'],
                        $suelto
                    );
                }
            }

            return self::mensaje(
                'No encontre ' . $desc . '. El catalogo tiene 15 juegos, asi que '
                . 'hay huecos. ¿Probamos otra cosa?',
                self::opcionesBase(),
                [],
                []
            );
        }

        // --- Con resultados -------------------------------------------
        $cuantos = $r['total'] === 1
            ? 'Tengo ' . self::describir($filtros, true)
            : 'Tengo ' . $r['total'] . ' ' . $desc;

        $cola = $r['personalizado']
            ? ' Los ordene segun lo que sueles jugar.'
            : '';

        $mostrados = count($r['juegos']);
        $resto = $r['total'] > $mostrados
            ? ' Te enseño ' . $mostrados . '.'
            : '';

        return self::mensaje(
            $cuantos . '.' . $resto . $cola,
            self::opcionesTras($filtros),
            $r['juegos'],
            $filtros
        );
    }

    /**
     * "Sorprendeme": aqui no filtra la persona, decide el modelo.
     *
     * @return array<string,mixed>
     */
    private static function sorpresa(?int $usuarioId, array $filtros): array
    {
        $r = Recomendador::para($usuarioId, 3);

        if ($r['recomendaciones'] === []) {
            return self::mensaje(
                'No tengo nada que sugerirte ahora mismo. Dime un genero y lo busco.',
                self::opcionesBase(),
                [],
                $filtros
            );
        }

        $texto = match ($r['diagnostico']['estrategia']) {
            'popularidad' => 'Sin saber lo que te gusta solo puedo ir por lo mas jugado. '
                . 'Inicia sesion y juega un rato, y empiezo a afinar:',
            'retomar' => 'Ya jugaste todo lo publicado, asi que te digo que conviene retomar:',
            default => 'Esto es lo que el motor cree que te pega, segun tu historial:',
        };

        return self::mensaje($texto, self::opcionesBase(), $r['recomendaciones'], $filtros);
    }

    /**
     * Busqueda por titulo.
     *
     * Solo entra si la frase tiene algo que parece un nombre propio: sin
     * ese corte, "quiero algo de accion" encontraria cualquier juego con
     * la palabra "a" dentro.
     *
     * @return array<string,mixed>|null
     */
    private static function buscarPorTitulo(string $texto, array $filtros): ?array
    {
        $palabras = array_filter(
            explode(' ', $texto),
            static fn(string $p): bool => mb_strlen($p) >= 4
        );
        if ($palabras === []) {
            return null;
        }

        $juegos = Database::filas(
            'SELECT j.id, j.titulo, j.slug, j.portada, j.anio,
                    c.nombre AS consola, g.nombre AS genero
               FROM juegos j
               JOIN consolas c ON c.id = j.consola_id
          LEFT JOIN generos  g ON g.id = j.genero_id
              WHERE j.publicado = 1'
        );

        $encontrados = [];
        foreach ($juegos as $j) {
            $titulo = self::normalizar((string) $j['titulo']);
            foreach ($palabras as $p) {
                if (str_contains($titulo, $p)) {
                    $encontrados[(int) $j['id']] = $j;
                    break;
                }
            }
        }

        if ($encontrados === []) {
            return null;
        }

        $lista = [];
        foreach (array_slice($encontrados, 0, 4) as $j) {
            $lista[] = [
                'id'       => (int) $j['id'],
                'titulo'   => (string) $j['titulo'],
                'slug'     => (string) $j['slug'],
                'portada'  => $j['portada'],
                'consola'  => (string) $j['consola'],
                'genero'   => $j['genero'],
                'anio'     => $j['anio'] !== null ? (int) $j['anio'] : null,
                'afinidad' => null,
                'porque'   => 'Coincide con lo que escribiste.',
            ];
        }

        return self::mensaje(
            count($lista) === 1
                ? 'Lo encontre:'
                : 'Encontre ' . count($lista) . ' que coinciden:',
            self::opcionesBase(),
            $lista,
            $filtros
        );
    }

    /** @return array<string,mixed> */
    private static function noEntendi(array $filtros): array
    {
        return self::mensaje(
            'No te segui. Entiendo generos (peleas, aventura, disparos, carreras), '
            . 'consolas (PlayStation, Neo Geo, Game Boy...), decadas y titulos '
            . 'concretos. Tambien puedes pedirme que te sorprenda.',
            self::opcionesBase(),
            [],
            $filtros
        );
    }

    // =================================================================
    // Redaccion y botones
    // =================================================================

    /**
     * Convierte los filtros en lenguaje natural.
     *
     * @param array<string,mixed> $f
     * @param bool $singular true para redactar en singular ("un juego de
     *        peleas") en lugar de en plural. Sin esto salia "Tengo uno
     *        juegos de peleas".
     */
    private static function describir(array $f, bool $singular = false): string
    {
        $partes = [];
        $nucleo = $singular ? 'un juego' : 'juegos';

        if (isset($f['genero'])) {
            $genero = match ($f['genero']) {
                'peleas'       => 'peleas',
                'disparos'     => 'disparos',
                'carreras'     => 'carreras',
                'aventura'     => 'aventura',
                'accion'       => 'accion',
                'estrategia'   => 'estrategia',
                'rompecabezas' => 'rompecabezas',
                'deportes'     => 'deportes',
                default        => (string) $f['genero'],
            };
            $partes[] = $nucleo . ' de ' . $genero;
        } else {
            $partes[] = $nucleo;
        }

        if (isset($f['consola'])) {
            $nombre = Database::valor(
                'SELECT nombre FROM consolas WHERE slug = :s',
                ['s' => $f['consola']]
            );
            if ($nombre !== null && $nombre !== false) {
                $partes[] = 'de ' . $nombre;
            }
        }
        if (isset($f['decada'])) {
            $partes[] = 'de los ' . $f['decada'];
        }
        if (isset($f['jugadores']) && (int) $f['jugadores'] >= 2) {
            $partes[] = 'para dos o mas';
        }

        return implode(' ', $partes);
    }

    /**
     * Botones de arranque, construidos desde la BASE DE DATOS.
     *
     * Solo se ofrece lo que de verdad tiene juegos publicados: un boton
     * que lleva a cero resultados es una promesa rota, y ademas se
     * adapta solo segun crece el catalogo.
     *
     * @return list<array{etiqueta:string, enviar:string}>
     */
    private static function opcionesBase(): array
    {
        $generos = Database::filas(
            'SELECT g.nombre, COUNT(j.id) AS n
               FROM generos g
               JOIN juegos j ON j.genero_id = g.id AND j.publicado = 1
              GROUP BY g.id, g.nombre
              HAVING n > 0
              ORDER BY n DESC
              LIMIT 4'
        );

        $opciones = [];
        foreach ($generos as $g) {
            $opciones[] = [
                'etiqueta' => (string) $g['nombre'],
                'enviar'   => 'juegos de ' . mb_strtolower((string) $g['nombre']),
            ];
        }

        $opciones[] = ['etiqueta' => 'Para dos jugadores', 'enviar' => 'para dos jugadores'];
        $opciones[] = ['etiqueta' => 'Sorprendeme',        'enviar' => 'sorprendeme'];

        return $opciones;
    }

    /**
     * Botones para refinar lo que ya se pidio.
     *
     * @param array<string,mixed> $f
     * @return list<array{etiqueta:string, enviar:string}>
     */
    private static function opcionesTras(array $f): array
    {
        $opciones = [];

        /* Si todavia no eligio consola, ofrecer solo las que tienen
           juegos DENTRO del filtro actual. Asi ningun boton lleva a
           una pantalla vacia. */
        if (!isset($f['consola'])) {
            $sql = 'SELECT c.nombre, c.slug, COUNT(j.id) AS n
                      FROM consolas c
                      JOIN juegos j ON j.consola_id = c.id AND j.publicado = 1';
            $params = [];

            if (isset($f['genero'])) {
                $sql .= ' JOIN generos g ON g.id = j.genero_id AND g.slug = :gen';
                $params['gen'] = $f['genero'];
            }
            $sql .= ' GROUP BY c.id, c.nombre, c.slug HAVING n > 0 ORDER BY n DESC LIMIT 3';

            foreach (Database::filas($sql, $params) as $c) {
                $opciones[] = [
                    'etiqueta' => (string) $c['nombre'],
                    'enviar'   => 'de ' . (string) $c['nombre'],
                ];
            }
        }

        if (!isset($f['jugadores'])) {
            $opciones[] = ['etiqueta' => 'Para dos jugadores', 'enviar' => 'para dos jugadores'];
        }

        $opciones[] = ['etiqueta' => 'Empezar de nuevo', 'enviar' => 'empezar de nuevo'];

        return $opciones;
    }

    /**
     * @param list<array{etiqueta:string, enviar:string}> $opciones
     * @param list<array<string,mixed>> $juegos
     * @param array<string,mixed>|null $filtros estado autoritativo de los
     *        filtros tras este turno. El servidor SIEMPRE lo devuelve, y
     *        el cliente lo reemplaza entero: si cada respuesta no dijera
     *        como queda el estado, una frase que el asistente no entiende
     *        borraria sin querer lo que la persona ya habia pedido.
     * @return array<string,mixed>
     */
    private static function mensaje(
        string $texto,
        array $opciones = [],
        array $juegos = [],
        ?array $filtros = null
    ): array {
        return [
            'respuesta' => $texto,
            'opciones'  => $opciones,
            'juegos'    => $juegos,
            'filtros'   => $filtros ?? [],
        ];
    }
}
