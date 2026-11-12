<?php
/**
 * RetroCloud · Motor de recomendaciones
 * =====================================================================
 *
 * Sistema de recomendacion hibrido escrito desde cero para el proyecto:
 * no usa ningun servicio externo, ninguna clave de API y ninguna
 * libreria de terceros. Todo el calculo ocurre en este archivo, con los
 * datos que la propia plataforma ya genera.
 *
 * ---------------------------------------------------------------------
 * POR QUE ESTO CUENTA COMO INTELIGENCIA ARTIFICIAL
 * ---------------------------------------------------------------------
 * Un sistema de recomendacion es aprendizaje automatico clasico: el
 * programa no lleva escrito "a quien le gusta Mario le gustara Sonic".
 * Construye esa relacion solo, a partir del comportamiento observado, y
 * la revisa cada vez que alguien juega. Nadie programa las reglas: se
 * deducen de los datos.
 *
 * Es la misma familia de algoritmos con la que Netflix ordena su
 * portada y Spotify arma sus listas. Que no hable como una persona no
 * lo hace menos IA; de hecho lleva en produccion mucho mas tiempo que
 * los modelos de lenguaje.
 *
 * ---------------------------------------------------------------------
 * EL PROBLEMA DE LAS VALORACIONES
 * ---------------------------------------------------------------------
 * RetroCloud no tiene estrellas ni "me gusta / no me gusta". Nadie
 * puntua los juegos del 1 al 5, asi que no hay valoraciones explicitas
 * con las que trabajar.
 *
 * La solucion es la misma que usa la industria: deducir el gusto del
 * comportamiento. Son las llamadas VALORACIONES IMPLICITAS. Aqui se
 * combinan tres señales que la plataforma ya registra:
 *
 *   favoritos        · el usuario lo marco a mano  -> señal fuerte
 *   save states      · invirtio esfuerzo y quiere volver -> muy fuerte
 *   tiempo jugado    · señal continua, pero ruidosa
 *
 * El tiempo se pasa por un logaritmo a proposito. Sin el, alguien que
 * dejo un juego abierto diez horas aplastaria cualquier otra señal, y
 * la diferencia real entre jugar 10 y 20 minutos importa mucho mas que
 * entre 10 y 20 horas.
 *
 * ---------------------------------------------------------------------
 * LOS DOS MOTORES
 * ---------------------------------------------------------------------
 * 1. CONTENIDO · mira las caracteristicas de los juegos (consola,
 *    genero, decada, estudio) y construye un perfil de gustos. Funciona
 *    con un solo usuario en el sistema.
 *
 * 2. COLABORATIVO · ignora por completo de que va cada juego y mira
 *    solo quien juega a que. Si dos juegos los juega la misma gente,
 *    se parecen. Encuentra relaciones que el contenido no ve, pero
 *    necesita varios usuarios para decir algo util.
 *
 * Se mezclan con un peso que NO es fijo: se calcula a partir de cuantos
 * datos colaborativos hay de verdad. Con un catalogo recien estrenado
 * manda el contenido; segun entra gente, el colaborativo va pesando
 * mas. Esto es el "arranque en frio", el problema clasico de cualquier
 * recomendador, y aqui se resuelve de forma explicita en lugar de
 * fingir que no existe.
 */

declare(strict_types=1);

namespace RetroCloud;

final class Recomendador
{
    // -----------------------------------------------------------------
    // Pesos de las señales implicitas
    //
    // No salen de ningun sitio magico: son una escala de intencion.
    // Guardar una partida exige mas compromiso que dejar correr el
    // reloj, y marcar un favorito es una declaracion deliberada.
    // -----------------------------------------------------------------
    private const PESO_FAVORITO = 5.0;
    private const PESO_SAVE     = 3.0;
    private const PESO_TIEMPO   = 1.6;

