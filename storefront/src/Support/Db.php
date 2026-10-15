<?php

declare(strict_types=1);

namespace Storefront\Support;

use PDO;
use PDOStatement;

/**
 * The one MySQL connection, and the few helpers the storefront needs.
 *
 * Every statement is prepared; nothing is ever interpolated into SQL.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(?PDO $pdo = null): PDO
    {
        if ($pdo !== null) {
            return self::$pdo = $pdo;
        }

        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $socket = Env::get('DB_SOCKET');
        $dsn = $socket !== null && $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, Env::get('DB_NAME', 'storefront'))
            : sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::int('DB_PORT', 3306),
                Env::get('DB_NAME', 'storefront'),
            );

        self::$pdo = new PDO($dsn, Env::get('DB_USER', 'root'), Env::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // Every timestamp the storefront writes is UTC.
        self::$pdo->exec("SET time_zone = '+00:00'");

        return self::$pdo;
    }

    /**
     * @param  array<int|string, mixed>  $params
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $statement = self::connect()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param  array<int|string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * @param  array<int|string, mixed>  $params
     * @return array<string, mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param  array<int|string, mixed>  $params
     */
    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param  array<int|string, mixed>  $params
     */
    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::connect()->lastInsertId();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connect();
        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
