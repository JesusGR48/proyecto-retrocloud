<?php
/**
 * RetroCloud · Capa de acceso a datos
 * ---------------------------------------------------------------------
 * Envoltorio delgado sobre PDO. Dos decisiones de diseno importantes:
 *
 *  1) Conexion unica (patron Singleton). Abrir una conexion por consulta
 *     es el error de rendimiento mas comun en PHP; aqui se abre una vez
 *     por peticion y se reutiliza.
 *
 *  2) TODA consulta pasa por sentencias preparadas. Los valores viajan
 *     separados del SQL, asi que el motor nunca los interpreta como
 *     codigo: eso es lo que vuelve imposible la inyeccion SQL. No existe
 *     en esta clase ningun metodo que concatene datos del usuario al SQL.
 */

declare(strict_types=1);

namespace RetroCloud;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class Database
{
    private static ?PDO $conexion = null;

    /** Clase de utilidad: no se instancia. */
    private function __construct() {}

    /**
     * Devuelve la conexion PDO, creandola la primera vez.
     */
    public static function conexion(): PDO
    {
        if (self::$conexion instanceof PDO) {
            return self::$conexion;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PUERTO,
            DB_NOMBRE,
            DB_CHARSET
        );

        try {
            self::$conexion = new PDO($dsn, DB_USUARIO, DB_PASSWORD, [
                // Los errores de SQL lanzan excepcion en lugar de pasar callados.
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Arreglos asociativos por defecto.
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // false = sentencias preparadas REALES del servidor MySQL,
                // no la emulacion de PDO. Mas seguro y permite reutilizarlas.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_INIT_COMMAND =>
                    "SET sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
            ]);
        } catch (PDOException $e) {
            // No propagamos el mensaje crudo: podria exponer usuario/host.
            error_log('Fallo de conexion a MySQL: ' . $e->getMessage());
            throw new RuntimeException(
                'No se pudo conectar a la base de datos. '
                . 'Verifica que MySQL este encendido en MAMP y que las '
                . 'credenciales de src/config.php sean correctas.'
            );
        }

        return self::$conexion;
    }

    /**
     * Ejecuta una consulta preparada y devuelve el statement.
     *
     * @param array<string|int, mixed> $parametros
     */
    public static function ejecutar(string $sql, array $parametros = []): PDOStatement
    {
        $stmt = self::conexion()->prepare($sql);
        $stmt->execute($parametros);
        return $stmt;
    }

    /**
     * Primera fila o null.
     *
     * @param array<string|int, mixed> $parametros
     * @return array<string, mixed>|null
     */
    public static function fila(string $sql, array $parametros = []): ?array
    {
        $fila = self::ejecutar($sql, $parametros)->fetch();
        return $fila === false ? null : $fila;
    }

    /**
     * Todas las filas.
     *
     * @param array<string|int, mixed> $parametros
     * @return list<array<string, mixed>>
     */
    public static function filas(string $sql, array $parametros = []): array
    {
        /** @var list<array<string, mixed>> $r */
        $r = self::ejecutar($sql, $parametros)->fetchAll();
        return $r;
    }

    /**
     * Primer valor de la primera fila (COUNT, SUM, MAX...).
     *
     * @param array<string|int, mixed> $parametros
     */
    public static function valor(string $sql, array $parametros = []): mixed
    {
        $v = self::ejecutar($sql, $parametros)->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * INSERT que devuelve el id generado.
     *
     * @param array<string|int, mixed> $parametros
     */
    public static function insertar(string $sql, array $parametros = []): int
    {
        self::ejecutar($sql, $parametros);
        return (int) self::conexion()->lastInsertId();
    }

    /**
     * UPDATE/DELETE que devuelve el numero de filas afectadas.
     *
     * @param array<string|int, mixed> $parametros
     */
    public static function afectar(string $sql, array $parametros = []): int
    {
        return self::ejecutar($sql, $parametros)->rowCount();
    }

    /**
     * Envuelve varias operaciones en una transaccion: si algo falla,
     * no queda nada a medias (atomicidad).
     *
     * @template T
     * @param callable():T $operacion
     * @return T
     */
    public static function transaccion(callable $operacion): mixed
    {
        $pdo = self::conexion();
        $pdo->beginTransaction();
        try {
            $resultado = $operacion();
            $pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Comprueba la conexion sin lanzar excepcion (para el diagnostico). */
    public static function disponible(): bool
    {
        try {
            self::conexion()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