    // -----------------------------------------------------------------
    // Importancia de cada caracteristica en el perfil de gustos.
    //
    // El genero manda sobre la consola a proposito: quien juega a
    // plataformas suele querer mas plataformas, aunque sea de otra
    // maquina. La consola pesa casi igual porque en emulacion la
    // familiaridad con los controles importa de verdad.
    // -----------------------------------------------------------------
    private const PESO_GENERO        = 0.34;
    private const PESO_CONSOLA       = 0.30;
    private const PESO_DESARROLLADOR = 0.21;
    private const PESO_DECADA        = 0.15;

    /** Penalizacion por repetir consola dentro de la misma lista. */
    private const CASTIGO_DIVERSIDAD = 0.82;

    private function __construct() {}

    // =================================================================
    // ENTRADA PRINCIPAL
    // =================================================================

    /**
     * Devuelve los juegos recomendados para un usuario.
     *
     * @return array{
     *   recomendaciones: list<array<string,mixed>>,
     *   diagnostico: array<string,mixed>
     * }
     */
    public static function para(?int $usuarioId, int $limite = 6): array
    {
        $catalogo = self::catalogo();

        if ($catalogo === []) {
            return [
                'recomendaciones' => [],
                'diagnostico' => [
                    'estrategia' => 'catalogo-vacio',
                    'mensaje'    => 'Todavia no hay juegos publicados que recomendar.',
                ],
            ];
        }

        /* Matriz usuario-juego con las afinidades implicitas de TODO el
           sistema. Es la materia prima de los dos motores. */
        $matriz = self::matrizAfinidad();
        $mias   = ($usuarioId !== null && isset($matriz[$usuarioId]))
            ? $matriz[$usuarioId]
            : [];

        // --- Arranque en frio ----------------------------------------
        // Sin historial no hay nada que aprender. Antes que inventarse
        // un gusto, se admite y se tira de popularidad.
        if ($mias === []) {
            return self::porPopularidad($catalogo, $limite, $usuarioId === null);
        }

        // --- Motor 1 · contenido -------------------------------------
        $perfil = self::perfilDeGustos($mias, $catalogo);

        // --- Motor 2 · colaborativo ----------------------------------
        $similitudes = self::similitudEntreJuegos($matriz);
        $confianza   = self::confianzaColaborativa($matriz, $mias, $similitudes);

        // --- Puntuacion de candidatos --------------------------------
        $candidatos = [];
        foreach ($catalogo as $id => $juego) {
            if (isset($mias[$id])) {
                continue;   // ya lo jugo: no se recomienda
            }

            $contenido    = self::puntuarPorContenido($juego, $perfil);
            $colaborativo = self::puntuarPorColaboracion($id, $mias, $similitudes);

            /* La mezcla adaptativa. Con poca gente en el sistema,
               $confianza tiende a 0 y manda el contenido. */
            $puntuacion = $confianza * $colaborativo
                        + (1 - $confianza) * $contenido;

            if ($puntuacion <= 0.0001) {
                continue;
            }

            $candidatos[$id] = [
                'juego'        => $juego,
                'puntuacion'   => $puntuacion,
                'contenido'    => $contenido,
                'colaborativo' => $colaborativo,
            ];
        }

        if ($candidatos === []) {
            return self::porPopularidad($catalogo, $limite, false, $mias);
        }

        $elegidos = self::ordenarConDiversidad($candidatos, $limite);

        // --- Explicacion ---------------------------------------------
        $salida = [];
        foreach ($elegidos as $c) {
            $salida[] = self::formatear(
                $c['juego'],
                $c['puntuacion'],
                self::explicar($c, $perfil, $mias, $catalogo, $confianza)
            );
        }

        return [
            'recomendaciones' => $salida,
            'diagnostico' => [
                'estrategia'          => 'hibrido',
                'juegos_analizados'   => count($catalogo),
                'juegos_con_historial'=> count($mias),
                'usuarios_en_modelo'  => count($matriz),
                'peso_colaborativo'   => round($confianza, 3),
                'peso_contenido'      => round(1 - $confianza, 3),
                'perfil'              => self::perfilLegible($perfil, $catalogo),
            ],
        ];
    }

    // =================================================================
    // DATOS
    // =================================================================

