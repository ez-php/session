<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Contracts\DatabaseInterface;
use PDO;
use Throwable;

/**
 * Minimal DatabaseInterface implementation backed by a raw PDO connection.
 *
 * Used in DatabaseSessionHandler tests to avoid depending on
 * EzPhp\Database\Database from ez-php/framework.
 *
 * Named `SessionPdoDatabase`, not `PdoDatabase`, deliberately: every package
 * shares the `Tests\` namespace and the root phpunit.xml loads them all in
 * one process, so a duplicate class name (`ez-php/orm` already has its own
 * `Tests\PdoDatabase`) is a fatal error, not a test failure.
 *
 * @package Tests
 */
final class SessionPdoDatabase implements DatabaseInterface
{
    private PDO $pdo;

    /**
     * @param string $dsn
     */
    public function __construct(string $dsn)
    {
        $this->pdo = new PDO($dsn);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * @param string                   $sql
     * @param array<int|string, mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo->prepare($sql);

        foreach ($bindings as $index => $value) {
            $paramIndex = is_int($index) ? $index + 1 : $index;
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($paramIndex, $value, $paramType);
        }

        $stmt->execute();

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * @param string                   $sql
     * @param array<int|string, mixed> $bindings
     *
     * @return int
     */
    public function execute(string $sql, array $bindings = []): int
    {
        $stmt = $this->pdo->prepare($sql);

        foreach ($bindings as $index => $value) {
            $paramIndex = is_int($index) ? $index + 1 : $index;
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($paramIndex, $value, $paramType);
        }

        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     * @throws Throwable
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return PDO
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
