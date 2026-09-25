<?php

declare(strict_types=1);

namespace EzPhp\Session\Driver;

use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Session\SessionException;
use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Class DatabaseSessionHandler
 *
 * PDO-backed session driver via `ez-php/contracts`' `DatabaseInterface`.
 * Sessions are stored in a `sessions` table, created automatically
 * (`CREATE TABLE IF NOT EXISTS`) with driver-aware DDL for MySQL and SQLite.
 *
 * @package EzPhp\Session\Driver
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /**
     * DatabaseSessionHandler Constructor
     *
     * @param DatabaseInterface $database
     * @param string            $table Session table; interpolated into SQL, so limited to `[A-Za-z0-9_]`.
     *
     * @throws SessionException When the table name contains anything but letters, digits or underscores.
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly string $table = 'sessions',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new SessionException("Invalid session table name '{$table}': only [A-Za-z0-9_] are allowed.");
        }

        $this->createTableIfNeeded();
    }

    /**
     * @param string $path
     * @param string $name
     *
     * @return bool
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * @param string $id
     *
     * @return string
     */
    public function read(string $id): string
    {
        $rows = $this->database->query(
            "SELECT payload FROM {$this->table} WHERE id = :id",
            ['id' => $id],
        );

        if ($rows === []) {
            return '';
        }

        $payload = $rows[0]['payload'] ?? '';

        return is_string($payload) ? $payload : '';
    }

    /**
     * @param string $id
     * @param string $data
     *
     * @return bool
     */
    public function write(string $id, string $data): bool
    {
        $now = time();

        $updated = $this->database->execute(
            "UPDATE {$this->table} SET payload = :payload, last_activity = :now WHERE id = :id",
            ['payload' => $data, 'now' => $now, 'id' => $id],
        );

        if ($updated === 0) {
            $this->database->execute(
                "INSERT INTO {$this->table} (id, payload, last_activity) VALUES (:id, :payload, :now)",
                ['id' => $id, 'payload' => $data, 'now' => $now],
            );
        }

        return true;
    }

    /**
     * @param string $id
     *
     * @return bool
     */
    public function destroy(string $id): bool
    {
        $this->database->execute("DELETE FROM {$this->table} WHERE id = :id", ['id' => $id]);

        return true;
    }

    /**
     * Whether a session with this id exists in the store.
     *
     * Called by PHP under `session.use_strict_mode`: returning false for an
     * unknown id makes PHP issue a fresh id instead of adopting one the client
     * chose (session fixation).
     *
     * @param string $id
     *
     * @return bool
     */
    public function validateId(string $id): bool
    {
        return $this->database->query(
            "SELECT 1 AS present FROM {$this->table} WHERE id = :id",
            ['id' => $id],
        ) !== [];
    }

    /**
     * Refresh an unchanged session's activity timestamp (lazy_write path).
     *
     * @param string $id
     * @param string $data
     *
     * @return bool
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    /**
     * @param int $max_lifetime
     *
     * @return int
     */
    public function gc(int $max_lifetime): int
    {
        return $this->database->execute(
            "DELETE FROM {$this->table} WHERE last_activity < :threshold",
            ['threshold' => time() - $max_lifetime],
        );
    }

    /**
     * Create the sessions table if it does not already exist.
     *
     * Uses driver-aware DDL to support both MySQL and SQLite.
     *
     * @return void
     */
    private function createTableIfNeeded(): void
    {
        $driver = $this->database->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $this->database->getPdo()->exec(
                "CREATE TABLE IF NOT EXISTS {$this->table} (
                    id            TEXT    PRIMARY KEY,
                    payload       TEXT    NOT NULL,
                    last_activity INTEGER NOT NULL
                )"
            );

            return;
        }

        $this->database->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                id            VARCHAR(128) PRIMARY KEY,
                payload       LONGTEXT     NOT NULL,
                last_activity INT UNSIGNED NOT NULL,
                INDEX sessions_last_activity_idx (last_activity)
            )"
        );
    }
}