    /**
     * Catalogo publicado, indexado por id, con las caracteristicas que
     * usa el motor de contenido.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function catalogo(): array
    {
        $filas = Database::filas(
            'SELECT j.id, j.titulo, j.slug, j.portada, j.anio, j.desarrollador,
                    j.jugadores, j.veces_jugado, j.consola_id, j.genero_id,
                    c.nombre AS consola, c.slug AS consola_slug,
                    g.nombre AS genero
               FROM juegos j
               JOIN consolas c ON c.id = j.consola_id
          LEFT JOIN generos  g ON g.id = j.genero_id
              WHERE j.publicado = 1'
        );

        $catalogo = [];
        foreach ($filas as $f) {
            $id = (int) $f['id'];
            $anio = $f['anio'] !== null ? (int) $f['anio'] : null;

            $catalogo[$id] = [
                'id'            => $id,
                'titulo'        => (string) $f['titulo'],
                'slug'          => (string) $f['slug'],
                'portada'       => $f['portada'],
                'anio'          => $anio,
                'consola'       => (string) $f['consola'],
                'consola_id'    => (int) $f['consola_id'],
                'genero'        => $f['genero'] !== null ? (string) $f['genero'] : null,
                'genero_id'     => $f['genero_id'] !== null ? (int) $f['genero_id'] : null,
                'desarrollador' => $f['desarrollador'] !== null
                    ? mb_strtolower(trim((string) $f['desarrollador']))
                    : null,
                'desarrollador_original' => $f['desarrollador'],
                'decada'        => $anio !== null ? intdiv($anio, 10) * 10 : null,
                'jugadores'     => (int) $f['jugadores'],
                'veces_jugado'  => (int) $f['veces_jugado'],
            ];
        }

        return $catalogo;
    }

    /**
     * Matriz de afinidad usuario -> juego -> valor en [0, 1].
     *
     * Las tres señales se recogen en una sola consulta con UNION ALL y
     * se funden despues. Hacerlo en una consulta evita el problema
     * clasico de N+1 (una consulta por usuario) que haria esto
     * inusable en cuanto hubiera gente de verdad.
     *
     * @return array<int, array<int, float>>
     */
    private static function matrizAfinidad(): array
    {
        $filas = Database::filas(
            'SELECT t.usuario_id, t.juego_id,
                    MAX(t.segundos) AS segundos,
                    MAX(t.saves)    AS saves,
                    MAX(t.fav)      AS fav
               FROM (
                    SELECT usuario_id, juego_id,
                           SUM(segundos_jugados) AS segundos, 0 AS saves, 0 AS fav
                      FROM sesiones_juego
                     GROUP BY usuario_id, juego_id

                    UNION ALL

                    SELECT usuario_id, juego_id,
                           0, COUNT(*), 0
                      FROM save_states
                     GROUP BY usuario_id, juego_id

                    UNION ALL

                    SELECT usuario_id, juego_id, 0, 0, 1
                      FROM favoritos
               ) AS t
              GROUP BY t.usuario_id, t.juego_id'
        );

        $matriz = [];
        foreach ($filas as $f) {
            $u = (int) $f['usuario_id'];
            $j = (int) $f['juego_id'];

            $minutos = ((int) $f['segundos']) / 60.0;

            /* log1p aplasta la escala: 10 min -> 2.4, 60 min -> 4.1,
               600 min -> 6.4. Una sesion maratoniana pesa mas, pero no
               diez veces mas. */
            $valor = self::PESO_TIEMPO   * log(1 + $minutos)
                   + self::PESO_SAVE     * (int) $f['saves']
                   + self::PESO_FAVORITO * (int) $f['fav'];

            if ($valor > 0) {
                $matriz[$u][$j] = $valor;
            }
        }

        /* Normalizar por usuario: lo que importa es el gusto RELATIVO de
           cada persona, no cuanto juega en terminos absolutos. Sin esto,
           quien mas horas acumula dominaria el modelo entero. */
        foreach ($matriz as $u => $juegos) {
            $max = max($juegos);
            if ($max <= 0) {
                continue;
            }
            foreach ($juegos as $j => $v) {
                $matriz[$u][$j] = $v / $max;
            }
        }

        return $matriz;
    }

    // =================================================================
    // MOTOR 1 · CONTENIDO
    // =================================================================

    /**
     * Construye el perfil de gustos sumando las caracteristicas de lo
     * que el usuario ya jugo, ponderadas por cuanto le gusto.
     *
     * Cada dimension se normaliza por separado para que una con muchos
     * valores posibles (desarrollador) no aplaste a otra con pocos
     * (decada).
     *
     * @param array<int,float> $mias
     * @param array<int,array<string,mixed>> $catalogo
     * @return array<string, array<string,float>>
     */
    private static function perfilDeGustos(array $mias, array $catalogo): array
    {
        $perfil = ['consola' => [], 'genero' => [], 'desarrollador' => [], 'decada' => []];

        foreach ($mias as $juegoId => $afinidad) {
            $j = $catalogo[$juegoId] ?? null;
            if ($j === null) {
                continue;   // juego despublicado: el historial sigue, el juego no
            }

            $perfil['consola'][(string) $j['consola_id']] =
                ($perfil['consola'][(string) $j['consola_id']] ?? 0) + $afinidad;

            if ($j['genero_id'] !== null) {
                $perfil['genero'][(string) $j['genero_id']] =
                    ($perfil['genero'][(string) $j['genero_id']] ?? 0) + $afinidad;
            }
            if ($j['desarrollador'] !== null) {
                $perfil['desarrollador'][$j['desarrollador']] =
                    ($perfil['desarrollador'][$j['desarrollador']] ?? 0) + $afinidad;
            }
            if ($j['decada'] !== null) {
                $perfil['decada'][(string) $j['decada']] =
                    ($perfil['decada'][(string) $j['decada']] ?? 0) + $afinidad;
            }
        }

        foreach ($perfil as $dimension => $valores) {
            if ($valores === []) {
                continue;
            }
            $max = max($valores);
            if ($max <= 0) {
                continue;
            }
            foreach ($valores as $k => $v) {
                $perfil[$dimension][$k] = $v / $max;
            }
        }

        return $perfil;
    }

    /**
     * Puntua un juego contra el perfil. El resultado cae en [0, 1].
     *
     * @param array<string,mixed> $juego
     * @param array<string,array<string,float>> $perfil
     */
    private static function puntuarPorContenido(array $juego, array $perfil): float
    {
        $p = 0.0;

        $p += self::PESO_CONSOLA
            * ($perfil['consola'][(string) $juego['consola_id']] ?? 0.0);

        if ($juego['genero_id'] !== null) {
            $p += self::PESO_GENERO
                * ($perfil['genero'][(string) $juego['genero_id']] ?? 0.0);
        }
        if ($juego['desarrollador'] !== null) {
            $p += self::PESO_DESARROLLADOR
                * ($perfil['desarrollador'][$juego['desarrollador']] ?? 0.0);
        }
        if ($juego['decada'] !== null) {
            /* La decada no es categorica: 1992 y 1995 se parecen. Una
               decada vecina cuenta a medias. */
            $d = (int) $juego['decada'];
            $mejor = 0.0;
            foreach ($perfil['decada'] as $dec => $valor) {
                $distancia = abs(((int) $dec) - $d) / 10;
                $cercania  = $distancia === 0 ? 1.0 : ($distancia === 1 ? 0.5 : 0.0);
                $mejor = max($mejor, $valor * $cercania);
            }
            $p += self::PESO_DECADA * $mejor;
        }

        return $p;
    }

    // =================================================================
    // MOTOR 2 · COLABORATIVO (item-item)
    // =================================================================

    /**
     * Similitud del coseno entre cada par de juegos, calculada sobre la
     * columna de cada juego en la matriz usuario-juego.
     *
     * Aqui el algoritmo no sabe ni el titulo ni el genero de nada: solo
     * ve numeros. Dos juegos se parecen si les gustan a la misma gente
     * con la misma intensidad. Asi se descubren relaciones que ninguna
     * etiqueta recoge.
     *
     * Se usa la version item-item y no user-user porque es la estandar
     * en produccion: los gustos de una persona cambian a diario, pero
     * la relacion entre dos juegos es mucho mas estable.
     *
     * @param array<int,array<int,float>> $matriz
     * @return array<int, array<int, float>>
     */
    private static function similitudEntreJuegos(array $matriz): array
    {
        // Transponer: juego -> usuario -> afinidad
        $porJuego = [];
        foreach ($matriz as $u => $juegos) {
            foreach ($juegos as $j => $v) {
                $porJuego[$j][$u] = $v;
            }
        }

        // Norma de cada vector columna
        $normas = [];
        foreach ($porJuego as $j => $vec) {
            $suma = 0.0;
            foreach ($vec as $v) {
                $suma += $v * $v;
            }
            $normas[$j] = sqrt($suma);
        }

        $sim = [];
        $ids = array_keys($porJuego);
        $n = count($ids);

        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                $ja = $ids[$a];
                $jb = $ids[$b];

                if ($normas[$ja] <= 0 || $normas[$jb] <= 0) {
                    continue;
                }

                // Producto escalar solo sobre los usuarios comunes
                $punto = 0.0;
                $comunes = 0;
                foreach ($porJuego[$ja] as $u => $v) {
                    if (isset($porJuego[$jb][$u])) {
                        $punto += $v * $porJuego[$jb][$u];
                        $comunes++;
                    }
                }

                if ($punto <= 0) {
                    continue;
                }

                $coseno = $punto / ($normas[$ja] * $normas[$jb]);

                /* Correccion por poca evidencia. Dos juegos que comparten
                   UN solo usuario dan coseno 1.0, que es matematicamente
                   cierto y practicamente mentira. Este factor hunde esas
                   similitudes hasta que haya varios usuarios detras. */
                $coseno *= $comunes / ($comunes + 2.0);

                $sim[$ja][$jb] = $coseno;
                $sim[$jb][$ja] = $coseno;
            }
        }

        return $sim;
    }

    /**
     * Media de similitudes ponderada por la afinidad del usuario:
     * cuanto se parece este juego a lo que ya le gusta.
     *
     * @param array<int,float> $mias
     * @param array<int,array<int,float>> $similitudes
     */
    private static function puntuarPorColaboracion(int $juegoId, array $mias, array $similitudes): float
    {
        $numerador = 0.0;
        $denominador = 0.0;

        foreach ($mias as $jugado => $afinidad) {
            $s = $similitudes[$juegoId][$jugado] ?? 0.0;
            if ($s <= 0) {
                continue;
            }
            $numerador   += $s * $afinidad;
            $denominador += $s;
        }

        return $denominador > 0 ? $numerador / $denominador : 0.0;
    }

    /**
     * Cuanto fiarse del motor colaborativo, en [0, 0.75].
     *
     * Depende de tres cosas: cuanta gente hay en el modelo, cuantos
     * juegos tiene el usuario en su historial, y si de verdad se
     * encontraron similitudes utiles. El tope es 0.75 a proposito: el
     * contenido nunca se apaga del todo, porque es lo unico que sabe
     * recomendar un juego recien subido que nadie ha tocado.
     *
     * @param array<int,array<int,float>> $matriz
     * @param array<int,float> $mias
     * @param array<int,array<int,float>> $similitudes
     */
    private static function confianzaColaborativa(array $matriz, array $mias, array $similitudes): float
    {
        $usuarios = count($matriz);
        if ($usuarios < 2 || $similitudes === []) {
            return 0.0;
        }

        // Cuantos de los juegos del usuario tienen alguna similitud util
        $conVecinos = 0;
        foreach (array_keys($mias) as $j) {
            if (!empty($similitudes[$j])) {
                $conVecinos++;
            }
        }
        if ($conVecinos === 0) {
            return 0.0;
        }

        $porUsuarios = min(1.0, ($usuarios - 1) / 8.0);
        $porHistoria = min(1.0, $conVecinos / 4.0);

        return 0.75 * $porUsuarios * $porHistoria;
    }

    // =================================================================
    // SELECCION
    // =================================================================

    /**
     * Ordena por puntuacion pero castiga repetir consola.
     *
     * Un recomendador que solo maximiza la puntuacion acaba enseñando
     * seis juegos de la misma maquina: tecnicamente optimo e inutil en
     * la practica. Se elige de uno en uno, bajando la nota de lo que se
     * parece a lo ya elegido.
     *
     * @param array<int,array<string,mixed>> $candidatos
     * @return list<array<string,mixed>>
     */
    private static function ordenarConDiversidad(array $candidatos, int $limite): array
    {
        $elegidos = [];
        $vecesConsola = [];

        while (count($elegidos) < $limite && $candidatos !== []) {
            $mejorId = null;
            $mejorNota = -1.0;

            foreach ($candidatos as $id => $c) {
                $consola = $c['juego']['consola_id'];
                $repeticiones = $vecesConsola[$consola] ?? 0;
                $nota = $c['puntuacion'] * (self::CASTIGO_DIVERSIDAD ** $repeticiones);

                if ($nota > $mejorNota) {
                    $mejorNota = $nota;
                    $mejorId = $id;
                }
            }

            if ($mejorId === null) {
                break;
            }

            $elegidos[] = $candidatos[$mejorId];
            $consola = $candidatos[$mejorId]['juego']['consola_id'];
            $vecesConsola[$consola] = ($vecesConsola[$consola] ?? 0) + 1;
            unset($candidatos[$mejorId]);
        }

        return $elegidos;
    }

    // =================================================================
    // EXPLICABILIDAD
    // =================================================================

    /**
     * Genera el "por que" de una recomendacion.
     *
     * Esta parte no mejora la precision ni una decima: existe porque un
     * recomendador que no sabe justificarse es una caja negra, y una
     * caja negra no se puede depurar ni defender. Si la sugerencia es
     * mala, la explicacion dice exactamente que señal fallo.
     *
     * @param array<string,mixed> $c
     * @param array<string,array<string,float>> $perfil
     * @param array<int,float> $mias
     * @param array<int,array<string,mixed>> $catalogo
     */
    private static function explicar(
        array $c,
        array $perfil,
        array $mias,
        array $catalogo,
        float $confianza
    ): string {
        $j = $c['juego'];
        $razones = [];

        // Aportacion de cada caracteristica, para quedarnos con la mayor
        $aportes = [
            'consola' => self::PESO_CONSOLA
                * ($perfil['consola'][(string) $j['consola_id']] ?? 0.0),
            'genero' => $j['genero_id'] !== null
                ? self::PESO_GENERO * ($perfil['genero'][(string) $j['genero_id']] ?? 0.0)
                : 0.0,
            'desarrollador' => $j['desarrollador'] !== null
                ? self::PESO_DESARROLLADOR * ($perfil['desarrollador'][$j['desarrollador']] ?? 0.0)
                : 0.0,
        ];
        arsort($aportes);

        foreach ($aportes as $clave => $valor) {
            if ($valor < 0.05) {
                continue;
            }
            $razones[] = match ($clave) {
                'consola'       => 'juegas bastante a ' . $j['consola'],
                'genero'        => 'te gustan los juegos de ' . mb_strtolower((string) $j['genero']),
                'desarrollador' => 'ya jugaste otros de ' . $j['desarrollador_original'],
                default         => null,
            };
            if (count($razones) >= 2) {
                break;
            }
        }

        /* Si el motor colaborativo fue quien lo empujo, es mas honesto
           decirlo asi: no es que el juego se parezca, es que lo juega
           gente parecida. */
        if ($confianza > 0.2 && $c['colaborativo'] > $c['contenido']) {
            $razones[] = 'lo juega gente con gustos parecidos a los tuyos';
        }

        if ($razones === []) {
            return 'Encaja con tu historial, aunque por poco margen.';
        }

        $texto = count($razones) === 1
            ? $razones[0]
            : implode(' y ', array_slice($razones, 0, 2));

        return 'Porque ' . $texto . '.';
    }

    /**
     * Traduce el perfil interno a algo legible, para el panel que
     * enseña como razono el sistema.
     *
     * @param array<string,array<string,float>> $perfil
     * @param array<int,array<string,mixed>> $catalogo
     * @return array<string, list<array{nombre:string, peso:float}>>
     */
    private static function perfilLegible(array $perfil, array $catalogo): array
    {
        $nombresConsola = [];
        $nombresGenero  = [];
        foreach ($catalogo as $j) {
            $nombresConsola[(string) $j['consola_id']] = $j['consola'];
            if ($j['genero_id'] !== null) {
                $nombresGenero[(string) $j['genero_id']] = $j['genero'];
            }
        }

        $traducir = static function (array $valores, array $nombres): array {
            arsort($valores);
            $salida = [];
            foreach (array_slice($valores, 0, 3, true) as $clave => $peso) {
                $salida[] = [
                    'nombre' => $nombres[$clave] ?? (string) $clave,
                    'peso'   => round($peso, 3),
                ];
            }
            return $salida;
        };

        return [
            'consolas' => $traducir($perfil['consola'], $nombresConsola),
            'generos'  => $traducir($perfil['genero'], $nombresGenero),
            'decadas'  => $traducir($perfil['decada'], []),
        ];
    }

    // =================================================================
    // ARRANQUE EN FRIO
    // =================================================================

    /**
     * Sin historial no hay nada que aprender. Se recurre a lo mas
     * jugado, repartido entre consolas para que la primera pantalla no
     * sea monotema.
     *
     * Decir "estrategia: popularidad" en lugar de disfrazarlo de
     * recomendacion personalizada es parte del diseño: el sistema
     * admite cuando no sabe.
     *
     * @param array<int,array<string,mixed>> $catalogo
     * @param array<int,float> $excluir
     * @return array{recomendaciones: list<array<string,mixed>>, diagnostico: array<string,mixed>}
     */
    private static function porPopularidad(
        array $catalogo,
        int $limite,
        bool $anonimo,
        array $excluir = []
    ): array {
        $candidatos = [];
        foreach ($catalogo as $id => $j) {
            if (isset($excluir[$id])) {
                continue;
            }
            $candidatos[$id] = [
                'juego'        => $j,
                'puntuacion'   => log(1 + $j['veces_jugado']),
                'contenido'    => 0.0,
                'colaborativo' => 0.0,
            ];
        }

        $elegidos = self::ordenarConDiversidad($candidatos, $limite);

        $salida = [];
        foreach ($elegidos as $c) {
            $salida[] = self::formatear(
                $c['juego'],
                $c['puntuacion'],
                $c['juego']['veces_jugado'] > 0
                    ? 'Uno de los mas jugados de la plataforma.'
                    : 'Recien llegado al catalogo.'
            );
        }

        return [
            'recomendaciones' => $salida,
            'diagnostico' => [
                'estrategia' => 'popularidad',
                'motivo' => $anonimo
                    ? 'Sin sesion iniciada no hay historial que analizar.'
                    : 'Todavia no has jugado nada: el modelo no tiene datos tuyos.',
                'juegos_analizados' => count($catalogo),
            ],
        ];
    }

    // =================================================================
    // Utilidades
    // =================================================================

    /** @param array<string,mixed> $juego */
    private static function formatear(array $juego, float $puntuacion, string $porque): array
    {
        return [
            'id'      => $juego['id'],
            'titulo'  => $juego['titulo'],
            'slug'    => $juego['slug'],
            'portada' => $juego['portada'],
            'consola' => $juego['consola'],
            'genero'  => $juego['genero'],
            'anio'    => $juego['anio'],
            'afinidad' => (int) round(min(1.0, $puntuacion) * 100),
            'porque'  => $porque,
        ];
    }
}
